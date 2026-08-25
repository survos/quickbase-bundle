<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle;

final readonly class QuickbaseAppRegistry
{
    /** @param array<string, array{id: string, tables: array<string, array{id: string, fields: array<string, int>}>}> $apps */
    public function __construct(private array $apps)
    {
    }

    /** @return array<string, array{id: string, tables: array<string, array{id: string, fields: array<string, int>}>}> */
    public function all(): array
    {
        return $this->apps;
    }

    public function resolve(string $nameOrId): string
    {
        return $this->apps[$nameOrId]['id'] ?? $nameOrId;
    }

    /** @return array{id: string, fields: array<string, int>} */
    public function table(string $appName, string $tableName): array
    {
        if (!isset($this->apps[$appName])) {
            throw new \InvalidArgumentException(sprintf('Quickbase app "%s" is not configured.', $appName));
        }

        return $this->apps[$appName]['tables'][$tableName]
            ?? throw new \InvalidArgumentException(sprintf('Quickbase table "%s.%s" is not configured.', $appName, $tableName));
    }
}
