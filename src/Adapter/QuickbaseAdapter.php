<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Adapter;

use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\RecordStoreBundle\Contract\RecordStoreAdapterInterface;
use Survos\RecordStoreBundle\Exception\UnsupportedRecordStoreOperation;
use Survos\RecordStoreBundle\Model\ApplicationReference;
use Survos\RecordStoreBundle\Model\ApplicationSchema;
use Survos\RecordStoreBundle\Model\FieldSchema;
use Survos\RecordStoreBundle\Model\FieldType;
use Survos\RecordStoreBundle\Model\ProviderCapability;
use Survos\RecordStoreBundle\Model\Record;
use Survos\RecordStoreBundle\Model\RecordPage;
use Survos\RecordStoreBundle\Model\RecordQuery;
use Survos\RecordStoreBundle\Model\TableReference;
use Survos\RecordStoreBundle\Model\TableSchema;
use Survos\RecordStoreBundle\Model\UpsertRequest;
use Survos\RecordStoreBundle\Model\WriteResult;

final readonly class QuickbaseAdapter implements RecordStoreAdapterInterface
{
    public function __construct(private QuickbaseClientInterface $client)
    {
    }

    public function provider(): string
    {
        return 'quickbase';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::SchemaRead,
            ProviderCapability::RecordRead,
            ProviderCapability::RecordWrite,
            ProviderCapability::RecordUpsert,
        ];
    }

    public function schema(ApplicationReference $application): ApplicationSchema
    {
        $configuredTables = [];
        foreach ($application->tables as $table) {
            $configuredTables[$table->id] = $table;
        }

        $tables = [];
        foreach ($this->client->tables($application->id) as $table) {
            $tableId = self::stringValue($table['id'] ?? null, 'table ID');
            $label = self::stringValue($table['name'] ?? $tableId, 'table name');
            $reference = $configuredTables[$tableId] ?? null;
            $reverseFields = [];
            if (null !== $reference) {
                foreach ($reference->fields as $logical => $remote) {
                    if (is_int($remote)) {
                        $reverseFields[$remote] = $logical;
                    }
                }
            }

            $fields = [];
            foreach ($this->client->fields($tableId) as $field) {
                $fieldId = self::positiveInt($field['id'] ?? null, 'field ID');
                $fieldLabel = self::stringValue($field['label'] ?? (string) $fieldId, 'field label');
                $nativeType = is_string($field['fieldType'] ?? null) ? $field['fieldType'] : '';
                $fields[] = new FieldSchema(
                    $fieldId,
                    $reverseFields[$fieldId] ?? (string) $fieldId,
                    $fieldLabel,
                    self::fieldType($nativeType),
                    $field,
                );
            }
            $tableName = null === $reference ? $label : $reference->name;
            $tables[] = new TableSchema($tableId, $tableName, $label, $fields);
        }

        return new ApplicationSchema($application->id, $application->name, $tables);
    }

    public function query(TableReference $table, RecordQuery $query): RecordPage
    {
        $select = array_map(static fn (string $field): int => self::fieldId($table->remoteField($field)), $query->select);
        $sorts = array_map(static fn ($sort): array => [
            'fieldId' => self::fieldId($table->remoteField($sort->field)),
            'order' => $sort->direction->value,
        ], $query->sorts);
        $result = $this->client->queryRecords(
            $table->id,
            $select,
            self::where($table, $query->filters),
            $sorts,
            $query->offset,
            $query->limit,
        );

        $reverse = [];
        foreach ($table->fields as $logical => $remote) {
            if (is_int($remote)) {
                $reverse[$remote] = $logical;
            }
        }
        $records = [];
        foreach (self::data($result) as $rawRecord) {
            $fields = [];
            $recordId = null;
            foreach ($rawRecord as $fieldId => $envelope) {
                if ((!is_int($fieldId) && !ctype_digit((string) $fieldId)) || !is_array($envelope)) {
                    continue;
                }
                $numericId = (int) $fieldId;
                $value = $envelope['value'] ?? null;
                $fields[$reverse[$numericId] ?? (string) $numericId] = $value;
                if (3 === $numericId && (is_int($value) || is_string($value))) {
                    $recordId = $value;
                }
            }
            $records[] = new Record($fields, $recordId);
        }
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $total = is_int($metadata['totalRecords'] ?? null) ? $metadata['totalRecords'] : null;
        $nextOffset = null !== $total && $query->offset + count($records) < $total
            ? $query->offset + count($records)
            : null;

        return new RecordPage($records, $total, $nextOffset);
    }

    public function upsert(TableReference $table, UpsertRequest $request): WriteResult
    {
        if (count($request->keyFields) > 1) {
            throw new UnsupportedRecordStoreOperation('Quickbase supports one merge field per upsert request.');
        }
        $records = [];
        foreach ($request->records as $record) {
            $fields = [];
            foreach ($record->fields as $logical => $value) {
                $fields[self::fieldId($table->remoteField($logical))] = $value;
            }
            $records[] = $fields;
        }
        $mergeFieldId = [] === $request->keyFields ? null : self::fieldId($table->remoteField($request->keyFields[0]));
        $result = $this->client->upsertRecords($table->id, $records, $mergeFieldId, [3]);
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];

        return new WriteResult(
            self::idList($metadata['createdRecordIds'] ?? []),
            self::idList($metadata['updatedRecordIds'] ?? []),
            self::idList($metadata['unchangedRecordIds'] ?? []),
        );
    }

    /** @param array<string, list<int|float|string|bool|null>> $filters */
    private static function where(TableReference $table, array $filters): ?string
    {
        $groups = [];
        foreach ($filters as $logical => $values) {
            $fieldId = self::fieldId($table->remoteField($logical));
            $clauses = array_map(
                static fn (int|float|string|bool|null $value): string => sprintf("{'%d'.EX.'%s'}", $fieldId, self::queryValue($value)),
                $values,
            );
            $groups[] = 1 === count($clauses) ? $clauses[0] : '('.implode('OR', $clauses).')';
        }

        return [] === $groups ? null : implode('AND', $groups);
    }

    private static function queryValue(int|float|string|bool|null $value): string
    {
        $string = match (true) {
            null === $value => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        return str_replace(['\\', "'"], ['\\\\', "\\'"], $string);
    }

    private static function fieldType(string $nativeType): FieldType
    {
        return match (strtolower($nativeType)) {
            'text', 'text-multiple-choice', 'multitext' => FieldType::Text,
            'numeric', 'recordid' => FieldType::Integer,
            'currency', 'percent', 'rating' => FieldType::Decimal,
            'checkbox' => FieldType::Boolean,
            'date' => FieldType::Date,
            'timestamp', 'timeofday' => FieldType::DateTime,
            'duration' => FieldType::Decimal,
            'reference' => FieldType::Reference,
            'file' => FieldType::Attachment,
            default => FieldType::Unknown,
        };
    }

    private static function fieldId(int|string $field): int
    {
        if (is_int($field) && $field > 0) {
            return $field;
        }

        throw new \InvalidArgumentException(sprintf('Quickbase requires a configured numeric field ID; got "%s".', (string) $field));
    }

    private static function stringValue(mixed $value, string $kind): string
    {
        if (!is_string($value) || '' === trim($value)) {
            throw new \UnexpectedValueException(sprintf('Quickbase returned an invalid %s.', $kind));
        }

        return $value;
    }

    private static function positiveInt(mixed $value, string $kind): int
    {
        if (!is_int($value) || $value < 1) {
            throw new \UnexpectedValueException(sprintf('Quickbase returned an invalid %s.', $kind));
        }

        return $value;
    }

    /** @param array<string, mixed> $result
     *  @return list<array<array-key, mixed>>
     */
    private static function data(array $result): array
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Quickbase returned an invalid record list.');
        }
        $records = [];
        foreach ($data as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /** @return list<int|string> */
    private static function idList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_int($id) || is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
