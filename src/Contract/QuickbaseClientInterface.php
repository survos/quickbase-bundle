<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Contract;

interface QuickbaseClientInterface
{
    /** @return list<array<string, mixed>> */
    public function tables(string $appId): array;

    /** @return list<array<string, mixed>> */
    public function fields(string $tableId, bool $includeFieldPermissions = false): array;

    /** @return array{metadata: array<string, mixed>, relationships: list<array<string, mixed>>} */
    public function relationships(string $tableId, int $skip = 0): array;

    /**
     * @param list<int> $select
     * @param list<array{fieldId: int, order: 'ASC'|'DESC'}> $sortBy
     *
     * @return array<string, mixed>
     */
    public function queryRecords(
        string $tableId,
        array $select = [],
        ?string $where = null,
        array $sortBy = [],
        int $skip = 0,
        int $top = 100,
    ): array;

    /**
     * @param array{name: string, singleRecordName: string, description?: string} $definition
     *
     * @return array<string, mixed>
     */
    public function createTable(string $appId, array $definition): array;

    /**
     * @param array{label: string, fieldType: string, properties?: array<string, mixed>} $definition
     *
     * @return array<string, mixed>
     */
    public function createField(string $tableId, array $definition): array;

    /**
     * The relationship definition follows Quickbase's REST schema and must include parentTableId.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function createRelationship(string $childTableId, array $definition): array;

    /**
     * @param iterable<array<int|string, mixed>> $records
     * @param list<int> $fieldsToReturn
     * @return array<string, mixed>
     */
    public function upsertRecords(string $tableId, iterable $records, ?int $mergeFieldId = null, array $fieldsToReturn = []): array;

    /**
     * @param array<string, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $path, array $options = []): array;
}
