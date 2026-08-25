# survos/quickbase-bundle

Symfony 8.1 integration for the [Quickbase JSON REST API](https://developer.quickbase.com/).
The first vertical slice is record upsert, intended for publishing completed PriceIt workflow data.

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

`QuickbaseClientInterface` also exposes typed methods for record queries and for creating tables,
fields, and relationships. Schema mutations are intentionally API-only for now; a future CLI will
apply a declarative schema diff rather than issuing unreviewed one-off changes.
