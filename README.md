# survos/quickbase-bundle

Symfony 8.1 integration for the [Quickbase JSON REST API](https://developer.quickbase.com/).
It supports record access, application schema inspection and materialization, and Quickbase Language
(QBL) solution management for forms, form rules, roles, reports, and dashboards.

## Install

```bash
composer require survos/quickbase-bundle
```

```dotenv
QUICKBASE_REALM=example.quickbase.com
QUICKBASE_API_KEY=your-user-token
```

```yaml
# config/packages/survos_quickbase.yaml
survos_quickbase:
    realm: '%env(QUICKBASE_REALM)%'
    token: '%env(QUICKBASE_API_KEY)%'
    apps:
        lions:
            id: bwa6visdy
            tables:
                inventory:
                    id: bwa6visd6
                    fields:
                        record_id: 3
                        sku: 6
                        name: 15
        rah:
            id: bv4hfi7e8
```

Inject `QuickbaseClientInterface` into the PriceIt workflow completion handler. Records may use
Quickbase's native field-value shape or a concise field-id-to-value map:

```php
$result = $quickbase->upsertRecords(
    tableId: 'bxxxxxxxx',
    records: [[6 => 'SKU-123', 7 => 19.95, 8 => 'complete']],
    mergeFieldId: 6,
    fieldsToReturn: [3, 6, 7, 8],
);
```

The scoped HTTP client supplies the realm, authorization, content type, user agent, retry policy,
and base URI. API failures throw `QuickbaseApiException`, including Quickbase's `qb-api-ray`
request identifier when available.

## Provider-neutral record access

The bundle also contributes a Quickbase adapter to `survos/record-store-bundle`. Keep credentials
and the provider-native catalog in `survos_quickbase`, then map stable application, table, and field
names for domain code:

```yaml
survos_record_store:
    connections:
        quickbase:
            driver: quickbase
    applications:
        lions:
            connection: quickbase
            id: bwa6visdy
            tables:
                inventory:
                    id: bwa6visd6
                    fields:
                        sku: 6
                        name: 15
```

Use `RecordStoreRegistry` for portable schema reads, basic record queries, and upserts. Continue to
use `QuickbaseClientInterface` for Quickbase query expressions and provider-specific schema work.

## Explore an app

```bash
bin/console quickbase:apps
bin/console quickbase:tables lions
bin/console quickbase:tables APP_ID --json
bin/console quickbase:fields lions.inventory
bin/console quickbase:fields TABLE_ID --permissions --json
bin/console quickbase:relationships lions.inventory
bin/console quickbase:query lions.inventory --select=3,6,15 --top=20
bin/console quickbase:query TABLE_ID --where="{'6'.EX.'SKU-123'}" --json
```

These commands are read-only. They use the same scoped client and credentials as application code.

## Materialize application schema

REST-manageable schema can be exported to portable JSON and applied idempotently. The materializer
matches tables and fields by stable names, creates relationships after both tables exist, and updates
changed field properties without deleting data.

```bash
bin/console quickbase:schema:snapshot lions var/lions.schema.json
bin/console quickbase:schema:materialize var/lions.schema.json --app=lions --yes

# Omit --app to create the application as well as its tables.
bin/console quickbase:schema:materialize var/lions.schema.json --yes
```

`QuickbaseClientInterface` exposes the corresponding typed app, table, field, relationship, record,
and solution lifecycle methods. Its public `request()` method remains the forward-compatible escape
hatch for newly released JSON endpoints.

## Forms and complete solution schema

Quickbase does not expose forms through its ordinary app/table REST resources. Forms, form rules,
roles, reports, and dashboards are solution resources managed with QBL. The bundle treats exported
QBL as the authoritative representation for those objects:

```bash
# Export the exact live solution, including forms.
bin/console quickbase:qbl:export SOLUTION_ID var/solution.qbl.yaml

# Build QBL from JSON/PHP-friendly data. Native references are represented as
# {"!Ref": {"Field": "$Field_Photo"}} in JSON.
bin/console quickbase:qbl:build resources/solution.json var/solution.qbl.yaml

# Preview first, then apply the reviewed document.
bin/console quickbase:qbl:changes SOLUTION_ID var/solution.qbl.yaml
bin/console quickbase:qbl:apply SOLUTION_ID var/solution.qbl.yaml --yes
```

Application code can use `QblDocument::fromArray($definition)->toYaml()` directly. Unknown resource
types and properties are preserved so a Quickbase QBL release does not force an immediate bundle
upgrade.

Solution APIs must be enabled by a realm administrator. The application must belong to a Solution,
and the token user must own or contribute to that Solution. These are Quickbase authorization
requirements; app-manager access alone is not sufficient.

## Destructive operations

App deletion requires both the app's ID and exact current name. The command fetches and verifies the
name first, and does nothing unless `--yes` is supplied:

```bash
bin/console quickbase:app:delete APP_ID 'Exact App Name' --yes
```
