# Upgrade from v2.x to v3.0

v3.0 is a **conformance major**. It aligns every instrumentation with the current *stable* OpenTelemetry semantic conventions. **There are no API changes for your application code** — the breaking changes are span names, attribute keys, attribute values, metric bucket layouts, and one configuration-validation tightening. In practice the impact is: **some dashboards, alerts, and saved queries will regroup or need a key/value update.**

## TL;DR

- **One action may be required**: if `error_status_threshold` is set to a value in `400`–`499`, the config now fails validation at boot. Raise it to `500`+ (see below).
- **Everything else is observability-side**: rename a few attribute keys, update a few attribute values, and re-pin span-name groupings in your backend.
- **Your config still works**: the legacy flat config keys (deprecated in v2.0) are **still accepted in v3.0**. Their removal moved to v4.0.
- Recommended: skim the tables below, grep your dashboards/alerts for the old keys/values, update them, then upgrade.

## The one hard breaking change — `error_status_threshold`

OTel forbids setting span status `Error` on `4xx` responses for `SERVER` spans. The config can no longer express that: the minimum is now `500`.

```yaml
# Now fails validation at boot if the value is 400–499:
open_telemetry:
    traces:
        error_status_threshold: 450   # InvalidConfigurationException
```

**Action**: set it to `500` (the default) or higher. If you relied on a 4xx threshold to flag client errors as span errors, use a backend alert on `http.response.status_code >= 400` instead — that's the spec-conformant place for it.

## Attribute key renames

Grep your dashboards/alerts for the old keys and switch them:

| Old key | New key | Where |
|---|---|---|
| `process.command` | `console.command` | Console spans |
| `process.exit_code` | `process.exit.code` | Console spans |
| `process.command.args` | `process.command_args` | Console spans |
| `email.message_id` | `messaging.message.id` | Mailer spans |

## Attribute value changes

`db.system.name` now carries the real semconv registry enum values:

| Old value | New value |
|---|---|
| `mssql` | `microsoft.sql_server` |
| `oracle` | `oracle.db` |
| (raw driver string) | `ibm.db2` (DB2), `other_sql` (unknown drivers) |

The deprecated `db.system` attribute is still dual-emitted and keeps its **legacy** values (`mssql`, `oracle`, `db2`) — so queries on `db.system` are unaffected. **Action**: dashboards filtering `db.system.name` on `mssql`/`oracle` must switch to the new values (or filter `db.system` instead).

## Span-name changes

These cause your backend to create a new grouping (the old group stops receiving data; a new one appears). Re-pin saved views once.

| Signal | Before | After |
|---|---|---|
| HTTP server (pre-route) | `HTTP GET` | `GET` |
| HTTP server (routed) | `HTTP GET` | `GET /api/items/{id}` |
| HTTP client | `GET api.example.com` | `GET` |
| Messenger producer | `SendWelcomeEmail publish` | `send SendWelcomeEmail` |
| Messenger consumer | `SendWelcomeEmail process` | `process SendWelcomeEmail` |
| Mailer (bus span) | `send <transport>` (duplicated transport identity) | `create <transport>` |

Notes:
- `http.route` is now the **real route template** from the router (not a synthesized string). Endpoints whose synthesized template differed — e.g. routes with a `.{_format}` suffix — regroup once under the correct template.
- Unrouted requests (404s, etc.) **no longer** leak the raw URL path into `http.route` or the span name (spec MUST NOT). They group under the bare method.
- Messenger/Mailer deliberately keep the **message class** (not the spec's `{destination}`) as the low-cardinality target, so task-oriented backends keep grouping per message type. This is the only intentional deviation and is documented.

## Removed attribute

- **`url.full` is no longer set on HTTP *server* spans** — per semconv it is a client-span attribute. Servers carry `url.path`, `url.query`, `url.scheme`. **Action**: query `url.path` instead of `url.full` on server spans. (`url.full` is unchanged on *client* spans.)

## Metric bucket-layout change

- **`db.client.operation.duration`** now uses the database-semconv advisory boundaries `[0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10]` (was the HTTP boundaries) — sub-millisecond queries no longer collapse into the `≤5ms` bucket. Existing time-series for this metric may show a one-time discontinuity.

## Behavioral changes (no action, but worth knowing)

- **Console command spans now appear** in task-oriented backends (incl. Traceway). They were previously emitted as `SERVER` and silently dropped, orphaning their children. They are now `INTERNAL` with `console.command` and classify as Tasks. Expect to start seeing CLI traces you didn't before.
- **Successful spans leave status `Unset`, not `Ok`** (messenger, mailer, scheduler, cache) — per the trace-API guidance that `Ok` is reserved for explicit application marking. If you alert on `status = Ok`, alert on `status != Error` instead.
- **`messaging.client.sent.messages` no longer counts sync-handled dispatches** that never reached a broker. Apps with sync buses will see this counter drop — that traffic was never broker traffic.
- **Sensitive-query-param redaction** now matches the current semconv list (`X-Amz-Signature`, `X-Amz-Credential`, `X-Amz-Security-Token`, `sig`, `X-Goog-Signature`). AWS presigned-URL signatures that previously leaked into client `url.full` are now redacted.

## Config: legacy flat keys still work

The flat config keys deprecated in v2.0 (e.g. `traces_enabled` → `traces.enabled`) are **still accepted in v3.0** and still emit a deprecation. The v2.0 deprecation window was too short to remove them safely, so **removal moved to v4.0**. See [UPGRADE-2.0.md](UPGRADE-2.0.md#flat--nested-mapping) for the mapping if you haven't migrated yet — doing so now silences the deprecations and prepares you for v4.0.

## Migration recipe

1. **Before upgrading**: grep your observability config for the renamed keys/values above (`process.command`, `process.exit_code`, `email.message_id`, `db.system.name = mssql|oracle`, `url.full` on server spans, `status = Ok`).
2. If `error_status_threshold` is set to `400`–`499`, raise it to `500`+.
3. Upgrade: `composer require traceway/opentelemetry-symfony:^3.0`.
4. Re-pin saved views/dashboards to the new span-name groupings.
5. Expect new Console (CLI) traces to appear, and the two metric histograms to show a one-time bucket discontinuity.
