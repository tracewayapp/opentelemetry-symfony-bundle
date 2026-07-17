# Semantic Conventions

## Conformance

The bundle is audited against the [OTel semantic conventions](https://opentelemetry.io/docs/specs/semconv/) per instrumentation:

- **Every stable MUST, Required, and Conditionally-Required rule is implemented.** That includes the details most instrumentations skip: `_OTHER` method normalization, `url.full` credential/query redaction, default-port inference, `error.type` on every failure path (including throwing accessors and cancellations), stable `db.system.name` values, SQLSTATE as `db.response.status_code`, and the per-signal histogram bucket advisories.
- **Recommended attributes are emitted wherever the data exists** — e.g. `network.peer.address`/`port` on server spans, `network.protocol.version` on spans and duration/body-size metrics.
- **Handled 4xx responses are not errors on SERVER spans** (spec: status "MUST be left unset" for 4xx on `SpanKind.SERVER`). The exception event is still recorded; 5xx and unhandled exceptions set Error with `error.type`. Client spans mark 4xx/5xx as errors, per their own rule. `traces.error_status_threshold` only accepts values >= 500 so configuration cannot violate the MUST.
- **`http.route` is never a raw path** — when no low-cardinality template can be resolved, the attribute is omitted, per spec.
- **Server metrics omit `server.address`/`server.port`** — they are Opt-In in the spec because the Host header is client-controlled (cardinality-attack vector).
- Messaging conventions are **Development status** upstream; the bundle tracks the current metric names and dual-emit hooks are in place for future renames.

## Deliberate deviations

Chosen so task-oriented backends group telemetry usefully; all are spec-permitted:

| Where | Spec says | We do | Why |
|---|---|---|---|
| Messenger span name | `send {transport}` | `send {MessageClass}` | Tasks group per message type, not per queue |
| Console span name | `{process.executable.name}` | the command name | `app:import` beats `php` (allowed low-cardinality alternative) |
| Consumer parenting | span links by default | parent-child (links with `root_spans: true`) | end-to-end traces out of the box |
| `db.system`, `db.statement`, … | deprecated | dual-emitted alongside the stable keys | migration aid for older backends; removal planned for v4.0 |
| `db.query.text` | sanitize, don't collect by default | **off by default**; opt-in via `traces.doctrine.record_statements: true` records verbatim (prepared statements are placeholder-safe) | opt-in is spec-sanctioned (MAY) |
| `messaging.system` | well-known broker list | `symfony_messenger` / `symfony_mailer` / `symfony_scheduler` | custom values are explicitly allowed |

Custom attributes (`console.command`, `cache.*`, `twig.*`, `scheduler.*`, `messaging.message.class`, `traceway.distributed_trace_id`) cover areas with no registered convention yet.

## Known limitations

- `framework.http_client.scoped_clients` keep their `base_uri` inside Symfony's `ScopingHttpClient`, invisible to the decorators — on the rare pre-transport failure of a relative-URL request, those clients miss `server.address`/`url.full` (successful and transport-failed requests are unaffected via effective-URL enrichment).
- `db.collection.name` is omitted for JOINs (per spec: single-collection operations only), but legacy comma-joins (`FROM a, b`) can still slip a name through — a full SQL parser is out of scope.
- Per-attempt retry spans with `http.request.resend_count` are not emitted; retries through `RetryableHttpClient` appear as one CLIENT span. Planned as an opt-in feature (span-volume implications).
- `db.stored_procedure.name` is not extracted from `CALL` statements.
