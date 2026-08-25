# ADR 0001 — Provider-neutral application-backed record stores

- **Status:** Accepted; initial vertical slice implemented
- **Date:** 2026-08-25
- **Scope:** `quickbase-bundle`, `record-store-bundle`, its Grist adapter, and domain bundles such as a future Contacts bundle.
- **Trigger:** The Quickbase integration now needs reusable schema and record tooling, while self-hosted Grist offers the same basic application/table/record shape for Survos-owned projects.

## Decision summary

Build a provider-neutral application-backed record-store layer, while retaining complete provider-specific clients.

- A logical **application** maps to a Quickbase app or a Grist document.
- Domain bundles own application blueprints and domain repository contracts.
- Provider adapters project those blueprints and record operations onto Quickbase or Grist.
- Portable contracts cover catalog discovery, schema inspection, schema reconciliation, basic record queries, writes, and upserts.
- Provider-specific operations remain available through `QuickbaseClientInterface` and a future `GristClientInterface`.
- The existing `survos/quickbase-bundle` remains backward compatible. It must not be silently renamed or made to expose non-Quickbase semantics through its existing client interface.
- Schema changes are diff-first and non-destructive by default. User-added fields are reported as unmanaged, not deleted.

The provider-neutral package is `survos/record-store-bundle`. Mono's existing `data-bundle`, `dataset-bundle`, `grid-bundle`, and `google-sheets-bundle` retain their different responsibilities.

## Context

### Current Quickbase work

The initial Quickbase bundle is deliberately provider-specific. It has:

- a Symfony scoped HTTP client with Quickbase authentication and retry behavior;
- named application, table, and field configuration;
- metadata inspection for tables, fields, and relationships;
- record querying and upserting;
- table, field, and relationship creation primitives;
- read-only console commands used successfully against the Lions app.

That remains the correct raw Quickbase API boundary. Its current types expose Quickbase concepts directly: realm headers, app and table IDs, numeric field IDs, Quickbase query expressions, merge field IDs, and raw response metadata. Those types must not become the provider-neutral contracts.

Lions and Rapp at Home must continue using Quickbase because the service is provided and paid for by `rrregion.org`. The abstraction is not a migration away from Quickbase.

### Grist opportunity

The local Docker environment runs `gristlabs/grist-oss` on localhost with persistent storage. Existing Pokedex and PGSC experiments exercise tables, reference columns, formulas, attachments, and custom display behavior.

Grist is a relational spreadsheet with an API for organizations, workspaces, documents, tables, columns, records, attachments, webhooks, and read-only SQL. The current public REST reference calls itself **Grist API 1.0.1** and uses unversioned `/api/...` paths. Grist software is 2.x; SCIM's `/v2` path is unrelated to the document and record API.

References:

- <https://support.getgrist.com/api/>
- <https://support.getgrist.com/rest-api/>
- <https://github.com/gristlabs/grist-core>
- <https://github.com/gristlabs/grist-core/blob/main/documentation/grist-data-format.md>

Grist is especially attractive for Survos-owned small applications because it supplies both persistence and a human-editable back office: grids, cards, forms, formulas, summaries, references, dashboards, and attachments.

### The Contacts example

A Contacts bundle could define an application consisting of:

- Contacts
- Organizations
- Addresses
- Groups or tags
- Contact activities

The application could be provisioned as a Grist document for an internal deployment or, where required, as a Quickbase app. Symfony code would use domain repositories rather than provider APIs. Nontechnical users would work in the provider's native interface without Survos rebuilding routine CRUD screens.

No current `contacts-bundle` checkout was found under `~/sites/mono`, `~/sites`, or `~/tacman`. The Contacts case is therefore a proposed proof of concept, not a commitment to migrate existing implementation code.

## Shared conceptual model

| Neutral concept | Quickbase | Grist |
|---|---|---|
| Connection | Realm and user token | Host and API key or OAuth token |
| Application | App | Document within a workspace and organization |
| Table | Table | Table |
| Field | Numeric field ID plus label/type | String column ID plus label/type |
| Relationship | Relationship resource with lookup/summary fields | `Ref:` or `RefList:` column, often with formula columns |
| Record identity | Record ID field | Integer row ID |
| Upsert identity | Merge field | `require` field set |
| Human interface | Quickbase views/forms/workflows | Grist pages/widgets/forms/formulas |

This alignment is strong enough for a shared operational core. It is not strong enough to make all provider features interchangeable.

## Architecture

### 1. Provider-neutral core

The neutral layer should use small, capability-oriented contracts rather than one large client:

