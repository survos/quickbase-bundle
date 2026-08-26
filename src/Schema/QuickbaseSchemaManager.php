<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Schema;

use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;

/** REST schema snapshot/materialization; forms and roles are handled by QBL Solution APIs. */
final readonly class QuickbaseSchemaManager
{
    public function __construct(private QuickbaseClientInterface $quickbase) {}

    /** @return array<string, mixed> */
    public function snapshot(string $appId): array
    {
        $app = $this->quickbase->app($appId);
        $tables = $this->quickbase->tables($appId);
        $fieldsByTable = $relationshipsByTable = [];
        foreach ($tables as $table) {
            $id = self::string($table, 'id');
            $fieldsByTable[$id] = $this->quickbase->fields($id);
            $relationshipsByTable[$id] = $this->quickbase->relationships($id)['relationships'];
        }
        $managed = self::relationshipManagedLabels($relationshipsByTable);
        $definitions = [];
        foreach ($tables as $table) {
            $tableId = self::string($table, 'id');
            $fields = [];
            foreach ($fieldsByTable[$tableId] as $field) {
                if (self::isCreatableField($field, $managed[$tableId] ?? [])) {
                    $fields[self::logicalName(self::string($field, 'label'))] = self::fieldDefinition($field);
                }
            }
            $relationships = [];
            foreach ($relationshipsByTable[$tableId] as $relationship) {
                $parentId = self::string($relationship, 'parentTableId');
                $foreignKeyLabel = self::string(self::object($relationship, 'foreignKeyField'), 'label');
                $lookups = [];
                foreach (self::objects($relationship, 'lookupFields') as $lookup) {
                    $source = self::matchParentField(self::string($lookup, 'label'), $fieldsByTable[$parentId] ?? []);
                    if (null !== $source) {
                        $lookups[] = self::string($source, 'label');
                    }
                }
                $summaries = array_map(static fn (array $summary): array => ['label' => self::string($summary, 'label'), 'accumulationType' => 'COUNT'], self::objects($relationship, 'summaryFields'));
                $relationships[self::logicalName($foreignKeyLabel)] = [
                    'parentTable' => self::logicalName(self::tableName($tables, $parentId)),
                    'foreignKeyLabel' => $foreignKeyLabel,
                    'lookupFields' => $lookups,
                    'summaryFields' => $summaries,
                ];
            }
            $definitions[self::logicalName(self::string($table, 'name'))] = [
                'name' => self::string($table, 'name'),
                'singleRecordName' => self::string($table, 'singleRecordName'),
                'description' => self::nullableString($table['description'] ?? null),
                'fields' => $fields,
                'relationships' => $relationships,
            ];
        }

        return ['name' => self::string($app, 'name'), 'description' => self::nullableString($app['description'] ?? null), 'sourceAppId' => $appId, 'tables' => $definitions];
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    public function materialize(array $schema, ?string $appId = null): array
    {
        $created = $updated = [];
        if (null === $appId) {
            $app = $this->quickbase->createApp(array_filter(['name' => self::required($schema, 'name'), 'description' => self::nullableString($schema['description'] ?? null)], static fn (mixed $v): bool => null !== $v));
            $appId = self::string($app, 'id');
            $created[] = 'app:'.$appId;
        }
        $existingTables = self::indexBy($this->quickbase->tables($appId), 'name');
        $tableIds = $fieldIds = [];
        foreach (self::schemaObjects($schema, 'tables') as $logicalTable => $definition) {
            $name = self::required($definition, 'name');
            $table = $existingTables[$name] ?? null;
            if (null === $table) {
                $table = $this->quickbase->createTable($appId, array_filter(['name' => $name, 'singleRecordName' => self::required($definition, 'singleRecordName'), 'description' => self::nullableString($definition['description'] ?? null)], static fn (mixed $v): bool => null !== $v));
                $created[] = 'table:'.$name;
            }
            $tableId = self::string($table, 'id');
            $tableIds[$logicalTable] = $tableId;
            $existingFields = self::indexBy($this->quickbase->fields($tableId), 'label');
            foreach (self::schemaObjects($definition, 'fields') as $logicalField => $fieldDefinition) {
                $label = self::required($fieldDefinition, 'label');
                $field = $existingFields[$label] ?? null;
                if (null === $field) {
                    $field = $this->quickbase->createField($tableId, self::fieldCreateDefinition($fieldDefinition));
                    $created[] = sprintf('field:%s.%s', $name, $label);
                    if (array_key_exists('unique', $fieldDefinition)) {
                        $field = $this->quickbase->updateField($tableId, (int) $field['id'], ['unique' => $fieldDefinition['unique']]);
                        $updated[] = sprintf('field:%s.%s', $name, $label);
                    }
                } elseif (self::needsFieldUpdate($field, $fieldDefinition)) {
                    $field = $this->quickbase->updateField($tableId, (int) $field['id'], self::fieldUpdateDefinition($fieldDefinition));
                    $updated[] = sprintf('field:%s.%s', $name, $label);
                }
                $fieldIds[$logicalTable][$logicalField] = $fieldIds[$logicalTable][$label] = (int) $field['id'];
            }
        }
        foreach (self::schemaObjects($schema, 'tables') as $logicalTable => $definition) {
            $childId = $tableIds[$logicalTable];
            $existing = $this->quickbase->relationships($childId)['relationships'];
            foreach (self::schemaObjects($definition, 'relationships') as $relationship) {
                $parentLogical = self::required($relationship, 'parentTable');
                $parentId = $tableIds[$parentLogical] ?? throw new \LogicException(sprintf('Unknown parent table "%s".', $parentLogical));
                $label = self::required($relationship, 'foreignKeyLabel');
                if (self::hasRelationship($existing, $parentId, $label)) continue;
                $lookupIds = [];
                foreach (($relationship['lookupFields'] ?? []) as $lookupLabel) {
                    $lookupIds[] = $fieldIds[$parentLogical][$lookupLabel] ?? throw new \LogicException(sprintf('Unknown lookup field "%s.%s".', $parentLogical, $lookupLabel));
                }
                $this->quickbase->createRelationship($childId, ['parentTableId' => $parentId, 'foreignKeyField' => ['label' => $label], 'lookupFieldIds' => $lookupIds, 'summaryFields' => $relationship['summaryFields'] ?? []]);
                $created[] = sprintf('relationship:%s.%s', $logicalTable, $label);
            }
        }

        return ['appId' => $appId, 'tables' => $tableIds, 'fields' => $fieldIds, 'created' => $created, 'updated' => $updated];
    }

    /** @param array<string, list<array<string, mixed>>> $byTable @return array<string, list<string>> */
    private static function relationshipManagedLabels(array $byTable): array
    {
        $labels = [];
        foreach ($byTable as $childId => $relationships) foreach ($relationships as $relationship) {
            $labels[$childId][] = self::string(self::object($relationship, 'foreignKeyField'), 'label');
            foreach (self::objects($relationship, 'lookupFields') as $field) $labels[$childId][] = self::string($field, 'label');
            foreach (self::objects($relationship, 'summaryFields') as $field) $labels[self::string($relationship, 'parentTableId')][] = self::string($field, 'label');
        }
        return $labels;
    }

    /** @param array<string, mixed> $field @param list<string> $managed */
    private static function isCreatableField(array $field, array $managed): bool
    {
        return is_int($field['id'] ?? null) && $field['id'] > 5 && '' === ($field['mode'] ?? '')
            && 'dblink' !== ($field['fieldType'] ?? '')
            && !isset($field['properties']['parentFieldId'])
            && !in_array($field['label'] ?? null, $managed, true);
    }

    /** @param array<string, mixed> $field @return array<string, mixed> */
    private static function fieldDefinition(array $field): array
    {
        $result = ['label' => self::string($field, 'label'), 'fieldType' => self::string($field, 'fieldType')];
        foreach (['appearsByDefault', 'bold', 'fieldHelp', 'findEnabled', 'noWrap', 'addToForms', 'properties'] as $key) if (array_key_exists($key, $field)) $result[$key] = $field[$key];
        return $result;
    }

    /** @param list<array<string, mixed>> $fields @return array<string, mixed>|null */
    private static function matchParentField(string $lookupLabel, array $fields): ?array
    {
        foreach ($fields as $field) {
            $label = $field['label'] ?? null;
            if ($label === $lookupLabel || (is_string($label) && str_ends_with($lookupLabel, ' - '.$label))) return $field;
        }
        return null;
    }

    /** @param list<array<string, mixed>> $tables */
    private static function tableName(array $tables, string $id): string
    {
        foreach ($tables as $table) if (($table['id'] ?? null) === $id) return self::string($table, 'name');
        throw new \UnexpectedValueException(sprintf('Unknown related table "%s".', $id));
    }

    private static function logicalName(string $label): string { return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $label), '_')); }

    /** @param list<array<string, mixed>> $objects @return array<string, array<string, mixed>> */
    private static function indexBy(array $objects, string $key): array
    {
        $result = [];
        foreach ($objects as $object) $result[self::string($object, $key)] = $object;
        return $result;
    }

    /** @param array<string, mixed> $schema @return array<string, array<string, mixed>> */
    private static function schemaObjects(array $schema, string $key): array
    {
        $value = $schema[$key] ?? [];
        if (!is_array($value)) throw new \InvalidArgumentException(sprintf('Schema "%s" must be an object.', $key));
        return $value;
    }

    /** @param list<array<string, mixed>> $relationships */
    private static function hasRelationship(array $relationships, string $parentId, string $label): bool
    {
        foreach ($relationships as $relationship) if (($relationship['parentTableId'] ?? null) === $parentId && ($relationship['foreignKeyField']['label'] ?? null) === $label) return true;
        return false;
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $desired */
    private static function needsFieldUpdate(array $existing, array $desired): bool
    {
        foreach (self::fieldUpdateDefinition($desired) as $key => $value) {
            $actual = $existing[$key] ?? null;
            if (is_array($value) && is_array($actual)) {
                if (self::needsSubsetUpdate($actual, $value)) return true;
            } elseif ($actual !== $value) {
                return true;
            }
        }
        return false;
    }

    /** @param array<array-key, mixed> $actual @param array<array-key, mixed> $desired */
    private static function needsSubsetUpdate(array $actual, array $desired): bool
    {
        foreach ($desired as $key => $value) {
            if (!array_key_exists($key, $actual)) return true;
            if (is_array($value) && is_array($actual[$key])) {
                if (self::needsSubsetUpdate($actual[$key], $value)) return true;
            } elseif ($actual[$key] !== $value) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private static function fieldUpdateDefinition(array $definition): array { unset($definition['fieldType']); return $definition; }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private static function fieldCreateDefinition(array $definition): array { unset($definition['unique']); return $definition; }

    /** @param array<string, mixed> $object */
    private static function string(array $object, string $key): string
    {
        $value = $object[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) throw new \UnexpectedValueException(sprintf('Quickbase response requires string "%s".', $key));
        return $value;
    }

    /** @param array<string, mixed> $object */
    private static function required(array $object, string $key): string
    {
        $value = $object[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) throw new \InvalidArgumentException(sprintf('Schema requires string "%s".', $key));
        return $value;
    }

    private static function nullableString(mixed $value): ?string { return is_string($value) && '' !== trim($value) ? $value : null; }

    /** @param array<string, mixed> $object @return array<string, mixed> */
    private static function object(array $object, string $key): array
    {
        $value = $object[$key] ?? null;
        if (!is_array($value)) throw new \UnexpectedValueException(sprintf('Quickbase response requires object "%s".', $key));
        return $value;
    }

    /** @param array<string, mixed> $object @return list<array<string, mixed>> */
    private static function objects(array $object, string $key): array
    {
        $value = $object[$key] ?? [];
        if (!is_array($value)) throw new \UnexpectedValueException(sprintf('Quickbase response requires list "%s".', $key));
        return $value;
    }
}
