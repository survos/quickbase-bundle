<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Command;

use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\Exception\QuickbaseApiException;
use Survos\QuickbaseBundle\QuickbaseAppRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class QuickbaseCommands
{
    public function __construct(
        private QuickbaseClientInterface $quickbase,
        private QuickbaseAppRegistry $apps,
    ) {
    }

    #[AsCommand('quickbase:apps', 'List configured Quickbase apps')]
    public function apps(SymfonyStyle $io, #[Option('Emit the configured apps as JSON')] bool $json = false): int
    {
        $apps = $this->apps->all();

        if ($json) {
            $io->writeln(json_encode($apps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($apps as $name => $app) {
            $rows[] = [$name, $app['id']];
        }

        $io->title('Configured Quickbase apps');
        $io->table(['Name', 'App ID'], $rows);
        $io->success(sprintf('%d app%s.', count($apps), 1 === count($apps) ? '' : 's'));

        return Command::SUCCESS;
    }

    #[AsCommand('quickbase:tables', 'List the tables in a Quickbase app')]
    public function tables(
        SymfonyStyle $io,
        #[Argument('Configured app name or Quickbase application ID')] string $app,
        #[Option('Emit the unmodified API response as JSON')] bool $json = false,
    ): int {
        $appId = $this->apps->resolve($app);

        try {
            $tables = $this->quickbase->tables($appId);
        } catch (QuickbaseApiException $exception) {
            return $this->renderApiError($io, $exception);
        }

        if ($json) {
            $io->writeln(json_encode($tables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = array_map(static fn (array $table): array => [
            self::scalar($table['id'] ?? null),
            self::scalar($table['name'] ?? null),
            self::scalar($table['singleRecordName'] ?? null),
            self::scalar($table['description'] ?? null),
        ], $tables);

        $label = $app === $appId ? $appId : sprintf('%s (%s)', $app, $appId);
        $io->title(sprintf('Quickbase tables · %s', $label));
        $io->table(['ID', 'Name', 'Record name', 'Description'], $rows);
        $io->success(sprintf('%d table%s.', count($tables), 1 === count($tables) ? '' : 's'));

        return Command::SUCCESS;
    }

    #[AsCommand('quickbase:fields', 'List the fields in a Quickbase table')]
    public function fields(
        SymfonyStyle $io,
        #[Argument('Configured app.table name or Quickbase table ID')] string $table,
        #[Option('Include field permission metadata')] bool $permissions = false,
        #[Option('Emit the unmodified API response as JSON')] bool $json = false,
    ): int {
        $tableId = $this->apps->resolveTable($table);

        try {
            $fields = $this->quickbase->fields($tableId, $permissions);
        } catch (QuickbaseApiException $exception) {
            return $this->renderApiError($io, $exception);
        }

        if ($json) {
            $io->writeln(json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = array_map(static fn (array $field): array => [
            self::scalar($field['id'] ?? null),
            self::scalar($field['label'] ?? null),
            self::scalar($field['fieldType'] ?? null),
            self::scalar($field['mode'] ?? null),
        ], $fields);

        $io->title(sprintf('Quickbase fields · %s', $tableId));
        $io->table(['ID', 'Label', 'Type', 'Mode'], $rows);
        $io->success(sprintf('%d field%s.', count($fields), 1 === count($fields) ? '' : 's'));

        return Command::SUCCESS;
    }

    #[AsCommand('quickbase:relationships', 'List relationships for a Quickbase table')]
    public function relationships(
        SymfonyStyle $io,
        #[Argument('Configured app.table name or Quickbase table ID')] string $table,
        #[Option('Relationships to skip')] int $skip = 0,
        #[Option('Emit the unmodified API response as JSON')] bool $json = false,
    ): int {
        $tableId = $this->apps->resolveTable($table);

        try {
            $result = $this->quickbase->relationships($tableId, $skip);
        } catch (QuickbaseApiException $exception) {
            return $this->renderApiError($io, $exception);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($json) {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $relationships = $result['relationships'];
        $rows = array_map(static fn (array $relationship): array => [
            self::scalar($relationship['id'] ?? null),
            self::scalar($relationship['parentTableId'] ?? null),
            self::scalar($relationship['childTableId'] ?? $tableId),
            self::nestedScalar($relationship, 'foreignKeyField', 'id'),
            self::nestedScalar($relationship, 'foreignKeyField', 'label'),
        ], $relationships);

        $io->title(sprintf('Quickbase relationships · %s', $table));
        $io->table(['ID', 'Parent table', 'Child table', 'Foreign key', 'Label'], $rows);
        $io->success(sprintf('%d relationship%s.', count($relationships), 1 === count($relationships) ? '' : 's'));

        return Command::SUCCESS;
    }

    #[AsCommand('quickbase:query', 'Query records from a Quickbase table')]
    public function query(
        SymfonyStyle $io,
        #[Argument('Configured app.table name or Quickbase table ID')] string $table,
        #[Option('Comma-separated field IDs to return')] string $select = '',
        #[Option('Quickbase query language expression')] ?string $where = null,
        #[Option('Comma-separated fieldId:ASC|DESC sorts')] string $sort = '',
        #[Option('Records to skip')] int $skip = 0,
        #[Option('Maximum records to return')] int $top = 20,
        #[Option('Emit the unmodified API response as JSON')] bool $json = false,
    ): int {
        $tableId = $this->apps->resolveTable($table);

        try {
            $fieldIds = self::parseFieldIds($select);
            $result = $this->quickbase->queryRecords(
                $tableId,
                $fieldIds,
                $where,
                self::parseSorts($sort),
                $skip,
                $top,
            );
        } catch (QuickbaseApiException $exception) {
            return $this->renderApiError($io, $exception);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($json) {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $records = self::records($result);
        $columns = [] !== $fieldIds ? $fieldIds : self::recordFieldIds($records);
        $rows = array_map(static function (array $record) use ($columns): array {
            $row = [];
            foreach ($columns as $fieldId) {
                $field = $record[$fieldId] ?? null;
                $row[] = is_array($field) ? self::scalar($field['value'] ?? null) : '';
            }

            return $row;
        }, $records);

        $io->title(sprintf('Quickbase records · %s', $table));
        $io->table(array_map(static fn (int $fieldId): string => (string) $fieldId, $columns), $rows);
        $io->success(sprintf('%d record%s.', count($records), 1 === count($records) ? '' : 's'));

        return Command::SUCCESS;
    }

    private function renderApiError(SymfonyStyle $io, QuickbaseApiException $exception): int
    {
        $io->error($exception->getMessage());
        if (null !== $exception->apiRay) {
            $io->note('Quickbase API ray: '.$exception->apiRay);
        }

        return Command::FAILURE;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) || null === $value ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $value */
    private static function nestedScalar(array $value, string $objectKey, string $valueKey): string
    {
        $nested = $value[$objectKey] ?? null;

        return is_array($nested) ? self::scalar($nested[$valueKey] ?? null) : '';
    }

    /** @return list<int> */
    private static function parseFieldIds(string $value): array
    {
        if ('' === trim($value)) {
            return [];
        }

        $fieldIds = [];
        foreach (explode(',', $value) as $fieldId) {
            $fieldId = trim($fieldId);
            if (!ctype_digit($fieldId) || (int) $fieldId < 1) {
                throw new \InvalidArgumentException(sprintf('Invalid Quickbase field ID "%s".', $fieldId));
            }
            $fieldIds[] = (int) $fieldId;
        }

        return $fieldIds;
    }

    /** @return list<array{fieldId: int, order: 'ASC'|'DESC'}> */
    private static function parseSorts(string $value): array
    {
        if ('' === trim($value)) {
            return [];
        }

        $sorts = [];
        foreach (explode(',', $value) as $sort) {
            $parts = array_map('trim', explode(':', $sort, 2));
            $fieldId = $parts[0];
            $order = strtoupper($parts[1] ?? 'ASC');
            if (!ctype_digit($fieldId) || (int) $fieldId < 1 || !in_array($order, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException(sprintf('Invalid Quickbase sort "%s"; use fieldId:ASC or fieldId:DESC.', $sort));
            }
            $sorts[] = ['fieldId' => (int) $fieldId, 'order' => $order];
        }

        return $sorts;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<int, mixed>>
     */
    private static function records(array $result): array
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $records = [];
        foreach ($data as $record) {
            if (!is_array($record)) {
                continue;
            }
            $normalized = [];
            foreach ($record as $fieldId => $field) {
                if (is_int($fieldId) || ctype_digit((string) $fieldId)) {
                    $normalized[(int) $fieldId] = $field;
                }
            }
            $records[] = $normalized;
        }

        return $records;
    }

    /**
     * @param list<array<int, mixed>> $records
     *
     * @return list<int>
     */
    private static function recordFieldIds(array $records): array
    {
        $fieldIds = [];
        foreach ($records as $record) {
            foreach (array_keys($record) as $fieldId) {
                $fieldIds[$fieldId] = $fieldId;
            }
        }
        sort($fieldIds);

        return $fieldIds;
    }
}