```php
interface CatalogReaderInterface {}
interface SchemaReaderInterface {}
interface SchemaWriterInterface {}
interface RecordReaderInterface {}
interface RecordWriterInterface {}
interface AttachmentStoreInterface {}
interface WebhookManagerInterface {}
interface ApplicationProvisionerInterface {}
```

The exact method signatures should be designed with typed models and tested against both providers before being stabilized.

Expected shared models include:

- `ConnectionName`
- `ApplicationReference`
- `TableReference`
- `FieldReference`
- `ApplicationSchema`
- `TableSchema`
- `FieldSchema`
- `RelationshipSchema`
- `Record`
- `RecordQuery`
- `Filter`
- `Sort`
- `RecordPage`
- `UpsertRequest`
- `WriteResult`
- `ProviderCapabilities`

References should carry stable logical names. Provider IDs belong in adapter mappings and provider metadata, not in domain services.

### 2. Application blueprints

A domain bundle may declare a portable application blueprint:

```php
interface ApplicationBlueprintInterface
{
    public function name(): string;

    public function version(): string;

    public function schema(): ApplicationSchema;
}
```

The blueprint owns semantic intent:

- stable table and field codes;
- portable field types;
- required fields and uniqueness intent;
- relationships;
- provider-neutral labels and descriptions;
- schema version.

It does not own credentials or physical provider IDs.

Provider-specific options must be possible without contaminating the portable core. Examples include Quickbase lookup/summary fields and Grist formulas, widget options, visible columns, and on-demand tables. A controlled `providerOptions` extension slot is preferable to pretending those features are portable.

### 3. Provider adapters and raw clients

Adapters translate the portable models into native operations:

```text
Domain repository
    -> provider-neutral record-store contract
        -> named connection registry
            -> QuickbaseAdapter -> QuickbaseClientInterface
            -> GristAdapter     -> GristClientInterface
```

Raw clients remain complete and provider-specific:

- `QuickbaseClientInterface` keeps Quickbase-native query, metadata, and schema operations.
- `GristClientInterface` should expose Grist-native organizations, workspaces, documents, tables, columns, records, SQL, attachments, webhooks, and low-level actions where needed.

An adapter may implement only the capability contracts it supports. Consumers must be able to inspect capabilities and receive a clear unsupported-operation exception rather than silent approximation.

### 4. Domain repositories, not an ORM imitation

The record-store layer must not pretend to be Doctrine's `EntityManager`.

Domain bundles should expose repositories appropriate to their model:

```php
interface ContactRepositoryInterface
{
    public function get(string|int $id): Contact;

    public function search(ContactCriteria $criteria): ContactPage;

    public function save(Contact $contact): Contact;
}
```

A record-store-backed repository maps domain DTOs to logical field codes. This keeps provider details out of controllers and workflow services while acknowledging that remote low-code stores do not provide Doctrine transactions, identity maps, lazy loading, arbitrary joins, or database-level invariants.

Business invariants belong in domain/application services and must be validated before writes.

## Query boundary

Only a deliberately small query language should be portable initially:

- equality;
- inclusion in a list;
- conjunction;
- sort by one or more fields;
- limit;
- a provider-supported continuation or offset mechanism when available.

Quickbase has a rich native query expression with field IDs and `skip`/`top`. Grist's normal record endpoint supports equality-list filters, sorting, and a limit, while its separate SQL endpoint supports parameterized read-only SQLite queries.

Therefore:

- the generic layer must not accept a raw provider query string;
- adapters compile portable filters when possible;
- unsupported portable filters fail explicitly;
- advanced callers use the provider-specific client, such as Quickbase query syntax or Grist SQL;
- pagination must not claim semantics a provider cannot guarantee.

## Schema ownership and reconciliation

Schemas will be edited both by code and by humans in the provider UI. The reconciler must distinguish:

- **managed:** declared by the application blueprint;
- **unmanaged:** exists remotely but is not declared;
- **missing:** declared but absent remotely;
- **changed:** logical field exists but its compatible properties differ;
- **conflicting:** a change cannot be applied safely or automatically.

Default behavior:

1. inspect the remote schema;
2. calculate and display a deterministic diff;
3. perform no writes unless explicitly requested;
4. apply additive and demonstrably safe changes;
5. never delete unmanaged fields automatically;
6. require explicit confirmation for destructive or lossy changes.

Each managed application should record the blueprint name and version in provider metadata or a small reserved metadata table. The mechanism must not prevent people from adding useful local columns.

Schema inspection should be cached with explicit invalidation after bundle-managed schema writes. Admin navigation must never trigger a remote schema call on every request.

## Configuration direction

Illustrative configuration:

