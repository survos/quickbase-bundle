<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Grist;

use Survos\Grist\Schema\GristColumnType;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\Quickbase\Qbl\QblDocument;
use Survos\RecordStore\Model\ApplicationSchema;
use Survos\RecordStore\Model\FieldSchema;
use Survos\RecordStore\Model\FieldType;
use Survos\RecordStore\Model\TableSchema;

/**
 * Decides what a Quickbase application becomes in Grist. Writes nothing.
 *
 * The normalized ApplicationSchema the adapter produces is most of the answer, but not all of
 * it: Quickbase's `mode` says whether a field holds a value or computes one, and its
 * `properties` say which table a reference points at and which values a pick list allows.
 * Those live in FieldSchema::$providerMetadata, so this class reads them -- which is also why
 * it lives here rather than in grist-php, which has no business knowing what a Quickbase
 * lookup is.
 *
 * Nothing is dropped silently. Every source field comes back as a ColumnPlan, including the
 * ones that produce no column, with the reason attached.
 */
final readonly class QuickbaseToGristConverter
{
    /** Quickbase's built-in fields, which exist in every table with these exact ids. */
    public const int RECORD_ID = 3;
    public const int DATE_CREATED = 1;
    public const int DATE_MODIFIED = 2;

    /**
     * The Quickbase Record ID#, kept as a real column.
     *
     * It is the natural key: it already exists, it is permanent, and it is the only thing that
     * makes a second run of the import an update rather than a duplicate. Nothing here invents
     * or hashes an id.
     */
    public const string KEY_COLUMN = 'QbRecordId';

    /** Grist uses these column ids itself. */
    private const array RESERVED = ['id', 'manualSort'];

    public function __construct(private QuickbaseClientInterface $quickbase)
    {
    }

    /**
     * @param list<string> $only          table names or ids to include; empty means all
     * @param bool         $skipDerived   drop Quickbase lookup and summary fields instead of
     *                                    freezing their current value into a static column
     */
    public function plan(
        ApplicationSchema $schema,
        array $only = [],
        bool $skipDerived = false,
        string $timezone = GristColumnType::DEFAULT_TIMEZONE,
    ): ConversionPlan {
        $tables = self::selected($schema->tables, $only);
        if ([] === $tables) {
            throw new \InvalidArgumentException(sprintf(
                'No tables selected. Available: %s',
                implode(', ', array_map(static fn (TableSchema $t): string => $t->label, $schema->tables)),
            ));
        }

        // Table ids first: a reference column needs the Grist id of the table it points at,
        // and that table may be planned after the one referring to it.
        $tableIds = [];
        $used = [];
        foreach ($tables as $table) {
            $tableIds[$table->id] = $used[] = self::identifier($table->label, $used, 'Table');
        }

        $plans = [];
        foreach ($tables as $table) {
            $plans[] = new TablePlan(
                $table->id,
                $table->name,
                $tableIds[$table->id],
                $table->label,
                $this->columns($table, $tableIds, $skipDerived, $timezone),
            );
        }

        return new ConversionPlan($schema->id, $schema->name, $plans, self::KEY_COLUMN);
    }

    /**
     * Everything the application has that the record-store contract has no room for.
     *
     * QBL is the right source: one export holds the forms, roles, dashboards and pipelines by
     * name, where REST exposes only reports. So it is tried first, and the REST sweep is the
     * fallback -- the Solutions API is a realm-level entitlement, and on a realm without it
     * every QBL call answers 403 no matter which application is asked for.
     *
     * @param list<TablePlan> $tables
     *
     * @return list<UnsupportedFeature>
     */
    public function unsupportedFeatures(string $appId, array $tables): array
    {
        $features = [];
        foreach ($tables as $table) {
            foreach ($this->quickbase->reports($table->sourceId) as $report) {
                $name = is_string($report['name'] ?? null) ? $report['name'] : (string) ($report['id'] ?? '?');
                $type = is_string($report['type'] ?? null) ? $report['type'] : 'unknown';
                $features[] = UnsupportedFeature::forReport($table->label, $name, $type);
            }
        }

        return array_merge($features, $this->solutionFeatures($appId));
    }

    /**
     * The QBL half: named forms, roles, dashboards and pipelines, or an entry saying why the
     * list is missing.
     *
     * @return list<UnsupportedFeature>
     */
    private function solutionFeatures(string $appId): array
    {
        try {
            $document = QblDocument::fromYaml($this->quickbase->exportSolution($appId));
        } catch (\Throwable $exception) {
            // Not silently degraded: an empty list here would read as "the application has no
            // forms", which is a very different statement from "nothing could look".
            return [new UnsupportedFeature(
                '(application)', 'qbl', 'Forms, roles, dashboards and pipelines', Verdict::None,
                sprintf(
                    'NOT ENUMERATED. These live in the QBL solution export, which failed: %s. On a realm without '
                    .'the Solutions API entitlement every QBL call answers 403, so this list cannot be built here '
                    .'-- assume the application has forms, roles and pipelines that this conversion does not carry, '
                    .'and enumerate them by hand in the Quickbase UI.',
                    $exception->getMessage(),
                ),
            )];
        }

        $features = [];
        foreach ($document->toArray()['Resources'] ?? [] as $logicalId => $resource) {
            $type = is_array($resource) && is_string($resource['Type'] ?? null) ? $resource['Type'] : '';
            $verdict = self::verdictForQblType($type);
            if (null === $verdict) {
                continue;
            }
            $name = is_array($resource) && is_string($resource['Properties']['Name'] ?? null)
                ? $resource['Properties']['Name']
                : (string) $logicalId;
            $features[] = new UnsupportedFeature('(application)', self::qblKind($type), $name, $verdict[0], $verdict[1]);
        }

        return $features;
    }

    /**
     * QBL resource type => what can be done about it, or null for the ones the tables already
     * cover (QB::Table, QB::Field, QB::Relationship and so on).
     *
     * @return array{Verdict, string}|null
     */
    private static function verdictForQblType(string $type): ?array
    {
        return match (true) {
            str_contains($type, '::Form') => [Verdict::Rebuild,
                'Grist forms are real forms but a different model -- one form per table, built on the columns. '
                .'See GristFormManager in survos/grist-php.'],
            str_contains($type, '::Role'), str_contains($type, '::Permission') => [Verdict::None,
                'Grist access rules are per document, table and row and do not map from Quickbase roles. Until '
                .'they are written deliberately the document is as open as its share link.'],
            str_contains($type, '::Dashboard'), str_contains($type, '::Page') => [Verdict::Rebuild,
                'A Grist page holding the rebuilt widgets.'],
            str_contains($type, '::Pipeline'), str_contains($type, '::Channel'), str_contains($type, '::Connection') => [Verdict::AppSide,
                'Quickbase\'s strongest feature and the one with the least on the other side. Grist has an outgoing '
                .'webhook per table and no connectors, retry policy or run history, so the integration becomes '
                .'application code: a Grist webhook into a Survos\Kit\Webhook receiver, with Messenger doing the '
                .'scheduling and retries Pipelines was doing. Budget for it separately -- it is rewritten, not moved.'],
            str_contains($type, '::Notification'), str_contains($type, '::Subscription') => [Verdict::AppSide,
                'Grist has no notification rules. A webhook into the application, and the mail goes out from there.'],
            str_contains($type, '::Webhook') => [Verdict::AppSide,
                'Grist calls out on row change (GristWebhookManager) but refuses every URL until '
                .'ALLOWED_WEBHOOK_DOMAINS names the host, and has no inbound webhook at all -- writes arrive '
                .'through the REST API, which means something has to hold the API key.'],
            default => null,
        };
    }

    private static function qblKind(string $type): string
    {
        $parts = explode('::', $type);

        return strtolower(end($parts) ?: 'resource');
    }

    /**
     * @param array<string, string> $tableIds source table id => Grist table id
     *
     * @return list<ColumnPlan>
     */
    private function columns(TableSchema $table, array $tableIds, bool $skipDerived, string $timezone): array
    {
        $columns = [];
        $used = self::RESERVED;

        foreach ($table->fields as $field) {
            $meta = $field->providerMetadata;
            $native = is_string($meta['fieldType'] ?? null) ? $meta['fieldType'] : '';
            $mode = is_string($meta['mode'] ?? null) ? $meta['mode'] : '';
            $properties = is_array($meta['properties'] ?? null) ? $meta['properties'] : [];

            $columns[] = $this->column($field, $native, $mode, $properties, $tableIds, $used, $skipDerived, $timezone);
        }

        return $columns;
    }

    /**
     * @param array<string, mixed>  $properties
     * @param array<string, string> $tableIds
     * @param list<string>          $used       taken column ids, appended to in place
     */
    private function column(
        FieldSchema $field,
        string $native,
        string $mode,
        array $properties,
        array $tableIds,
        array &$used,
        bool $skipDerived,
        string $timezone,
    ): ColumnPlan {
        $id = (int) $field->id;
        $label = $field->label;
        $type = $field->type;

        // Quickbase's built-in Record ID#. Everything else about this conversion depends on it
        // surviving as data, so it is named explicitly rather than sanitized from its label
        // ("Record ID#" would become Record_ID).
        if (self::RECORD_ID === $id) {
            $used[] = self::KEY_COLUMN;

            return ColumnPlan::converted(
                $id, $label, $native, $mode, FieldType::Integer, self::KEY_COLUMN, 'Int',
                GristColumnType::definition(FieldType::Integer, 'Quickbase Record ID#'),
                note: 'The Quickbase record id, kept as the natural key. Re-running the import matches on it '
                    .'instead of creating duplicates. Grist cannot enforce that it stays unique.',
            );
        }

        if ('user' === $native) {
            return ColumnPlan::dropped(
                $id, $label, $native, $mode, $type,
                'Quickbase user field. Grist has no user column type: a document has access rules, not a '
                .'directory of people to point a cell at. Add a Text column and copy the addresses if the '
                .'value matters.',
            );
        }

        if ('dblink' === $native) {
            return ColumnPlan::dropped(
                $id, $label, $native, $mode, $type,
                'Quickbase report link, which holds no data of its own. In Grist the child table\'s reference '
                .'column is the same relationship, shown as a linked widget.',
            );
        }

        if ($skipDerived && in_array($mode, ['lookup', 'summary'], true)) {
            return ColumnPlan::dropped(
                $id, $label, $native, $mode, $type,
                sprintf('Quickbase %s field, skipped by request. The value it copied lives in the related table.', $mode),
            );
        }

        $columnId = self::identifier($label, $used, 'Field');
        $used[] = $columnId;

        // A reference field: Quickbase reports it as numeric, and only properties.foreignKey
        // and masterTableId say otherwise.
        if (FieldType::Reference === $type) {
            $master = is_string($properties['masterTableId'] ?? null) ? $properties['masterTableId'] : null;
            $target = null === $master ? null : ($tableIds[$master] ?? null);

            if (null === $target) {
                return ColumnPlan::converted(
                    $id, $label, $native, $mode, FieldType::Integer, $columnId, 'Int',
                    GristColumnType::definition(FieldType::Integer, $label),
                    note: sprintf(
                        'Reference to table "%s", which is not in this conversion. Kept as the raw Quickbase '
                        .'record id so nothing is lost; convert that table too and re-run to turn it into a '
                        .'real Grist reference.',
                        $master ?? 'unknown',
                    ),
                );
            }

            return ColumnPlan::converted(
                $id, $label, $native, $mode, $type, $columnId, GristColumnType::native($type, $target),
                GristColumnType::definition($type, $label, referencedTable: $target),
                referencedTable: $target,
                note: 'Quickbase stores the parent record id; Grist stores its own row id, so this column is '
                    .'filled in a second pass once both tables have rows.',
            );
        }

        [$normalized, $note, $widgetOptions, $choices, $list] = $this->interpret($native, $mode, $type, $properties);

        return ColumnPlan::converted(
            $id, $label, $native, $mode, $normalized, $columnId,
            GristColumnType::native($normalized, list: $list, timezone: $timezone),
            GristColumnType::definition($normalized, $label, choices: $choices, list: $list, widgetOptions: $widgetOptions, timezone: $timezone),
            note: self::combine($note, self::constraintNote($properties, $field->providerMetadata)),
        );
    }

    /**
     * The Quickbase-specific nuance the normalized FieldType cannot carry.
     *
     * @param array<string, mixed> $properties
     *
     * @return array{FieldType, string|null, array<string, mixed>, list<string>, bool}
     */
    private function interpret(string $native, string $mode, FieldType $type, array $properties): array
    {
        $note = match ($mode) {
            // Grist formulas are Python and Quickbase's are not; translating them is a language
            // port, not a mapping. The current value is written as a plain column instead, which
            // is correct on the day of the import and static afterwards.
            'formula' => 'Was a Quickbase formula, now a static value. Grist formulas are Python; this one was '
                .'not translated. Formula: '.self::excerpt($properties['formula'] ?? ''),
            'lookup' => 'Was a Quickbase lookup, now a static copy. In Grist the same value is $Ref.Column '
                .'through the reference column, if you would rather it stayed live.',
            'summary' => sprintf(
                'Was a Quickbase %s summary, now a static number. Grist recomputes this with a summary table '
                .'or a formula over the reference.',
                is_string($properties['summaryFunction'] ?? null) ? $properties['summaryFunction'] : 'aggregate',
            ),
            default => null,
        };

        $choices = [];
        $list = false;
        $widgetOptions = [];

        switch (strtolower($native)) {
            case 'text-multiple-choice':
            case 'multitext':
                $list = 'multitext' === strtolower($native);
                foreach ((array) ($properties['choices'] ?? []) as $choice) {
                    if (is_string($choice)) {
                        $choices[] = $choice;
                    }
                }
                if ([] === $choices) {
                    $note = self::combine($note, 'Quickbase reports no allowed values for this pick list, so the '
                        .'Grist column starts empty and every value in it will read as invalid until the choices '
                        .'are added.');
                } elseif (true === ($properties['allowNewChoices'] ?? null)) {
                    $note = self::combine($note, 'Quickbase allowed new values to be added to this list. Grist keeps '
                        .'an unlisted value but marks the cell invalid.');
                }
                break;

            case 'currency':
                $widgetOptions = ['numMode' => 'currency', 'decimals' => (int) ($properties['decimalPlaces'] ?? 2)];
                break;

            case 'percent':
                $widgetOptions = ['numMode' => 'percent'];
                break;

            case 'duration':
                $note = self::combine($note, 'Quickbase duration, converted to a plain number. Grist has no duration '
                    .'type, so the unit is only in the column label.');
                break;

            case 'address':
                $note = self::combine($note, 'Quickbase composite address. The parent field carries the formatted '
                    .'value; its street/city/state parts are separate child fields and convert on their own.');
                break;

            case 'url':
                $widgetOptions = ['widget' => 'HyperLink'];
                break;
        }

        if (FieldType::Time === $type) {
            $note = self::combine($note, 'Quickbase time of day. Grist has no time-only type, so this becomes text; '
                .'sorting it works because the format is zero-padded, arithmetic on it does not.');
        }

        if (FieldType::Unknown === $type) {
            $note = self::combine($note, sprintf(
                'No mapping for Quickbase type "%s". Converted to text so the value survives; check it reads correctly.',
                $native,
            ));
        }

        return [FieldType::Unknown === $type ? FieldType::Text : $type, $note, $widgetOptions, $choices, $list];
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $meta
     */
    private static function constraintNote(array $properties, array $meta): ?string
    {
        $lost = [];
        if (true === ($meta['required'] ?? null)) {
            $lost[] = 'required';
        }
        if (true === ($meta['unique'] ?? null)) {
            $lost[] = 'unique';
        }

        return [] === $lost ? null : sprintf(
            'Quickbase enforced %s on this field. Grist has no column constraints, so nothing enforces it after '
            .'the conversion.',
            implode(' and ', $lost),
        );
    }

    /**
     * @param list<TableSchema> $tables
     * @param list<string>      $only
     *
     * @return list<TableSchema>
     */
    private static function selected(array $tables, array $only): array
    {
        if ([] === $only) {
            return $tables;
        }

        $wanted = array_map(strtolower(...), $only);

        return array_values(array_filter($tables, static fn (TableSchema $t): bool => in_array(strtolower($t->id), $wanted, true)
            || in_array(strtolower($t->label), $wanted, true)
            || in_array(strtolower($t->name), $wanted, true)));
    }

    /**
     * A Grist identifier from a Quickbase label.
     *
     * Grist ids are Python identifiers; a leading underscore is reserved for its own metadata
     * tables, so a label like "_internal" gets a prefix rather than a stripped underscore.
     *
     * @param list<string> $used
     */
    private static function identifier(string $label, array $used, string $fallbackPrefix): string
    {
        // Transliterate the symbols that carry meaning before stripping punctuation, or
        // Quickbase's "# of Loan records" sanitizes to "of_Loan_records", which reads as a
        // different field.
        $label = strtr($label, ['#' => ' Num ', '%' => ' Pct ', '&' => ' and ', '@' => ' at ']);
        $id = trim((string) preg_replace('/_+/', '_', (string) preg_replace('/[^A-Za-z0-9]+/', '_', $label)), '_');
        if ('' === $id || !preg_match('/^[A-Za-z]/', $id)) {
            $id = $fallbackPrefix.('' === $id ? '' : '_'.$id);
        }

        $candidate = $id;
        $suffix = 2;
        while (in_array($candidate, $used, true)) {
            $candidate = $id.'_'.$suffix++;
        }

        return $candidate;
    }

    private static function combine(?string ...$notes): ?string
    {
        $present = array_values(array_filter($notes, static fn (?string $n): bool => null !== $n && '' !== $n));

        return [] === $present ? null : implode(' ', $present);
    }

    private static function excerpt(mixed $value): string
    {
        if (!is_string($value) || '' === trim($value)) {
            return '(none)';
        }
        $flat = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($flat) > 120 ? mb_substr($flat, 0, 117).'...' : $flat;
    }
}
