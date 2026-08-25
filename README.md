# survos/quickbase-bundle

Symfony 8.1 integration for the [Quickbase JSON REST API](https://developer.quickbase.com/).
The first vertical slice is record upsert, intended for publishing completed PriceIt workflow data.

## Install

```bash
composer require survos/quickbase-bundle
```

```dotenv
QUICKBASE_REALM=example.quickbase.com
QUICKBASE_USER_TOKEN=your-user-token
```

```yaml
# config/packages/survos_quickbase.yaml
survos_quickbase:
    realm: '%env(QUICKBASE_REALM)%'
    token: '%env(QUICKBASE_USER_TOKEN)%'
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