```yaml
survos_record_store:
    connections:
        lions_quickbase:
            driver: quickbase
            realm: '%env(QUICKBASE_REALM)%'
            token: '%env(QUICKBASE_API_KEY)%'

        internal_grist:
            driver: grist
            base_uri: '%env(GRIST_BASE_URI)%'
            token: '%env(GRIST_API_KEY)%'

    applications:
        lions:
            connection: lions_quickbase
            remote_id: bwa6visdy
            blueprint: App\Quickbase\LionsBlueprint

        contacts:
            connection: internal_grist
            remote_id: '%env(GRIST_CONTACTS_DOC_ID)%'
            blueprint: Survos\ContactsBundle\Schema\ContactsBlueprint
```

Applications are named independently of connections. Several applications may use one connection, and one Symfony project may use both providers concurrently.

## Symfony services and commands

Likely shared services:

- named connection/application registry;
- adapter factory/provider, following the established `search-bundle` pattern;
- schema inspector and cache;
- schema differ;
- guarded schema applier;
- generic record mapper;
- diagnostics service;
- optional admin schema controller/components later.

Likely shared commands:

```text
record-store:connections
record-store:applications
record-store:schema <application>
record-store:schema:diff <application>
record-store:schema:apply <application> --dry-run
record-store:records:query <application.table>
record-store:records:upsert <application.table>
```

Provider-specific commands remain namespaced, for example `quickbase:*` and `grist:*`, when their inputs or output are inherently native.

## Packaging and compatibility

### Proposed direction

Create `survos/record-store-bundle` for the neutral runtime and its models. It must use `survos/kit-bundle` and follow `bu/AGENTS.md`.

There are two viable adapter packaging arrangements:

1. Keep Quickbase and Grist adapters in the neutral bundle initially because both use Symfony HttpClient and have no heavy SDK dependency.
2. Keep adapters in provider packages, with the neutral bundle containing only contracts, models, registry, commands, and orchestration.

The second arrangement has cleaner release and dependency boundaries. The first is smaller operationally. This should be decided during a minimal two-adapter spike rather than abstractly.

Regardless of adapter packaging:

- `survos/quickbase-bundle` keeps its package and namespace;
- current Quickbase services and commands remain available;
- PriceIt's working Quickbase path must remain covered by integration tests;
- the Quickbase bundle may depend on the neutral bundle and register a Quickbase adapter;
- deprecation of any existing public API requires a separate decision and migration period.

## Security and operations

- API keys stay in environment-backed secrets.
- Grist's current localhost deployment has authentication disabled and is suitable only for local development.
- A shared or production Grist deployment requires real authentication, access rules appropriate for the data, backups, restore testing, and a pinned container version rather than `latest`.
- Contacts can contain personally identifiable information. Native Grist access and Symfony authorization must be designed together; a single owner API key must not accidentally give every Symfony user unrestricted access.
- Retry policies must distinguish safe reads and idempotent writes from unsafe repeated mutations.
- Provider request identifiers and useful error metadata should survive adapter translation.

## Native UI and admin integration

The first value of a Grist-backed application is that Grist already supplies the back office. The Symfony bundle should initially link to or embed provider-native applications rather than reproduce their editing UI.

A later optional admin integration can provide:

- configured application list;
- cached schema browser;
- schema drift badges;
- links to the native Quickbase or Grist application;
- connection health and last-refresh information.

Provider layouts, forms, views, dashboards, formulas, and workflows are not initially portable. Blueprints may eventually declare presentation hints, but generating identical Quickbase and Grist user experiences is a non-goal.

## Alternatives considered

### Keep entirely separate Quickbase and Grist bundles

This is straightforward but duplicates named connection handling, schema models, schema diff/apply logic, caching, diagnostics, generic record mapping, commands, and admin schema UI. It also prevents domain bundles from selecting storage by configuration.

**Rejected as the long-term architecture.** Provider-specific clients still remain separate boundaries.

### Make `QuickbaseClientInterface` generic

The interface already exposes legitimate Quickbase concepts. Generalizing it would either break compatibility or produce misleading method contracts.

**Rejected.** Add a neutral contract above it.

### Put Grist directly into `survos/quickbase-bundle`

This would make the package name and configuration dishonest and couple unrelated provider releases without a clear neutral model.

**Rejected.** Preserve Quickbase identity and add a neutral orchestration layer.

### Replace Doctrine transparently

Quickbase and Grist lack the transactional and ORM semantics expected by Doctrine consumers. A transparent replacement would fail in subtle ways.

**Rejected.** Domain repository contracts make the storage boundary explicit.

### Generalize every provider feature

