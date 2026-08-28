<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Grist;

final readonly class TablePlan
{
    /** @param list<ColumnPlan> $columns every source field, converted or not */
    public function __construct(
        public string $sourceId,
        public string $sourceName,
        public string $id,
        public string $label,
        public array $columns,
    ) {
    }

    /** @return list<ColumnPlan> */
    public function converted(): array
    {
        return array_values(array_filter($this->columns, static fn (ColumnPlan $c): bool => $c->converted));
    }

    /** @return list<ColumnPlan> */
    public function dropped(): array
    {
        return array_values(array_filter($this->columns, static fn (ColumnPlan $c): bool => !$c->converted));
    }

    /** @return list<ColumnPlan> */
    public function references(): array
    {
        return array_values(array_filter($this->columns, static fn (ColumnPlan $c): bool => $c->isReference()));
    }

    /** @return list<ColumnPlan> */
    public function attachments(): array
    {
        return array_values(array_filter($this->columns, static fn (ColumnPlan $c): bool => $c->isAttachment()));
    }

    /**
     * Columns holding their own value, as opposed to a row id that only means something once
     * the table it points at has been loaded.
     *
     * @return list<ColumnPlan>
     */
    public function scalars(): array
    {
        return array_values(array_filter(
            $this->converted(),
            static fn (ColumnPlan $c): bool => !$c->isReference() && !$c->isAttachment(),
        ));
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $columns = [];
        foreach ($this->converted() as $column) {
            $columns[(string) $column->id] = $column->definition;
        }

        return $columns;
    }
}
