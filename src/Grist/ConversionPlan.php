<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Grist;

/**
 * The whole intended Grist document, before anything has been written.
 *
 * This is what --dry-run prints. It is the review artifact, so it has to hold the fields that
 * are not being converted as well as the ones that are.
 */
final readonly class ConversionPlan
{
    /**
     * @param list<TablePlan>         $tables
     * @param list<UnsupportedFeature> $unsupported everything the application has that the
     *                                              document will not: reports, forms, roles
     */
    public function __construct(
        public string $sourceAppId,
        public string $sourceAppName,
        public array $tables,
        public string $keyColumn,
        public array $unsupported = [],
    ) {
    }

    public function withUnsupported(array $unsupported): self
    {
        return new self($this->sourceAppId, $this->sourceAppName, $this->tables, $this->keyColumn, $unsupported);
    }

    public function table(string $sourceId): ?TablePlan
    {
        foreach ($this->tables as $table) {
            if ($table->sourceId === $sourceId) {
                return $table;
            }
        }

        return null;
    }

    /** @return array<string, array{label?: string, columns: array<string, array<string, mixed>>}> */
    public function definitions(): array
    {
        $tables = [];
        foreach ($this->tables as $table) {
            $tables[$table->id] = ['label' => $table->label, 'columns' => $table->definitions()];
        }

        return $tables;
    }

    /**
     * Every place the conversion is not faithful, in one list -- dropped fields and fields
     * whose meaning changed.
     *
     * @return list<array{table: string, field: string, source: string, target: string, note: string}>
     */
    public function losses(): array
    {
        $losses = [];
        foreach ($this->tables as $table) {
            foreach ($table->columns as $column) {
                if (!$column->isLossy()) {
                    continue;
                }
                $losses[] = [
                    'table' => $table->label,
                    'field' => $column->label,
                    'source' => $column->source(),
                    'target' => $column->converted ? (string) $column->gristType : '(not converted)',
                    'note' => (string) $column->note,
                ];
            }
        }

        return $losses;
    }
}
