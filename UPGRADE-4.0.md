# Upgrade from v3.x to v4.0

v4.0 removes the deprecated database attribute names that v3.x dual-emitted next to their stable replacements. **There are no API or configuration changes for your application code.** If your dashboards, alerts and saved queries already use the stable names, nothing to do.

## Database span attributes

Since 3.0 every Doctrine span carried each database attribute twice, under the stable name and under the name the conventions used before they stabilized. 4.0 emits the stable name only.

| Removed (v3.x dual-emitted) | Use instead | Value changes |
|---|---|---|
| `db.system` | `db.system.name` | `mssql` is `microsoft.sql_server`, `oracle` is `oracle.db`, `db2` is `ibm.db2`; other values are unchanged |
| `db.statement` | `db.query.text` | none (still only present with `traces.doctrine.record_statements: true`) |
| `db.operation` | `db.operation.name` | none |
| `db.name` | `db.namespace` | none |

Grep your backend for the four removed keys and rename them. This is the step the [OpenTelemetry database migration guide](https://opentelemetry.io/docs/specs/semconv/non-normative/db-migration/) describes for instrumentations once the conventions are stable: drop the old names in the next major release.

Why now: each old name repeats a value already on the span, and `db.statement` repeats the entire SQL text, so a span with SQL recording on was twice its necessary size on the wire and in storage.

## Flat v1 configuration keys are still accepted

Earlier upgrade guides scheduled the removal of the flat v1 config keys (`traces_enabled`, `doctrine_record_statements`, ...) for 4.0. They remain accepted in 4.0 with the same deprecation notice and are now scheduled for 5.0. The mapping to the nested form is in [UPGRADE-2.0.md](UPGRADE-2.0.md#flat--nested-mapping).

## Removed PHP API

- `Traceway\OpenTelemetryBundle\Doctrine\Middleware\DbSystemResolver::legacyValue()` had no purpose without the old attribute.

## New in 4.0

Not breaking, but worth knowing when you tune volume:

- `traces.doctrine.max_spans_per_trace` caps the DB spans one trace may carry; past the cap the statements run untraced and the parent span reports the count in `traceway.db.spans_dropped`.
- `traces.messenger.excluded_messages` removes whole message classes from tracing.

See [docs/performance.md](docs/performance.md#keeping-span-volume-in-check).
