# Quickbase to Grist

One-directional: read a Quickbase application, produce the equivalent Grist document. There is no
Grist-to-Quickbase path here and none is planned; where a partner requires Quickbase, the
integration is a connector pushing into Grist or WordPress, which is separate work.

```bash
# Review first. Writes nothing.
bin/console quickbase:to-grist lions-ai --dry-run

# Schema only, into a new document.
bin/console quickbase:to-grist lions-ai --workspace=3

# Schema and rows, into a document that already exists.
bin/console quickbase:to-grist lions-ai --doc=6xcrwpm9DWyA4WRUiQZEHu --with-data
```

| Option | |
|---|---|
| `--doc` | existing Grist document id; omit to create one |
| `--workspace` | Grist workspace to create the new document in (`GET /api/orgs/{org}/workspaces`) |
| `--connection` | record-store connection with driver `grist`; inferred when only one is configured |
| `--dry-run` | print the plan and the type decisions, write nothing |
| `--with-data` | copy rows as well as schema |
| `--tables` | comma-separated Quickbase table names or ids |
| `--skip-derived` | drop lookup and summary fields instead of freezing their current value |
| `--json` | emit the plan as JSON, and nothing else, so it can be diffed between runs |

## How it goes across

`QuickbaseAdapter::schema()` produces a normalized `ApplicationSchema`; `GristColumnType` maps each
`FieldType` back out to a Grist column type. Both live behind
`Survos\RecordStore\Contract\RecordStoreAdapterInterface`, so this is the record-store seam rather
than a bespoke pipeline.

Rows move in three passes, because a Grist row id does not exist until the row does:

1. **values** — everything a row holds on its own
2. **references** — Quickbase's parent record id translated to the Grist row id it became
3. **attachments** — bytes out of Quickbase, into Grist's attachment store

### QbRecordId

Every table gets a `QbRecordId` column holding Quickbase's `Record ID#`. It is the natural key: it
already exists and it is permanent, so nothing here invents or hashes an id. All three passes upsert
on it, which is what makes the import re-runnable — and it has to be, because Grist has no
transaction and no save button. Every batch commits as it arrives, so a run that dies half way is
resumed by running it again.

Batches are sized by **payload bytes**, not row count. Grist answers 413 well below 500 rows when
the rows are wide, and batching on row count is the bug that looks like a Grist outage.

## Where it is lossy

`--dry-run` lists every one of these explicitly, per field. A conversion that quietly drops a column
is worse than one that refuses.

| Quickbase | Becomes | |
|---|---|---|
| `text-multiple-choice` | `Choice` | allowed values come from the field properties |
| `multitext` | `ChoiceList` | |
| reference (`numeric` + `foreignKey`) | `Ref:Table` | filled in pass 2; falls back to the raw record id, reported, when the target table is out of scope |
| formula | the computed value, as a plain column | **static from then on.** Grist formulas are Python; no translation is attempted |
| lookup | a static copy | `$Ref.Column` in Grist restores it live, if you want that |
| summary | a static number | a Grist summary table recomputes it |
| `file` | `Attachments` | one round trip per file, so it is slow |
| `timeofday` | `Text` | Grist has no time-only type |
| `duration` | `Numeric` | the unit survives only in the label |
| `user` | — | **not converted.** Grist has no user column type |
| `dblink` | — | **not converted.** A report link holds no data; the child table's reference column is the same relationship |
| `required` / `unique` | — | Grist has no column constraints; nothing enforces them afterwards |

## What does not come across at all

`--dry-run` also reports the application surface, grouped by what can be done about it: **rebuild in
Grist**, **build a custom widget**, or **no equivalent**. Reports are enumerated per table
from the REST API. Forms, roles, dashboards and pipelines come from the QBL solution export, which
needs the Solutions API — an Enterprise entitlement. Without it the report says so rather than
listing nothing, because an empty list reads as "this application has no forms".

Kanban, Gantt and map reports have no Grist view, but they do not have to leave the document: a
[custom widget](https://support.getgrist.com/widget-custom/) is any URL added to a Grist page,
reading and writing the selected records through the Plugin API. So those become a component to
write, not a capability lost.

The honest weak spot is **Pipelines**. Quickbase ships hosted connectors, retries and run history;
Grist has one outgoing webhook per table and nothing else in that column. Those integrations get
rewritten as application code — a Grist webhook into a `Survos\Kit\Webhook` receiver, with Messenger
doing the scheduling Pipelines was doing. Budget for it separately from the data move.
