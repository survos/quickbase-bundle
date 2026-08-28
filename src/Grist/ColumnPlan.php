<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Grist;

use Survos\RecordStore\Model\FieldType;

/**
 * What one Quickbase field becomes in Grist, including when the answer is "nothing".
 *
 * A dropped field is a plan entry rather than an omission on purpose: a conversion that
 * quietly loses a column is worse than one that refuses, and the only way a reviewer can
 * see what was lost is if the thing that was lost is still in the report.
 */
final readonly class ColumnPlan
{
    /**
     * @param int                  $fieldId    the Quickbase field id, which is its only real identifier
     * @param string               $mode       Quickbase's field mode: '', formula, lookup, summary
     * @param string|null          $id         Grist column id, null when nothing is created
     * @param string|null          $gristType  native Grist type, null when nothing is created
     * @param array<string, mixed> $definition the `fields` payload that creates the column
     * @param string|null          $note       what a reviewer needs to know: why it was dropped,
     *                                         or what changed meaning on the way across
     */
    private function __construct(
        public int $fieldId,
        public string $label,
        public string $nativeType,
        public string $mode,
        public FieldType $normalized,
        public ?string $id,
        public ?string $gristType,
        public array $definition,
        public ?string $referencedTable,
        public ?string $note,
        public bool $converted,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public static function converted(
        int $fieldId,
        string $label,
        string $nativeType,
        string $mode,
        FieldType $normalized,
        string $id,
        string $gristType,
        array $definition,
        ?string $referencedTable = null,
        ?string $note = null,
    ): self {
        return new self($fieldId, $label, $nativeType, $mode, $normalized, $id, $gristType, $definition, $referencedTable, $note, true);
    }

    public static function dropped(
        int $fieldId,
        string $label,
        string $nativeType,
        string $mode,
        FieldType $normalized,
        string $note,
    ): self {
        return new self($fieldId, $label, $nativeType, $mode, $normalized, null, null, [], null, $note, false);
    }

    public function isReference(): bool
    {
        return $this->converted && null !== $this->referencedTable;
    }

    public function isAttachment(): bool
    {
        return $this->converted && 'Attachments' === $this->gristType;
    }

    /** Whether anything about this field is worth a human's attention before trusting the result. */
    public function isLossy(): bool
    {
        return null !== $this->note;
    }

    public function source(): string
    {
        return '' === $this->mode ? $this->nativeType : sprintf('%s (%s)', $this->nativeType, $this->mode);
    }
}
