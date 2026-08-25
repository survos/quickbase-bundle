<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Contract;

interface QuickbaseClientInterface
{
    /**
     * @param iterable<array<int|string, mixed>> $records
     * @param list<int> $fieldsToReturn
     * @return array<string, mixed>
     */
    public function upsertRecords(string $tableId, iterable $records, ?int $mergeFieldId = null, array $fieldsToReturn = []): array;

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $options = []): array;
}
