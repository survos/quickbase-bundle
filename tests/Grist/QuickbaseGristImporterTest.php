<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests\Grist;

use PHPUnit\Framework\TestCase;
use Survos\Grist\Contract\GristClientInterface;
use Survos\Grist\Service\GristDocument;
use Survos\Quickbase\Adapter\QuickbaseAdapter;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\Grist\QuickbaseGristImporter;
use Survos\QuickbaseBundle\Grist\QuickbaseToGristConverter;
use Survos\RecordStore\Model\ApplicationReference;

/**
 * The three passes, against stubbed stores.
 *
 * Worth having as a unit test and not only a live run: the attachment pass only executes its
 * interesting branch when a record actually has a file, so an application whose file fields
 * happen to be empty exercises none of it.
 */
final class QuickbaseGristImporterTest extends TestCase
{
    public function testValuesAreWrittenInTheGristWireFormatKeyedByRecordId(): void
    {
        [$importer, $plan, $document, $sink] = $this->fixture();

        $result = $importer->importValues($plan, $plan->tables[1], $document);

        self::assertSame(['records' => 1, 'batches' => 1], $result);
        self::assertSame([
            'require' => ['QbRecordId' => 11],
            'fields' => [
                'QbRecordId' => 11,
                'Status' => 'Open',
                // A Date arrives as the epoch second of UTC midnight, never the source string.
                'Ordered_On' => 1615766400,
            ],
        ], $sink->rows['Orders'][0][0]);
    }

    /** Quickbase's parent record id is meaningless in Grist until it is mapped to a row id. */
    public function testReferencesAreTranslatedToGristRowIds(): void
    {
        [$importer, $plan, $document, $sink] = $this->fixture();

        $result = $importer->importReferences($plan, $plan->tables[1], $document);

        self::assertSame(0, $result['unresolved']);
        self::assertSame(
            ['require' => ['QbRecordId' => 11], 'fields' => ['QbRecordId' => 11, 'Related_Customer' => 4]],
            $sink->rows['Orders'][0][0],
        );
    }

    /** A parent row that is not in the document is counted, never guessed at. */
    public function testUnresolvableReferencesAreCountedAndLeftEmpty(): void
    {
        [$importer, $plan, $document, $sink] = $this->fixture(rowIds: []);

        $result = $importer->importReferences($plan, $plan->tables[1], $document);

        self::assertSame(1, $result['unresolved']);
        self::assertSame(0, $result['records']);
        self::assertSame([], $sink->rows);
    }

    public function testAttachmentsAreUploadedAndStoredAsAGristList(): void
    {
        [$importer, $plan, $document, $sink] = $this->fixture(withFile: true);

        $result = $importer->importAttachments($plan, $plan->tables[1], $document);

        self::assertSame(1, $result['files']);
        self::assertSame(0, $result['failed']);
        self::assertSame(
            ['require' => ['QbRecordId' => 11], 'fields' => ['QbRecordId' => 11, 'Invoice' => ['L', 99]]],
            $sink->rows['Orders'][0][0],
        );
    }

    /** One unreadable file must not abandon the rest of the table. */
    public function testAFailedAttachmentIsCountedRatherThanFatal(): void
    {
        [$importer, $plan, $document, ] = $this->fixture(withFile: true, downloadFails: true);

        $result = $importer->importAttachments($plan, $plan->tables[1], $document);

        self::assertSame(0, $result['files']);
        self::assertSame(1, $result['failed']);
    }

    /**
     * @param array<string, int>|null $rowIds
     *
     * @return array{QuickbaseGristImporter, \Survos\QuickbaseBundle\Grist\ConversionPlan, GristDocument, object{rows: array<string, list<list<array<string, mixed>>>>}}
     */
    private function fixture(?array $rowIds = null, bool $withFile = false, bool $downloadFails = false): array
    {
        $quickbase = $this->createStub(QuickbaseClientInterface::class);
        $quickbase->method('tables')->willReturn([
            ['id' => 'tbl-cust', 'name' => 'Customers'],
            ['id' => 'tbl-ord', 'name' => 'Orders'],
        ]);
        $quickbase->method('fields')->willReturnCallback(static fn (string $t): array => match ($t) {
            'tbl-cust' => [['id' => 3, 'label' => 'Record ID#', 'fieldType' => 'recordid', 'mode' => '']],
            'tbl-ord' => [
                ['id' => 3, 'label' => 'Record ID#', 'fieldType' => 'recordid', 'mode' => ''],
                ['id' => 6, 'label' => 'Related Customer', 'fieldType' => 'numeric', 'mode' => '', 'properties' => ['foreignKey' => true, 'masterTableId' => 'tbl-cust']],
                ['id' => 7, 'label' => 'Status', 'fieldType' => 'text-multiple-choice', 'mode' => '', 'properties' => ['choices' => ['Open']]],
                ['id' => 8, 'label' => 'Ordered On', 'fieldType' => 'date', 'mode' => ''],
                ['id' => 9, 'label' => 'Invoice', 'fieldType' => 'file', 'mode' => ''],
            ],
            default => [],
        });
        $quickbase->method('queryRecords')->willReturn([
            'data' => [[
                '3' => ['value' => 11],
                '6' => ['value' => 7],
                '7' => ['value' => 'Open'],
                '8' => ['value' => '2021-03-15'],
                '9' => ['value' => $withFile ? ['versions' => [['versionNumber' => 2, 'fileName' => 'invoice.pdf']]] : ''],
            ]],
            'metadata' => ['totalRecords' => 1],
        ]);
        $downloadFails
            ? $quickbase->method('downloadFile')->willThrowException(new \RuntimeException('gone'))
            : $quickbase->method('downloadFile')->willReturn('%PDF-1.4');

        // An object, not a by-reference array: the sink is handed back inside a returned array,
        // and a reference does not survive that.
        $sink = new class { /** @var array<string, list<list<array<string, mixed>>>> */ public array $rows = []; };
        $grist = $this->createStub(GristClientInterface::class);
        $grist->method('upsertRecords')->willReturnCallback(
            static function (string $doc, string $table, array $records) use ($sink): array {
                $sink->rows[$table][] = $records;

                return array_map(static fn (int $i): int => $i + 1, array_keys($records));
            },
        );
        $grist->method('request')->willReturnCallback(static fn (string $method, string $path): array => match (true) {
            str_contains($path, '/sql') => ['records' => array_map(
                static fn (string $k, int $v): array => ['fields' => ['id' => $v, 'k' => $k]],
                array_keys($rowIds ?? ['7' => 4]),
                array_values($rowIds ?? ['7' => 4]),
            )],
            str_contains($path, '/attachments') => [99],
            default => [],
        });

        $schema = (new QuickbaseAdapter($quickbase))->schema(new ApplicationReference('shop', 'quickbase', 'app-1'));
        $plan = (new QuickbaseToGristConverter($quickbase))->plan($schema);

        return [new QuickbaseGristImporter($quickbase), $plan, new GristDocument($grist, 'doc-1'), $sink];
    }
}
