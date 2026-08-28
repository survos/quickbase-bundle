<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Grist;

use Survos\Grist\Schema\GristColumnType;
use Survos\Grist\Service\GristDocument;
use Survos\Quickbase\Adapter\QuickbaseAdapter;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\RecordQuery;
use Survos\RecordStore\Model\TableReference;

/**
 * Moves the rows, in the order the destination requires.
 *
 * Three passes, because Grist row ids do not exist until the rows do:
 *
 *   1. values -- everything a row holds on its own, keyed by the Quickbase record id
 *   2. references -- Quickbase's parent record id translated to the Grist row id it became
 *   3. attachments -- bytes out of Quickbase and into Grist's attachment store
 *
 * Each pass upserts on QbRecordId, so all three are re-runnable and a run that dies half way
 * is resumed by running it again. That matters more here than usual: Grist commits every batch
 * as it arrives and has no transaction to roll back and no save button to withhold.
 */
final readonly class QuickbaseGristImporter
{
    /** Quickbase caps a query page at 1000 records but shrinks it further on wide tables. */
    public const int PAGE = 500;

    public function __construct(private QuickbaseClientInterface $quickbase)
    {
    }

    /**
     * Create the tables and columns. Additive: an existing document keeps everything it has.
     *
     * @return array{tables: list<string>, columns: array<string, list<string>>}
     */
    public function createSchema(ConversionPlan $plan, GristDocument $document): array
    {
        $created = $document->addTables($plan->definitions());

        // A document that already had some of these tables needs the missing columns adding to
        // them; addTables only ever creates whole tables.
        $columns = [];
        foreach ($plan->tables as $table) {
            $added = $document->addColumns($table->id, $table->definitions());
            if ([] !== $added) {
                $columns[$table->id] = $added;
            }
        }

        return ['tables' => $created, 'columns' => $columns];
    }

    /**
     * Pass 1: every column that holds its own value.
     *
     * @return array{records: int, batches: int}
     */
    public function importValues(
        ConversionPlan $plan,
        TablePlan $table,
        GristDocument $document,
        int $maxBytes = GristDocument::DEFAULT_MAX_BYTES,
        ?\Closure $progress = null,
    ): array {
        $columns = $table->scalars();

        return $document->upsert(
            $table->id,
            $this->rows($plan, $table, $columns, $progress),
            [$plan->keyColumn],
            $maxBytes,
        );
    }

    /**
     * Pass 2: turn Quickbase parent record ids into Grist row ids.
     *
     * The map is read back out of Grist rather than remembered from pass 1, which is what makes
     * this resumable on its own -- and correct when the target rows were written by an earlier
     * run rather than this one.
     *
     * @return array{records: int, batches: int, unresolved: int}
     */
    public function importReferences(
        ConversionPlan $plan,
        TablePlan $table,
        GristDocument $document,
        int $maxBytes = GristDocument::DEFAULT_MAX_BYTES,
        ?\Closure $progress = null,
    ): array {
        $columns = $table->references();
        if ([] === $columns) {
            return ['records' => 0, 'batches' => 0, 'unresolved' => 0];
        }

        $maps = [];
        foreach ($columns as $column) {
            $target = (string) $column->referencedTable;
            $maps[$target] ??= $document->rowIdsBy($target, $plan->keyColumn);
        }

        $unresolved = 0;
        $rows = (function () use ($plan, $table, $columns, $maps, &$unresolved, $progress): \Generator {
            foreach ($this->records($plan, $table, $columns, $progress) as $record) {
                $fields = [$plan->keyColumn => $record->id];
                $any = false;
                foreach ($columns as $column) {
                    $rid = $record->fields[(string) $column->id] ?? null;
                    if (null === $rid || '' === $rid || 0 === (int) $rid) {
                        continue;
                    }
                    $rowId = $maps[(string) $column->referencedTable][(string) $rid] ?? null;
                    if (null === $rowId) {
                        // The parent row is genuinely not there -- deleted in Quickbase, or its
                        // table was excluded. Counted, never guessed at.
                        ++$unresolved;
                        continue;
                    }
                    $fields[(string) $column->id] = $rowId;
                    $any = true;
                }
                if ($any) {
                    yield $fields;
                }
            }
        })();

        $result = $document->upsert($table->id, $rows, [$plan->keyColumn], $maxBytes);

        return $result + ['unresolved' => $unresolved];
    }

    /**
     * Pass 3: file attachments, one HTTP round trip out of Quickbase and one into Grist per file.
     *
     * @return array{files: int, records: int, batches: int, failed: int}
     */
    public function importAttachments(
        ConversionPlan $plan,
        TablePlan $table,
        GristDocument $document,
        int $maxBytes = GristDocument::DEFAULT_MAX_BYTES,
        ?\Closure $progress = null,
    ): array {
        $columns = $table->attachments();
        if ([] === $columns) {
            return ['files' => 0, 'records' => 0, 'batches' => 0, 'failed' => 0];
        }

        $files = $failed = 0;
        $rows = (function () use ($plan, $table, $columns, $document, &$files, &$failed, $progress): \Generator {
            foreach ($this->records($plan, $table, $columns, $progress) as $record) {
                $fields = [$plan->keyColumn => $record->id];
                $any = false;
                foreach ($columns as $column) {
                    $value = $record->fields[(string) $column->id] ?? null;
                    if (!is_array($value)) {
                        continue;
                    }
                    [$filename, $version] = self::fileVersion($value);
                    if (null === $filename) {
                        continue;
                    }
                    try {
                        $bytes = $this->quickbase->downloadFile($table->sourceId, (string) $record->id, $column->fieldId, $version);
                        $fields[(string) $column->id] = [GristColumnType::LIST_PREFIX, $document->uploadAttachment($filename, $bytes, self::mimeType($filename, $bytes))];
                        ++$files;
                        $any = true;
                    } catch (\Throwable) {
                        // One unreadable file must not abandon the rest of the table; the count
                        // is reported so the run is not mistaken for a complete one.
                        ++$failed;
                    }
                }
                if ($any) {
                    yield $fields;
                }
            }
        })();

        $result = $document->upsert($table->id, $rows, [$plan->keyColumn], $maxBytes);

        return ['files' => $files, 'failed' => $failed] + $result;
    }

    /**
     * @param list<ColumnPlan> $columns
     *
     * @return \Generator<array<string, mixed>>
     */
    private function rows(ConversionPlan $plan, TablePlan $table, array $columns, ?\Closure $progress): \Generator
    {
        foreach ($this->records($plan, $table, $columns, $progress) as $record) {
            $fields = [$plan->keyColumn => $record->id];
            foreach ($columns as $column) {
                if ($column->id === $plan->keyColumn) {
                    continue;
                }
                $value = GristColumnType::coerce((string) $column->gristType, $record->fields[(string) $column->id] ?? null);
                if (null !== $value) {
                    $fields[(string) $column->id] = $value;
                }
            }
            yield $fields;
        }
    }

    /**
     * Quickbase records, paged, with the fields already named by their Grist column ids.
     *
     * This is the record-store seam doing its job: the TableReference field map is what turns
     * Quickbase's numeric field ids into the destination's names, so nothing downstream has to
     * carry both.
     *
     * @param list<ColumnPlan> $columns
     *
     * @return \Generator<Record>
     */
    private function records(ConversionPlan $plan, TablePlan $table, array $columns, ?\Closure $progress): \Generator
    {
        $map = [$plan->keyColumn => QuickbaseToGristConverter::RECORD_ID];
        foreach ($columns as $column) {
            $map[(string) $column->id] = $column->fieldId;
        }

        $reference = new TableReference(
            $plan->sourceAppName,
            $plan->sourceAppId,
            'quickbase',
            $table->sourceName,
            $table->sourceId,
            $map,
        );
        $adapter = new QuickbaseAdapter($this->quickbase);

        $offset = 0;
        do {
            $page = $adapter->query($reference, new RecordQuery(select: array_keys($map), limit: self::PAGE, offset: $offset));
            foreach ($page->records as $record) {
                if (null === $record->id) {
                    // Without the Quickbase record id there is no key to upsert on, and writing
                    // it anyway would duplicate the row on the next run.
                    continue;
                }
                yield $record;
            }
            $progress?->call($this, count($page->records), $page->total);
            $offset = $page->nextOffset ?? null;
        } while (null !== $offset);
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array{string|null, int}
     */
    private static function fileVersion(array $value): array
    {
        $versions = is_array($value['versions'] ?? null) ? $value['versions'] : [];
        $best = null;
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }
            if (null === $best || (int) ($version['versionNumber'] ?? 0) > (int) ($best['versionNumber'] ?? 0)) {
                $best = $version;
            }
        }

        $filename = is_string($best['fileName'] ?? null) ? $best['fileName'] : null;

        return [$filename, (int) ($best['versionNumber'] ?? 0)];
    }

    private static function mimeType(string $filename, string $bytes): string
    {
        // No finfo_close(): deprecated in PHP 8.5, the object frees itself.
        if (class_exists(\finfo::class)) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (is_string($detected) && '' !== $detected) {
                return $detected;
            }
        }

        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
