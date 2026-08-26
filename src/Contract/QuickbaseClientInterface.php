<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Contract;

interface QuickbaseClientInterface
{
    /** @param array<string, mixed> $definition @return array<string, mixed> */
    public function createApp(array $definition): array;

    /** @return array<string, mixed> */
    public function app(string $appId): array;

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    public function updateApp(string $appId, array $definition): array;

    /** @return array<string, mixed> */
    public function deleteApp(string $appId, string $appName): array;

    /** @return list<array<string, mixed>> */
    public function tables(string $appId): array;

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    public function updateTable(string $appId, string $tableId, array $definition): array;

    /** @return array<string, mixed> */
    public function deleteTable(string $appId, string $tableId): array;

    /** @return list<array<string, mixed>> */
    public function fields(string $tableId, bool $includeFieldPermissions = false): array;

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    public function updateField(string $tableId, int $fieldId, array $definition): array;

    /** @param list<int> $fieldIds @return array<string, mixed> */
    public function deleteFields(string $tableId, array $fieldIds): array;

    /** @return array{metadata: array<string, mixed>, relationships: list<array<string, mixed>>} */
    public function relationships(string $tableId, int $skip = 0): array;

    /** @return list<array<string, mixed>> */
    public function reports(string $tableId): array;

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

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    public function updateRelationship(string $childTableId, int $relationshipId, array $definition): array;

    /** @return array<string, mixed> */
    public function deleteRelationship(string $childTableId, int $relationshipId): array;

    /**
     * @param iterable<array<int|string, mixed>> $records
     * @param list<int> $fieldsToReturn
     * @return array<string, mixed>
     */
    public function upsertRecords(string $tableId, iterable $records, ?int $mergeFieldId = null, array $fieldsToReturn = []): array;

    /** @return array<string, mixed> */
    public function deleteRecords(string $tableId, string $where): array;

    public function exportSolution(string $solutionId, string $qblVersion = '0.14'): string;

    /** @return array<string, mixed> */
    public function createSolution(string $qbl, string $qblVersion = '0.14'): array;

    /** @return array<string, mixed> */
    public function updateSolution(string $solutionId, string $qbl, string $qblVersion = '0.14'): array;

    /** @return array<string, mixed> */
    public function solutionChanges(string $solutionId, string $qbl, string $qblVersion = '0.14'): array;

    /**
     * @param array<string, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $path, array $options = []): array;
}