Quickbase formulas, relationships, views, and workflows differ materially from Grist formulas, references, widgets, forms, and SQL.

**Rejected.** Portable core plus typed provider escape hatches.

## Incremental implementation plan

### Phase 1 — Contract spike

- Choose the final neutral package and namespace name.
- Implement only application/table/field/record references, schema reading, basic record reading, and upsert contracts.
- Build both adapters far enough to run the same contract tests against mocked provider responses.
- Do not implement schema mutation or UI yet.

### Phase 2 — Quickbase compatibility adapter

- Wrap the existing `QuickbaseClientInterface` without changing it.
- Adapt current named app/table/field mappings.
- Move PriceIt's `app:push` workflow onto a domain or neutral record writer.
- Verify that Lions behavior and stored Quickbase record IDs remain unchanged.

### Phase 3 — Grist vertical slice

- Add a scoped Grist HTTP client and native API interface.
- Add Grist document/table/column discovery and record query/upsert.
- Test live against the local Docker Grist instance.
- Provision one disposable demonstration application with at least two related tables.

### Phase 4 — Blueprint and schema reconciliation

- Introduce `ApplicationBlueprintInterface`.
- Normalize portable field types and relationships.
- Implement schema inspection and deterministic diff.
- Add explicit, additive-first apply behavior.
- Add cache invalidation and schema-version recording.

### Phase 5 — Contacts proof of concept

- Create or locate the Contacts domain bundle.
- Define Contacts and Organizations first; avoid modeling every CRM feature.
- Implement a Grist-backed `ContactRepositoryInterface`.
- Verify both Symfony workflow access and native Grist editing.
- Evaluate identity, authorization, search, and schema-drift behavior with realistic contact data.

### Phase 6 — Optional administration UI

- Add cached schema display and application links to `ADMIN_NAVBAR`.
- Show capabilities and schema drift.
- Keep remote calls out of request-time navbar construction.

## Acceptance criteria for the abstraction

The abstraction is justified only if all of the following are true:

1. One domain-level upsert test runs unchanged against both Quickbase and Grist adapters.
2. One schema blueprint can describe two related tables for both providers without containing provider IDs.
3. Provider-specific features remain accessible without weakening the neutral contracts.
4. Existing Quickbase consumers continue working without source changes.
5. Unmanaged remote fields survive schema reconciliation.
6. Unsupported query or schema behavior fails clearly.
7. The neutral layer contains no Quickbase query strings, numeric-field assumptions, Grist SQL, or Grist column-type encoding.

## Open decisions

- Final package name: `record-store-bundle`, `application-store-bundle`, or another name that does not collide with existing Survos data/grid packages.
- Whether adapters live in the neutral package or provider packages.
- The minimum portable field-type vocabulary.
- How logical field codes map to existing Quickbase numeric field IDs and renamed Grist columns.
- Whether each logical application always maps to exactly one Quickbase app or Grist document.
- How schema versions are recorded without making the metadata intrusive to native users.
- Whether generic events should complement provider webhooks.
- How user identity and row-level authorization flow through Symfony to native provider access.
- Whether offline/read-through caching is ever appropriate for records, as distinct from schema metadata.
- Whether the older `google-sheets-bundle` should eventually implement a limited subset of the same contracts.

## Non-goals

- Replacing Quickbase for Lions or Rapp at Home.
- Replacing Doctrine for transactional application data.
- Making Quickbase and Grist formulas or user interfaces identical.
- Automatically deleting remote schema elements.
- Building a generic visual application designer.
- Hiding provider capabilities behind lossy behavior.

## Consequences

### Benefits

- Domain bundles can offer a real, editable application without requiring Doctrine tables and custom CRUD administration.
- Quickbase remains usable where externally required; Grist becomes a self-hosted option for Survos-controlled deployments.
- Schema, record, caching, diagnostics, and admin tooling are implemented once.
- The current Quickbase investment becomes the first adapter rather than throwaway code.
- Contacts provides a concrete, bounded proof rather than designing only from abstract API symmetry.

### Costs and risks

- A new abstraction and compatibility layer must be maintained.
- Type normalization and relationship semantics can become over-generalized if the first slice is too broad.
- Provider UIs permit schema drift; reconciliation needs unusually conservative defaults.
- Remote application stores have different consistency, query, identity, and authorization behavior from relational databases.
- Supporting more providers can encourage lowest-common-denominator APIs unless capability contracts remain narrow.

## Recommendation

Proceed with the architecture, but only through the staged two-adapter spike. Preserve the working Quickbase client, extract a narrow provider-neutral record and schema-reading boundary above it, and prove that boundary with the local Grist instance before implementing schema application, Contacts, or admin UI.
