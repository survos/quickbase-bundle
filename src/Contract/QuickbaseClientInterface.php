<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Contract;

interface QuickbaseClientInterface
{
    /** @return list<array<string, mixed>> */
    public function tables(string $appId): array;

    /** @return list<array<string, mixed>> */
    public function fields(string $tableId, bool $includeFieldPermissions = false): array;

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
