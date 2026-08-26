<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle;

final readonly class QuickbaseAppRegistry
{
    /** @param array<string, array{id: string, readonly?: bool, tables: array<string, array{id: string, fields: array<string, int>}>}> $apps */
    public function __construct(private array $apps, private string $realm = 'example.quickbase.com')
    {
    }

    /** @return array<string, array{id: string, readonly?: bool, tables: array<string, array{id: string, fields: array<string, int>}>}> */
    public function all(): array
    {
        return $this->apps;
    }

    public function isReadonly(string $name): bool
    {
        return (bool) ($this->apps[$this->configuredName($name)]['readonly'] ?? false);
    }

    public function appUrl(string $nameOrId): string
    {
        return sprintf('https://%s/db/%s', $this->realm, rawurlencode($this->resolve($nameOrId)));
    }

    public function tableUrl(string $tableId): string
    {
        return sprintf('https://%s/db/%s', $this->realm, rawurlencode($tableId));
    }

    public function resolve(string $nameOrId): string
    {
        return $this->apps[$this->configuredName($nameOrId)]['id'] ?? $nameOrId;
    }

    public function resolveTable(string $nameOrId): string
    {
        $parts = explode('.', $nameOrId, 2);
        if (2 !== count($parts)) {
            return $nameOrId;
        }

        return $this->table($parts[0], $parts[1])['id'];
    }

    /** @return array{id: string, fields: array<string, int>} */
    public function table(string $appName, string $tableName): array
    {
        $appName = $this->configuredName($appName);
        if (!isset($this->apps[$appName])) {
            throw new \InvalidArgumentException(sprintf('Quickbase app "%s" is not configured.', $appName));
        }

        return $this->apps[$appName]['tables'][$tableName]
            ?? throw new \InvalidArgumentException(sprintf('Quickbase table "%s.%s" is not configured.', $appName, $tableName));
    }

    private function configuredName(string $name): string
    {
        if (isset($this->apps[$name])) {
            return $name;
        }

        $normalized = str_replace('-', '_', $name);

        return isset($this->apps[$normalized]) ? $normalized : $name;
    }
}
