# Semantic Conventions

## Conformance

The bundle conforms to **semantic conventions 1.38.0** (`open-telemetry/sem-conv` 1.38.0, its `Attributes\*` classes). Every tracer and meter reports `https://opentelemetry.io/schemas/1.38.0` as its instrumentation-scope schema URL, and `OpenTelemetryBundleTest` holds that value, so moving to a newer release is a deliberate step: bump the constant in `OpenTelemetryBundle`, re-run the audit below, update this line.

The bundle is audited against the [OTel semantic conventions](https://opentelemetry.io/docs/specs/semconv/) per instrumentation:

- **Every stable MUST, Required, and Conditionally-Required rule is implemented.** That includes the details most instrumentations skip: `_OTHER` method normalization, `url.full` credential/query redaction, default-port inference, `error.type` on every failure path (including throwing accessors and cancellations), stable `db.system.name` values, SQLSTATE as `db.response.status_code`, and the per-signal histogram bucket advisories.
- **Recommended attributes are emitted wherever the data exists** — e.g. `network.peer.address`/`port` on server spans, `network.protocol.version` on spans and duration/body-size metrics.
- **Handled 4xx responses are not errors on SERVER spans** (spec: status "MUST be left unset" for 4xx on `SpanKind.SERVER`). The exception event is still recorded; 5xx and unhandled exceptions set Error with `error.type`. Client spans mark 4xx/5xx as errors, per their own rule. `traces.error_status_threshold` only accepts values >= 500 so configuration cannot violate the MUST.
- **`http.route` is never a raw path** — when no low-cardinality template can be resolved, the attribute is omitted, per spec.
- **Symfony HttpClient, Guzzle and PSR-18 clients emit identical telemetry** — span name, status, span attributes, metric attributes, and one span and one measurement per retry attempt with `http.request.resend_count`. `network.peer.address`/`port` are recorded wherever the transport reports them: Symfony HttpClient, and Guzzle's curl handler through its transfer stats. A plain PSR-18 client exposes no transport, so it has none. `ClientParityTest` holds the three paths to this with a real peer in play, and the e2e run asserts the peer of a real request.
- **Log export follows the Logs Data Model.** SeverityNumber comes from OpenTelemetry's PSR-3 mapping (`notice` is INFO2, `critical` ERROR2, `alert` ERROR3, `emergency` FATAL), SeverityText is Monolog's original level name, ObservedTimestamp is set when the record is emitted, an exception in the context becomes the stable `exception.type`/`exception.message`/`exception.stacktrace`, introspection data becomes the stable `code.file.path`/`code.line.number`/`code.function.name`, each Monolog channel is its own instrumentation scope, and trace context comes from the active span.
- **Client spans carry `url.full`, not `url.path`** — `url.path` belongs to the server span attribute set; on a CLIENT span it only repeated part of `url.full`.
- **Server metrics omit `server.address`/`server.port`** — they are Opt-In in the spec because the Host header is client-controlled (cardinality-attack vector).
- Messaging conventions are **Development status** upstream; the bundle tracks the current metric names and dual-emit hooks are in place for future renames.
- **Only the stable database attribute names are emitted.** The deprecated `db.system`, `db.statement`, `db.operation` and `db.name` were dual-emitted through 3.x as a migration aid and were removed in 4.0, which is the path the [db migration guide](https://opentelemetry.io/docs/specs/semconv/non-normative/db-migration/) prescribes once the conventions are stable.

## Deliberate deviations

Chosen so task-oriented backends group telemetry usefully; all are spec-permitted:

| Where | Spec says | We do | Why |
|---|---|---|---|
| Messenger span name | `send {transport}` | `send {MessageClass}` | Tasks group per message type, not per queue |
| Console span name | `{process.executable.name}` | the command name | `app:import` beats `php` (allowed low-cardinality alternative) |
| Consumer parenting | span links by default | parent-child (links with `root_spans: true`) | end-to-end traces out of the box |
| `db.query.text` | sanitize, don't collect by default | **off by default**; opt-in via `traces.doctrine.record_statements: true` records verbatim (prepared statements are placeholder-safe) | opt-in is spec-sanctioned (MAY) |
| `messaging.system` | well-known broker list | `symfony_messenger` / `symfony_mailer` / `symfony_scheduler` | custom values are explicitly allowed |

Custom attributes (`console.command`, `cache.*`, `twig.*`, `scheduler.*`, `messaging.message.class`, `traceway.distributed_trace_id`, `traceway.db.spans_dropped`) cover areas with no registered convention yet.

## Known limitations

- On Symfony 6.4 a scoped client (`framework.http_client.scoped_clients`) is a `ScopingHttpClient` service rather than a decorator, so the tracer wraps it from outside and sees the relative URL: on the rare failure before the transport runs, such a request misses `server.address`/`url.full` (successful and transport-failed requests are enriched from the effective URL). On Symfony 7 and 8 the tracer sits inside the scoping decorator and always sees the absolute URL.
- `db.collection.name` is omitted for JOINs (per spec: single-collection operations only), but legacy comma-joins (`FROM a, b`) can still slip a name through — a full SQL parser is out of scope.
- Redirects followed inside Symfony HttpClient's transport (`max_redirects`) stay within one CLIENT span, since the transport never hands them back to a decorator. Retries through `RetryableHttpClient` (`retry_failed`) and Guzzle's redirect and retry middlewares each produce one span per attempt with `http.request.resend_count`.
- `db.stored_procedure.name` is not extracted from `CALL` statements.
