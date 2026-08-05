# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.2.0] - 2026-08-05

### Added

- **Recommended-tier semconv attributes** — `network.peer.address`/`network.peer.port` on HTTP SERVER spans (the immediate TCP peer, distinct from the proxy-resolved `client.address`), and `network.protocol.version` on HTTP server and client duration/body-size metrics (deliberately not on `http.server.active_requests`, which the spec's attribute table excludes).
- **End-to-end test harness (`e2e/`)** — boots a real kernel, exports over a real OTLP `http/json` pipeline to a real collector, and asserts on the spans the collector received; runs in CI (`.github/workflows/e2e.yml`). Catches the silent-export failure class that mocked tests cannot.
- **`docs/semantic-conventions.md`** — the conformance statement, deliberate deviations, and known limitations; the README was slimmed to standard bundle shape with details moved into `docs/`.
- **Gauge metric** — added support for gauge metrics in the MeterRegistry.

### Fixed

- **Handled 4xx responses no longer mark SERVER spans as errors** — semconv: "For HTTP status codes in the 4xx range span status MUST be left unset in case of SpanKind.SERVER". The error decision is now deferred from the exception event to response/finish time, so this also holds when a plain exception is mapped to 4xx via `#[WithHttpStatus]` or a custom exception listener (span Error status is irreversible in the SDK, so deciding early was unfixable after the fact). The exception event is always recorded; responses >= `error_status_threshold` keep the exception FQCN as `error.type`; unhandled exceptions (no response produced) mark the span Error with `error.type` at FINISH_REQUEST and now also record an `http.server.request.duration` sample with `error.type` (failed requests were previously invisible in the duration metric). **Dashboards note**: HTTP error-span counts will drop — those were false positives.
- **`http.response.status_code` is kept whenever one was received** — throwing accessors (`getContent(true)`/`getHeaders(true)`/`toArray(true)` on 4xx/5xx) and cancellations after headers arrived now record the received status on the client span and metrics (semconv: required "if and only if one was received").
- **Relative-URL requests keep Required client attributes on pre-transport failures** — the trace/metrics HTTP client decorators capture `base_uri` (from `withOptions()` or per-request options) and backfill `server.address`/`server.port` and an absolute, sanitized `url.full` when `request()` throws before an effective URL exists; query-only references resolve per RFC 3986 and scheme-relative (`//host`) URLs now get effective-URL enrichment so `url.full` ends up absolute. Known limit: `framework.http_client.scoped_clients` keep `base_uri` inside `ScopingHttpClient`, invisible to the decorators — those still rely on effective-URL enrichment.
- **`db.collection.name` omitted for multi-table SELECTs** — semconv conditions the attribute on the operation applying to a single collection; JOINs after the top-level FROM and inside derived tables no longer claim a single table (span names fall back to `{operation} {db.namespace}`).

## [3.1.0] - 2026-07-14

### Fixed

- **X-Ray tracer provider registers a shutdown flush** — with `traces.id_generator: xray`, the custom `TracerProvider` never registered a shutdown handler, so the default `BatchSpanProcessor` silently dropped most spans in short-lived PHP-FPM processes. `XRayBootstrapper` now registers `ShutdownHandler::register($provider->shutdown(...))` like the SDK autoloader does.
- **Failed requests first touched via `getStatusCode()` now record a failure metric** — `MeteredResponse::getStatusCode()` had no try/catch (unlike every other accessor), so a DNS/connect failure surfacing there left the request invisible in `http.client.request.duration`.
- **`toStream()` works with tracing and metrics both enabled** — the decorator stack is `TracedResponse` wrapping `MeteredResponse`, and `MeteredResponse` didn't implement `StreamableInterface`, so `TracedResponse::toStream()` always threw `LogicException` and flagged a successful request as an error. `MeteredResponse` now implements `StreamableInterface` and finalizes metrics at stream time.
- **Route-template warmer output is found on read-only-container deploys** — the warmer writes to `kernel.build_dir` but the resolver only searched `kernel.cache_dir`; on kernels separating the two, every process fell back to rebuilding the route collection at runtime. The resolver now checks both.
- **Messenger consumer `error.type` reflects the real handler exception** — `HandlerFailedException` wrappers (single-wrapped) are unwrapped via `getWrappedExceptions()` in `ErrorTypeResolver`, so error breakdowns no longer show a constant wrapper FQCN on consumer spans and metrics.
- **Messenger middleware survives app-defined bus middleware** — registration moved from `prepend()` to a compiler pass (`MessengerMiddlewarePass`): FrameworkBundle's `middleware` node uses `performNoDeepMerging()`, so any app configuring its own `middleware:` silently replaced the bundle's list and dropped tracing/metrics. The pass also inherits FrameworkBundle's own `default_bus` resolution, fixing single-custom-bus setups that previously aborted compilation ([#63](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/63) — thanks @d-mitrofanov-v).
- **`OpenTelemetryBundle::version()` no longer throws when not Composer-installed** — `InstalledVersions::getPrettyVersion()` throws `OutOfBoundsException` (rather than returning null) for unknown packages; the documented `"unknown"` fallback now actually applies (phar/single-file deploys).
- **Tag-aware pools keep `withSubNamespace()`** — Symfony 7.3+ `TagAwareAdapter` implements `NamespacedPoolInterface`, but the tag-aware decorator hid it, fataling on `withSubNamespace()`. Such pools now get the new `TraceableNamespacedTagAwareCachePool`.
- **SQL operation/target extraction hardened** — a comment between a CTE list and its body no longer wins keyword matching (`WITH x AS (...) /* delete old */ SELECT ...` reported `DELETE`), and `db.collection.name` for SELECTs now comes from the depth-zero `FROM`, so string literals (`SELECT 'from paris' ...`) and scalar subqueries in the select list can't hijack the table name. Doubled-quote escapes (`'it''s'`) are handled.
- **DBAL3 transaction methods return the driver result** — `beginTransaction()`/`commit()`/`rollBack()` on the traced DBAL3 connection discarded the inner result and always returned `true`.
- **DSN-style credentials and SigV2 signatures redacted** — `UrlSanitizer` now also redacts `AWSAccessKeyId`/`Signature` query params (SigV2 pre-signed URLs), handles protocol-relative URLs, and fully redacts userinfo containing a literal `@`.
- **HTTP server metrics drop `server.address`/`server.port`** — semconv marks them Opt-In on `http.server.request.duration`/`active_requests` because the Host header is client-controlled; keeping them allowed unbounded time-series cardinality from arbitrary Host headers.
- **`http.route` is never a raw concrete path** — when the route template can't be resolved and whole-segment substitution replaces nothing, the resolver now returns no route at all (per semconv) instead of emitting the request path as a metric dimension.
- **Long-running worker hygiene** — `OpenTelemetrySubscriber::reset()` now detaches scopes and ends spans for in-flight requests instead of dropping them (context-stack corruption on RoadRunner/FrankenPHP resets); `ConsoleSubscriber` no longer leaks a previous orphan span when commands without a `Command` instance run back-to-back; cached tracers no longer pin a pre-SDK-init noop tracer forever.
- **`OTEL_EXPORTER_OTLP_HEADERS`/`OTEL_RESOURCE_ATTRIBUTES` values are percent-encoded on merge** — values containing `,` or `=` no longer corrupt the whole variable; existing values are decoded before merging so the round-trip is lossless (the SDK/exporter url-decode on read per spec).
- **`traceway:doctor` correctness** — unknown `--only` names now error instead of running zero checks and exiting 0 in CI; non-numeric `--timeout` returns INVALID like other bad options; gRPC endpoints are probed with a TCP connect instead of an HTTP/1.1 HEAD that healthy collectors reject.
- **`composer test` no longer fails the SDK autoload test** — composer scripts export `COMPOSER_DEV_MODE`, which makes the SDK's `_autoload.php` a no-op; phpunit now clears it.
- **`Tracing::trace()` leaves span status UNSET on success** — per the OTel spec, instrumentation should not set `Ok`; error paths still set `ERROR`.
- **Trace/metrics parity** — the HTTP metrics subscriber honors `traces.error_status_threshold` (was hardcoded ≥ 500) and the trace subscriber validates `Content-Length` with the same `ctype_digit` guard as the metrics side. Cancelled HTTP client requests now record their span/duration with `error.type: cancelled` instead of vanishing.
- **`http.client.request.body.size` records with the final attribute set** — it was recorded pre-flight without `http.response.status_code`/`error.type`, so its dimensions never aligned with the duration histogram.
- **`server.address` for IPv6 hosts is emitted bare** (`::1`, not `[::1]`) across HTTP client spans and metrics.
- **`MeterRegistry` caches instruments by name + unit** — requesting a same-named instrument with a different unit no longer silently returns the first one.
- **`TraceContextProcessor` uses `SpanContext::isValid()`** instead of comparing against an all-zero trace id, so invalid-but-nonzero propagation input is not injected into logs.

### Changed

- **Config**: `excluded_paths` entries that are numeric scalars are now cast to path prefixes instead of silently dropped; `traces.enabled` info text documents master-switch behavior; using `%env()%` placeholders in `open_telemetry` config now fails with a clear explanation (the config is consumed at compile time) instead of a confusing type error.
- **Internal**: duplicated tracer/meter boilerplate, host-exclusion logic, transport-name extraction, short-class-name and default-port/IPv6 parsing consolidated into shared traits/helpers (`TracerAwareTrait`, `MeterAwareTrait`, `HostExclusionTrait`, `TraceableDbalTrait`/`MeteredDbalTrait`, `TransportNameResolver`, `ClassName`, `UrlParts`); the DBAL 3/4 middleware pairs are now thin signature shims and the cache decorators share one `traced()` span wrapper; dead code removed (`isConsoleAvailable()`, `DbMetricRecorder`'s unreachable `reset()`). The emitted `cache.pool` attribute is unchanged: CachePoolPass strips the tag's `name` before the bundle's pass runs, and the `name`-attribute fallback is kept (and now test-pinned) for custom pass orderings.

## [3.0.2] - 2026-07-07

### Fixed

- **Tag-aware cache pools stay tag-aware under the Symfony profiler** — `TraceableTagAwareCachePool` now implements `TagAwareAdapterInterface`, so Symfony's `CacheCollectorPass` wraps it in `TraceableTagAwareAdapter` rather than the plain `TraceableAdapter`. Injecting a decorated pool as a `TagAwareCacheInterface` previously failed with a type error in dev/profiler mode ([#59](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/59) — thanks @d-mitrofanov-v).
- **Messenger middleware respects a configured `default_bus`** — the tracing/metrics middleware is now prepended to the bus named by `framework.messenger.default_bus` (falling back to `messenger.bus.default`), instead of always targeting `messenger.bus.default`. Apps using a custom default bus no longer have to wire the middleware manually ([#60](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/60) — thanks @d-mitrofanov-v).
- **`traces.enabled` now acts as a master switch** — setting `traces.enabled: false` disables every trace subsystem (console, HTTP client, messenger, doctrine, cache, twig, scheduler, mailer) instead of only the request subscriber, so a single flag turns tracing off (e.g. in the test environment). Metrics and logs are unaffected ([#61](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/61) — thanks @d-mitrofanov-v).
- **Streamable traced responses cast to a real stream** — `TracedResponse` now implements `StreamableInterface`/`toStream()`, so Symfony's `TraceableResponse::toStream()` streams the underlying response instead of falling back to a lazy `StreamWrapper`; the span is finalized (or the error recorded) when the stream is produced ([#62](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/62) — thanks @d-mitrofanov-v).

## [3.0.1] - 2026-06-26

### Fixed

- **`cache:pool:prune` no longer fatals on a traced cache pool** — `TraceableCachePool` now implements `PruneableInterface` and delegates `prune()` to the inner pool. The prunable `cache.pool` tag transfers onto the decorator, so `CachePoolPrunerPass` selects it and the command called the missing `prune()` (`Call to undefined method ...::prune()`) on every scheduled run against a DB-backed pool. Mirrors Symfony's `TraceableAdapter` (returns `false` when the pool is not prunable); the `TraceableTagAwareCachePool`/`TraceableNamespacedCachePool` subclasses inherit it ([#57](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/57) — thanks @pflueg).
- **HTTP client spans/metrics finalize when a response is consumed via `stream()`** — a response read only through the streaming API previously left its span open until `__destruct()`/GC, producing wildly inflated durations in long-running workers. The stream now ends the span/metrics on the last chunk (and records the error on a transport-error chunk).
- **Decorators are now wired for `kernel.reset`** — the HTTP client (trace + metrics), cache pool, and mailer (`TraceableMailer`/`TraceableTransports`/`MeteredTransports`) decorators implement `ResetInterface` but were never tagged, so their cached tracer/`enabled` state never refreshed between work units in long-running workers (Swoole, RoadRunner, Messenger).
- **Doctrine span creation can no longer break a query** — `DbSpanBuilder` degrades to a non-recording span if the tracer throws (mirroring the metrics recorder), so a misconfigured SDK can no longer propagate an exception out of `query()`/`exec()`/prepared-statement execution. Tracing must never break the instrumented operation.
- **Empty `excluded_paths` entries are ignored** — an empty or whitespace-only string previously normalized to `/`, silently excluding all traffic from tracing/metrics. Such entries are now dropped (both `traces.excluded_paths` and `metrics.http_server.excluded_paths`).

## [3.0.0] - 2026-06-15

This is a **conformance major**: a full pass aligning every instrumentation with the current *stable* OpenTelemetry semantic conventions. There are no API changes for application code — the breaking changes are span-name, attribute-key, attribute-value, metric-bucket, and one config-validation change that mostly cause dashboards/alerts to regroup. See [UPGRADE-3.0.md](UPGRADE-3.0.md) for the full migration checklist. **Legacy flat config keys still work in 3.0** (removal deferred to 4.0 — see [UPGRADE-2.0.md](UPGRADE-2.0.md#timeline)).

### Fixed

- **`traces.doctrine.record_statements` now defaults to `false`** — per the database semconv, non-parameterized `query()`/`exec()` SQL should not be collected by default because it may carry unsanitized literals. SQL (`db.query.text`/`db.statement`) is no longer recorded unless you opt in. **Migration**: set `traces.doctrine.record_statements: true` to restore SQL on spans.
- **`url.full`/`url.query` redaction list updated to the current HTTP semconv** — the default sensitive-query-param deny-list is now `X-Amz-Signature`, `X-Amz-Credential`, `X-Amz-Security-Token`, `sig`, `X-Goog-Signature` (was the retired `AWSAccessKeyId`/`Signature` pair plus `sig`/`X-Goog-Signature`). Closes a gap where AWS presigned-URL signatures and credentials leaked into client-span `url.full`.
- **`QUERY` added to the default known-HTTP-methods list** (httpbis safe-method-with-body draft, present in the vendored sem-conv) so `QUERY` requests are no longer normalized to `_OTHER`.
- **Mailer transport span omits an empty `messaging.message.id`** instead of emitting a blank attribute when `SentMessage::getMessageId()` returns `''`.
- **HTTP client spans/metrics backfill transport-level attributes at finalize** — `network.peer.address`/`network.peer.port` (from `primary_ip`/`primary_port`), `network.protocol.version` (curl version constant mapped to `1.1`/`2`/`3`), and — closing the last Required-attribute gap — relative `base_uri` requests now resolve `server.address`/`server.port` and an absolute, sanitized `url.full` from the effective URL.
- **Messenger spans carry `messaging.message.id`** from `TransportMessageIdStamp` on both producer and consumer sides.
- **`error_status_threshold` minimum raised from 400 to 500** — OTel forbids `Error` status for 4xx responses on server spans; the config can no longer express a spec violation. **Migration**: configs using 400–499 fail validation at boot.
- **`url.full` removed from server spans** — it is a client-span attribute per semconv; servers carry `url.path`/`url.query`/`url.scheme`. **Migration**: query `url.path` instead.
- **Route templates resolve from a warmed cache, not the router** — new `RouteTemplateCacheWarmer` dumps a route-name → path-template map at `cache:warmup`; `RouteTemplateResolver` reads it (opcache-shared, free at runtime) and only falls back to `Router::getRouteCollection()` when no warmed cache exists. Calling `getRouteCollection()` per request would rebuild the full route collection under PHP-FPM — a measurable regression in the first iteration of this change, caught before release.
- **Console spans carry the cli-semconv Required attributes** — `process.pid` and `process.executable.name` were missing entirely; `process.command_args` is now the real process argv as a string array (was a single reconstructed string). Also: nested re-runs of the same Command instance no longer leak the previous span/scope.
- **Twig extension is now actually wired for `kernel.reset`** — the DI definition lacked `setAutoconfigured(true)`, so `reset()` was never invoked by `services_resetter`: a throwing top-level template leaked its span with a permanently-active scope (context-stack corruption) for the life of the worker. Drained orphans now end with status Unset (only exception-proven nested orphans are marked Error).
- **`MeteredResponse::__destruct` no longer blocks on the network** — it previously forced the lazy response to complete (up to the full request timeout) when garbage-collecting an unconsumed response; it now reads the non-blocking `http_code` info and skips never-started responses.
- **Scheduler recurring-message overwrite guard** — `RecurringMessage` reuses the same message instance per trigger; a missed PostRun no longer leaks the prior span/scope on the next run.
- **Messenger producer destination only set when unambiguous** — multi-transport routing previously recorded just the last `SentStamp`'s transport; per semconv the attribute is now set only when a single destination applies.
- **`OtelLogHandler` skips the `trace_flags` extra key** — avoids duplicating the LogRecord's native trace context as a flat attribute.
- **Twig spans are now exception-safe** — Twig's profiler hooks skip `leave()` when a template throws, leaving the span open with a leaked scope, a wrong duration (closed only at `reset()`/destruct), and out-of-order scope detaching. `leave()` now closes any nested orphans (in reverse order, preserving scope nesting) and marks them `Error` with "Template rendering did not complete"; `reset()`/destruct drains do the same.
- **Cache spans set `error.type` on failure** (exception FQCN via `ErrorTypeResolver`), matching every other instrumentation in the bundle; `cache.tags` is now a native string-array attribute instead of a comma-joined string.
- **Scheduler spans leave status `Unset` on success** (and on ignored failures) instead of forcing `Ok` — per OTel trace API guidance, `Ok` is reserved for explicit app-level marking. Ignored failures still record the exception event and `error.type`.
- **Monolog `TraceContextProcessor` now also injects `trace_flags`** (W3C two-hex form, e.g. `01`) alongside `trace_id`/`span_id`, so non-OTel log sinks can tell sampled from unsampled traces when joining logs to traces.
- **Messenger and Mailer instrumentation conform to the current OTel messaging semconv (breaking span-name and operation-type changes)** — six fixes:
  - *`messaging.operation.type: publish` → `send`*: `publish` was renamed in the messaging conventions (the vendored sem-conv marks it `@deprecated Replaced by 'send'`).
  - *Required `messaging.operation.name` now set* on messenger producer (`send`) and consumer (`process`) spans — previously only the metrics middleware and Scheduler satisfied this.
  - *Span names use semconv operation-first ordering*: `send {ShortMessageClass}` / `process {ShortMessageClass}` (was `{ShortMessageClass} publish/process`). The message class is kept as the low-cardinality target — deliberately not the spec's `{destination}` — so task backends (Traceway) keep grouping Tasks per message type instead of collapsing to one group per transport. **Migration**: Traceway Task groups rename once (e.g. `SendWelcomeEmail process` → `process SendWelcomeEmail`).
  - *Producer spans now carry `messaging.destination.name`* (conditionally required) resolved from the `SentStamp` after dispatch; *`error.type`* now set on both messenger failure paths via `ErrorTypeResolver`.
  - *`root_spans: true` no longer loses producer correlation*: the consumer root span now carries a span link to the dispatching trace context (the spec's default correlation mechanism) instead of dropping it.
  - *`messaging.client.sent.messages` no longer counts sync-handled messages*: dispatches that produce no `SentStamp` never reached a broker (spec: "MUST NOT count messages that were created but haven't yet been sent"); failed dispatches still record `messaging.client.operation.duration` with `error.type`. **Migration**: apps with sync-handled buses will see this counter drop — that traffic was never broker traffic.
  - *Mailer*: the bus-level span is now `create {transport}` with `messaging.operation.type/name: create` (PRODUCER, per the spec's operation table) so it no longer duplicates the transport-level `send {transport}` CLIENT span's identity; `email.message_id` renamed to the standard `messaging.message.id`; success spans leave status Unset instead of forcing `Ok` (also applied to messenger spans), per the trace API guidance that `Ok` is reserved for explicit user marking.
- **Doctrine instrumentation conforms to the stable OTel database semconv (breaking `db.system.name` value changes for MSSQL/Oracle/unknown drivers)** — five fixes:
  - *`db.system.name` carries real enum values*: `microsoft.sql_server` (was `mssql`), `oracle.db` (was `oracle`), new `ibm.db2`, and unknown drivers now fall back to `other_sql` instead of leaking the raw Doctrine driver string. MariaDB is detected via the connection's `serverVersion` param (e.g. `10.11.2-MariaDB` → `mariadb`). The dual-emitted deprecated `db.system` attribute keeps its legacy values (`mssql`, `oracle`, `db2`) via the new shared `DbSystemResolver` (also de-duplicates resolution between the trace and metric drivers). **Migration**: dashboards filtering `db.system.name` on `mssql`/`oracle` must switch to the new values.
  - *`db.response.status_code`* (conditionally required on failure): SQLSTATE from Doctrine's `Driver\Exception::getSQLState()` is now recorded on failed spans and metric points.
  - *DB-specific histogram buckets*: `db.client.operation.duration` now uses the database semconv advisory `[0.001 … 10]` (new `DurationBoundaries::DB_SECONDS`) instead of the HTTP boundaries — sub-millisecond queries no longer collapse into the ≤5ms bucket. **Migration**: bucket layout of this metric changes.
  - *SQL operation extraction hardened*: leading comments (`/* */`, `--`, `#`), wrapping parentheses, and CTEs (`WITH … SELECT`) now resolve to the real statement verb instead of garbage tokens like `/*`, `(SELECT`, or `WITH`; unparseable SQL falls back to target → namespace → `db.system.name` for the span name (per spec) instead of the invented `UNKNOWN` token, and no longer emits `db.operation.name`.
  - *Span-side `error.type` now uses `ErrorTypeResolver`* (anonymous exception classes resolve to their parent FQCN), matching the metric side; failure handling is centralized in `DbSpanBuilder::recordFailure()`. `db.query.summary` is now also emitted on the duration metric (recommended attribute).
- **HTTP spans and metrics conform to the stable OTel HTTP semconv (breaking span-name and `http.route` changes)** — six fixes across server and client instrumentation:
  - *Method normalization*: unknown HTTP methods are normalized to `_OTHER` with the raw value preserved in `http.request.method_original` (spec MUST). The known-method list is RFC 9110 + PATCH, overridable via `OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS`.
  - *Span names*: server spans start as bare `GET` (previously `HTTP GET`) and become `GET /api/items/{id}` after routing; client spans are named by bare method (previously `GET api.example.com`), both per the spec's `{method} {target}` rules. Unknown methods use `HTTP`.
  - *`http.route` comes from the real route template*: resolved from the router's route collection via the new `RouteTemplateResolver` (cached per route name), with whole-segment parameter substitution as fallback — the old substring `str_replace` could corrupt static segments (param value `a` mangling `/api`). Unrouted requests no longer leak the raw URL path into `http.route` or the span name (spec MUST NOT). **Migration**: endpoints whose synthesized template differed from the real route path (e.g. routes with `.{_format}` suffixes) will regroup once under the new string.
  - *`error.type`* (spec conditionally required) now set everywhere it was missing: server spans (exception FQCN, or status code string for >= threshold responses), client spans (status string for >= 400, exception FQCN for transport errors), server metrics (5xx without exception), client metrics (>= 400 responses).
  - *Client `server.address`/`server.port`*: default ports are inferred from the scheme (443/80) so the spec-required `server.port` is present on default-port URLs; relative/unparseable URLs omit `server.address` instead of recording the literal `unknown`.
  - *URL redaction* (spec MUST NOT carry credentials): `url.full` redacts `user:pass@` to `REDACTED:REDACTED@`, and the semconv-listed sensitive query params (`AWSAccessKeyId`, `Signature`, `sig`, `X-Goog-Signature`) are redacted in `url.full` and `url.query`.
  - Also: `network.protocol.version` reports `2`/`3` instead of `2.0`/`3.0`, and `TracedResponse::getStatusCode()` now records transport errors on the span instead of silently ending it in the destructor.
- **Console command spans now conform to OTel CLI semconv and classify as Tasks (breaking attribute changes)** — `ConsoleSubscriber` emitted the command root span as `SERVER` with a `process.command` attribute. Task-oriented backends (Traceway) classify a span as a Task only when it is `CONSUMER` or a root `INTERNAL` span carrying `console.command`, so every `bin/console` run was silently dropped and its child spans orphaned. The span kind is now `INTERNAL` (the [CLI span spec](https://opentelemetry.io/docs/specs/semconv/cli/cli-spans/) says the callee span "SHOULD be INTERNAL"; no other PHP console instrumentation uses `SERVER`) and the command name is recorded as `console.command`, matching keepsuit's Laravel instrumentation. Attributes renamed to their real semconv keys: `process.exit_code` → `process.exit.code`, `process.command.args` → `process.command_args` (neither old name exists in the semconv registry). Failed commands now also carry `error.type` (exception FQCN via `ErrorTypeResolver`, or the exit code as a string when no exception was recorded), conditionally required by the CLI spec. **Migration**: dashboards or alerts querying `process.command`, `process.exit_code`, or `process.command.args` must switch to the new keys.

## [2.2.1] - 2026-06-11

### Fixed

- **Cache pools declared via `ChildDefinition` now classify correctly** — the default shape of `framework.cache.pools` pools (a `ChildDefinition` of `cache.adapter.filesystem` / `cache.adapter.redis` / etc. with no class set on the child) was falling through to plain `TraceableCachePool`. On Symfony 7.3+ this crashed container compilation via `CheckAliasValidityPass` because `NamespacedPoolInterface` is aliased to `cache.app`. `CacheTracingPass` now walks the parent chain to resolve the effective class before classifying ([#55](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/55) — thanks @d-mitrofanov-v).

### Docs

- **`grpc` protocol prerequisites documented** — README now flags that `protocol: grpc` requires the `ext-grpc` PHP extension and the `open-telemetry/transport-grpc` Composer package ([#54](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/54)).

## [2.2.0] - 2026-06-01

### Added

- **`traceway:doctor` diagnostic command** — new `traceway:doctor` (alias `debug:traceway`) runs a suite of checks across runtime extensions (`ext-opentelemetry`, `ext-protobuf`), SDK configuration (service name, OTLP endpoint, protocol, traces exporter, sampler, tracer provider), bundle wiring (Messenger middleware registration, OtelLogHandler registration, X-Ray dependency presence), and OTLP endpoint reachability. Supports `--format=json` with a stable envelope (`{version, summary, checks}`) for CI consumption; `--skip-network` skips connectivity checks; `--only=<names>` filters to a comma-separated subset; `--fail-on=info|warning|error` controls the exit-code threshold (default `error`); `--timeout=<seconds>` bounds network checks. Severities are `info`, `warning`, `error` ([#53](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/53)).
- **SDK configuration via bundle config** — new `open_telemetry.sdk.*` section lets you set OpenTelemetry SDK env variables through Symfony's configuration system rather than the shell or Composer-time env. `sdk.autoload_enabled: true` toggles `OTEL_PHP_AUTOLOAD_ENABLED` from bundle config and re-executes the SDK's `_autoload.php` — solving the case where Symfony's Dotenv runs too late for autoload to see it (so `.env.local` and Symfony Secrets now work for the autoload toggle). `sdk.resource_attributes` and `sdk.exporter_otlp_headers` accept key/value maps that merge into `OTEL_RESOURCE_ATTRIBUTES` and `OTEL_EXPORTER_OTLP_HEADERS` respectively, with bundle values winning over any pre-existing env entries — primarily intended for Symfony Secrets interpolation (e.g. `'Authorization': 'Bearer %env(OTEL_API_BEARER_TOKEN)%'`). `sdk.use_putenv` (off by default for thread-safety) opts into mirroring writes via `putenv()` alongside `$_SERVER`/`$_ENV`. The whole section is `canBeEnabled()`-style: setting any sub-key implicitly enables it; set `sdk.enabled: false` to suppress ([#49](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/49) — thanks @AndreasA).

## [2.1.0] - 2026-05-29

### Added

- **Native AWS X-Ray support** — new `traces.propagator` config key (`w3c` default, `xray`, `w3c+xray`) swaps the global `TextMapPropagator` for AWS X-Ray's `X-Amzn-Trace-Id` header format; all context injection and extraction across HTTP, HttpClient, and Messenger updates automatically. New `traces.id_generator` config key (`default`, `xray`) builds a `TracerProvider` using the SDK's env-var auto-configuration factories plus `XRayIdGenerator`, producing epoch-prefixed trace IDs that X-Ray's UI renders as request timestamps. Both options require `open-telemetry/contrib-aws` (added to `suggest`); a clear `LogicException` is thrown at container boot if the package is absent. The `w3c+xray` propagator mode writes both `traceparent` and `X-Amzn-Trace-Id` simultaneously via `MultiTextMapPropagator`, useful during a gradual migration ([#46](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/46) — thanks @FrameAutomata).

### Fixed

- **PHP 8.5 deprecations** — replaced `SplObjectStorage::contains()` / `detach()` (deprecated in 8.5) with `offsetExists()` / `offsetUnset()` in the Console, Scheduler, and Twig instrumentation ([#48](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/48) — thanks @MrYamous). Removed `ReflectionProperty::setAccessible()` / `ReflectionMethod::setAccessible()` calls from the test suite (no-op since PHP 8.1, deprecated in 8.5).

## [2.0.0] - 2026-05-15

### Changed (breaking)

- **Config restructured to nested, signal-grouped shape** — every top-level flat key migrates under `traces:`, `metrics:` (unchanged), or `logs:`. Aligns with the OpenTelemetry specification, the `OTEL_*` env-var convention, and the existing nested `metrics:` node we've shipped since v1.7.0. Existing v1.x flat config **keeps working** with a deprecation per key; flat keys are scheduled for removal in v3.0. Setting both a legacy flat key and its nested equivalent in the same configuration block throws `InvalidConfigurationException`. See [UPGRADE-2.0.md](UPGRADE-2.0.md) for the complete flat→nested mapping.
- **`log_export_unprefixed_attributes` default flipped from `false` to `true`** — Monolog `context` and `extra` fields are now emitted as flat OTel attributes by default, matching the cross-ecosystem norm (Java logback, Python `LoggingHandler`, .NET `OpenTelemetryLogger`, JS Winston). The new path is `logs.export.unprefixed_attributes`. If your dashboards depend on the v1.x `monolog.context.*` / `monolog.extra.*` prefixed shape, set it explicitly to `false`. The knob has existed since v1.8.0 ([#39](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/39)) so users have had a release cycle to migrate before the default change.

### Deprecated

- **All v1.x flat config keys** — every key triggers a Symfony deprecation pointing at its new nested location. Removal scheduled for v3.0. Run `bin/console cache:clear` after upgrading to surface the deprecations in the dev log. Full mapping in [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Added

- **`logs:` configuration node** — groups Monolog correlation (`logs.correlation.enabled`, formerly `monolog_enabled`) and native OTel log export (`logs.export.*`, formerly `log_export_*`) under one signal-aligned section.
- **Instrumentation scope version + schema URL** — every `Tracer`, `Meter`, and `Logger` the bundle obtains from the OTel SDK now reports the full `(name, version, schemaUrl)` tuple required by the OTel instrumentation-scope spec (a SHOULD-level recommendation we previously missed). Version is resolved at runtime via Composer's `InstalledVersions::getPrettyVersion()` so it always reflects the actual installed release. Schema URL is pinned to `https://opentelemetry.io/schemas/1.32.0` (the version of `open-telemetry/sem-conv` we target). Backends like Tempo, Datadog, and Honeycomb can now filter by `otel.scope.version` to slice telemetry per bundle release.

## [1.9.0] - 2026-05-15

### Added

- **Mailer tracing** — new `TraceableMailer` decorator emits a PRODUCER span around `MailerInterface::send()`, and new `TraceableTransports` decorator emits a CLIENT span around the transport-level send. Attribute shape follows OTel messaging semconv (`messaging.system=symfony_mailer`, operation name/type, destination from `X-Transport` header, `error.type` on failure) plus ECS-aligned email keys (`email.to.count`, `email.message_id`, opt-in `email.subject`) anticipating [semantic-conventions#927](https://github.com/open-telemetry/semantic-conventions/issues/927). Auto-activates when `symfony/mailer` is installed; opt out with `mailer_enabled: false`. Subject capture is opt-in via `mailer_record_subject: true` (PII-adjacent). When `framework.mailer.message_bus` is set, the PRODUCER span correctly scopes to the dispatch only; the CLIENT span later covers the worker-side transport send ([#40](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/40)).
- **Symfony Scheduler instrumentation** — new `SchedulerSubscriber` emits a CONSUMER span per scheduled task run with OTel messaging semconv attributes plus a Traceway-specific `scheduler.*` namespace for trigger metadata. Pre/Post/Failure events drive span lifecycle; cancellations via `PreRunEvent::shouldCancel(true)` are recorded as `scheduler.cancelled` so they remain observable. Because scheduler dispatches flow through the Messenger bus, `OpenTelemetryMiddleware` auto-suppresses its own PRODUCER/CONSUMER spans on envelopes carrying Symfony's `ScheduledStamp` — letting the richer scheduler span own the work unit without duplicate spans. Auto-activates when `symfony/scheduler` is installed; opt out with `scheduler_enabled: false` ([#41](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/41)).
- **Incoming HTTP server metrics** — new `OpenTelemetryMetricsSubscriber` emits `http.server.request.duration` (Histogram, semconv Stable), `http.server.active_requests` (UpDownCounter), `http.server.request.body.size`, and `http.server.response.body.size` (Histograms) with OTel HTTP server semconv attributes. Only main requests are measured (sub-requests are already covered by the main duration); metric recording failures never mask request handling. Off by default; enable with `metrics.http_server.enabled: true`. `metrics.http_server.excluded_paths` lets you skip routes like `/health` and `/_profiler` ([#30](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/30) — thanks @srekcud).
- **Mailer transport metrics** — new `MeteredTransports` decorator emits `messaging.client.operation.duration` (Histogram, `s`) and `messaging.client.sent.messages` (Counter, `{message}`) on outbound transport sends, with OTel messaging attributes (`messaging.system=symfony_mailer`, operation name/type, destination from `X-Transport` header, `error.type` on failure). Off by default; enable with `metrics.mailer.enabled: true`. Decoration priority places it inside the existing `TraceableTransports` so metric data points record within the active trace span scope, enabling SDK-level exemplar linkage from metric points back to traces.

### Changed

- **Internal: shared `DurationBoundaries::SECONDS` constant** — bucket boundaries for every second-based duration histogram in the bundle are now centralized in `Traceway\OpenTelemetryBundle\Metrics\DurationBoundaries`. The previously public-but-undocumented per-class `DURATION_BUCKET_BOUNDARIES` constants on `MeteredHttpClient`, `OpenTelemetryMetricsMiddleware`, `OpenTelemetryMetricsSubscriber`, `DbMetricRecorder`, and `MeteredTransports` have been removed. If you reference any of them in your own code, switch to `DurationBoundaries::SECONDS`.

### Fixed

- **`HttpClientMetricsPass` decoration-priority comment** — was incorrect about Symfony's priority direction (claimed `MeteredHttpClient` wraps `TraceableHttpClient`; the actual decoration ordering is the inverse, with metrics recorded inside the active trace span scope). No behavior change — runtime ordering was already correct for exemplar linkage; only the explanatory comment was misleading future readers.
- **`OpenTelemetryTestKernel` cache directory collision under PHPUnit 13** — the test kernel keyed its cache directory on `spl_object_id($this)`, which PHP recycles after garbage collection. Under PHPUnit 13's earlier teardown lifecycle, a second test could be assigned the same object ID as a destroyed first kernel and silently load the previous test's compiled container — masking its own config and producing flaky failures in `BundleBootTest`. Now uses `getmypid() . '_' . $counter` where the counter is a monotonic per-process value, so the cache dir is unique both within a process (no ID recycling) and across concurrent PHP processes (paratest, accidental parallel `phpunit` invocations).

### Maintenance

- **PHPUnit 13 compatibility** — every `@dataProvider` and `@group` docblock annotation across `tests/Doctrine/Middleware/` was migrated to the PHP 8 attribute equivalents (`#[DataProvider]`, `#[Group]`). PHPUnit 13 removed support for docblock metadata; under it, unmigrated `@dataProvider` annotations silently fall through to argument-less invocation and throw `ArgumentCountError`. `phpunit/phpunit` require-dev constraint expanded to `^10.0 || ^11.0 || ^13.0` so the existing CI matrix (PHP 8.1 through 8.4) picks the highest compatible version on each row: PHP 8.1 → PHPUnit 10, PHP 8.2 → PHPUnit 11, PHP 8.4 → PHPUnit 13. PHPUnit 12 is omitted (short-lived release between Feb–Sep 2025, immediately superseded by 13 with overlapping PHP version support, so testing it adds matrix complexity for no coverage gain).

## [1.8.0] - 2026-05-11

### Added

- **OTel `code.*` log attributes (semconv Stable)** — `OtelLogHandler` now emits `code.file.path`, `code.line.number`, and `code.function.name` on log records, promoted from Monolog's `IntrospectionProcessor` extras. Backends with source-link support (Jaeger, Tempo, Datadog, etc.) render these as clickable links to your log call sites ([#36](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/36))
- **`log_export_capture_code_attributes` config flag** — opt-in `debug_backtrace` fallback to resolve the new `code.*` attributes when `Monolog\Processor\IntrospectionProcessor` is not installed. Off by default; prefer installing the processor for zero-overhead resolution ([#36](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/36))
- **`log_export_unprefixed_attributes` config flag** — opt into the flat cross-ecosystem attribute shape (Java/Python/.NET/JS all emit user log fields flat). When `true`, `$record->context` and `$record->extra` keys are emitted unprefixed instead of under `monolog.context.*` / `monolog.extra.*`. Default `false` for backward compatibility; will flip to `true` in v2.0 ([#39](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/39))
- **Outgoing HTTP client metrics** — new `MeteredHttpClient` decorator emits `http.client.request.duration` (Histogram, semconv Stable), `http.client.request.body.size`, and `http.client.response.body.size` (Development) with OTel HTTP semantic-convention attributes. Off by default; enable with `metrics.http_client.enabled: true` ([#29](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/29) — thanks @srekcud)
- **Doctrine DBAL metrics** — new metered middleware emits `db.client.operation.duration` (Histogram) for every DBAL query, exec, prepared statement execution, and transaction control. Off by default; enable with `metrics.doctrine.enabled: true` ([#31](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/31) — thanks @srekcud)
- **`Tracing` implements `ResetInterface`** — the manual-instrumentation helper now joins every other lazy-tracer class in the bundle, clearing its cached tracer state between Symfony `kernel.reset` cycles. Closes the last `ResetInterface` gap; matters in long-running processes (Messenger workers, FrankenPHP, RoadRunner, Swoole) ([#38](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/38))

### Changed

- **`monolog.channel` attribute no longer emitted on log records** — the Monolog channel is exclusively represented as the OTel `InstrumentationScope` name (matching Java logback, Python `LoggingHandler`, .NET `OpenTelemetryLogger`, and JS Winston, none of which duplicate the channel/logger name as an attribute). If your dashboards filter by `monolog.channel = "X"`, switch to filtering by the scope name instead ([#37](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/37))
- **`monolog.extra.{file,line,class,callType,function}` no longer emitted when `IntrospectionProcessor` extras are present** — those keys are promoted to canonical `code.*` attributes (see Added). Users running without `IntrospectionProcessor` are unaffected; users running with it should migrate dashboard queries to the new `code.*` keys ([#36](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/36), [#37](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/37))

### Fixed

- **`TraceableHttpClient::request()` cleanup-ordering bug** — if `$span->recordException()` or `$span->setStatus()` itself threw inside the catch block (rare, but possible when the OTel SDK or attribute serializer fails), `$inFlight` was left `true` and the scope was not detached, silently suppressing all future HTTP client spans on that instance until `reset()` fired. Cleanup is now wrapped in a `try { try { ... } catch (...) { record; end; throw; } } finally { detach; inFlight=false; }` shape that matches `Tracing::trace()`. Most users won't have observed the symptom, but the failure mode would have been particularly bad in long-running Messenger workers ([#38](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/38))

## [1.7.0] - 2026-05-10

### Added

- **Metrics foundation** — new `MeterRegistry` service and `OpenTelemetryMetricsMiddleware` for Symfony Messenger consumer-side metrics (`messaging.process.duration` histogram, `messaging.client.consumed.messages` counter) with OTel semantic convention attributes. Off by default; enable with `metrics.enabled: true` and `metrics.messenger.enabled: true` ([#27](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/27))
- **`Util\ErrorTypeResolver`** — shared utility for resolving `error.type` attribute from exceptions, with anonymous class fallback to parent FQCN ([#32](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/32))
- Unit and functional test coverage improvements ([#20](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/20))

### Fixed

- **`TraceableHttpClient::stream()` breaks `RetryableHttpClient`** — stream chunks were keyed by the inner (unwrapped) response instead of the `TracedResponse` wrapper, causing `UnexpectedValueException: Object not found` in `AsyncResponse` when any decorator using `AsyncResponse` (e.g. `RetryableHttpClient`) sat above `TraceableHttpClient` in the chain. Now re-keys chunks using `SplObjectStorage`, mirroring Symfony's own `TraceableResponse::stream()` pattern ([#34](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues/34), [#35](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/35))
- **Metrics never mask handler exceptions** — metric recording failures are swallowed so a broken meter provider cannot interfere with Messenger message handling ([#27](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/27))
- **Second-based histogram buckets** — `messaging.process.duration` now uses explicit second-based bucket boundaries aligned with OTel conventions ([#27](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/27))
- **Anonymous class `error.type`** — classes containing `@anonymous` in their FQCN now fall back to the parent class name ([#27](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/27))
- **PHP 8.1 compat** — replaced `iterator_to_array()` with spread operator where needed ([#27](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/27))

## [1.6.1] - 2026-04-16

### Fixed

- **Bundle loading order crash with `log_export_enabled: true`** — `OtelLogHandler` and `OtelLoggerFlushSubscriber` service definitions are now registered in `prepend()` instead of `load()`, so they exist before `MonologBundle::load()` compiles its handler references regardless of bundle registration order in `bundles.php` ([#17](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues/17), [#18](https://github.com/tracewayapp/opentelemetry-symfony-bundle/pull/18) — thanks @srekcud)

## [1.6.0] - 2026-04-13

### Added

- **Monolog log export via OpenTelemetry Logs API** — new `OtelLogHandler` bridges Monolog records into the OTel Logs API with native trace correlation, per-channel instrumentation scopes, microsecond timestamp precision, and exception attributes. Off by default; enable with `log_export_enabled: true` (also requires `symfony/monolog-bundle`)
- **`log_export_enabled` / `log_export_level` config keys** — opt-in toggle and minimum severity filter for the new OTel log export pipeline
- **`OtelLoggerFlushSubscriber`** — flushes the `LoggerProvider` on `kernel.terminate` and `console.terminate` so log records queued in `BatchLogRecordProcessor` are not lost when a request finishes faster than the batch processor's scheduled flush interval
- **Re-entrance guard in `OtelLogHandler`** — prevents infinite loops when the OTel exporter itself emits a log record (e.g. an instrumented HTTP client logging a failed OTLP send), matching `TraceableHttpClient`'s `$inFlight` pattern
- **Loud failure when `log_export_enabled: true` but `symfony/monolog-bundle` is missing** — `OpenTelemetryExtension::prepend()` now throws `LogicException` with a clear install hint instead of silently no-op'ing (the previous behavior masked the misconfiguration because `prependExtensionConfig` stores config for nonexistent extensions without error)

## [1.5.0] - 2026-04-10

### Added

- **Doctrine DBAL 3 tracing support** — version-specific classes (`TraceableConnectionDbal3`/`Dbal4`, `TraceableStatementDbal3`/`Dbal4`) with runtime detection via `VersionAwarePlatformDriver` interface existence; DBAL 3 users now get full query tracing instead of auto-disabled Doctrine instrumentation ([#8](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues/8))
- **`NamespacedPoolInterface` support** — `CacheTracingPass` now selects `TraceableNamespacedCachePool` for cache pools implementing `NamespacedPoolInterface` (Symfony 7.3+), fixing container compilation failures; guarded with `interface_exists()` so Symfony < 7.3 is unaffected ([#11](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues/11))

### Fixed

- **ConsoleSubscriber orphaned spans** — `ConsoleSubscriber::reset()` now properly detaches active spans before clearing storage, preventing orphaned spans in long-lived workers (Messenger, Swoole, RoadRunner) ([#9](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues/9))

## [1.4.4] - 2026-04-04

### Fixed

- **Doctrine config default not normalized** — `doctrine_enabled` default value now uses a shared `$isDbalCompatible` closure so it evaluates correctly even when no config is set manually, following FrameworkBundle's `$enableIfStandalone` pattern ([#8](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues/8))

## [1.4.3] - 2026-04-04

### Changed

- **Doctrine DBAL 3 graceful degradation** — removed the `doctrine/dbal: <4.0` conflict rule from `composer.json` so the bundle can be installed alongside DBAL 3; Doctrine tracing is now auto-disabled via Symfony config normalization when DBAL < 4.0 is detected, keeping all other instrumentations (HTTP, Console, Messenger, Cache, Twig, Monolog) fully functional ([#8](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues/8))

## [1.4.2] - 2026-04-03

### Added

- `doctrine/dbal: <4.0` conflict rule in `composer.json` — Composer now blocks installation with DBAL 3 instead of failing at runtime with TypeErrors
- Doctrine DBAL 3 support added to roadmap

## [1.4.1] - 2026-04-03

### Changed

- **Service version via environment** — `service.version` is no longer set automatically from the bundle; configure it via `OTEL_RESOURCE_ATTRIBUTES=service.version=1.0` for per-deployment control
- Removed `OpenTelemetryBundle::VERSION` constant (no longer used)
- Added Releases section to `CONTRIBUTING.md`
- Updated `CLAUDE.md` release instructions to reflect manual tagging process

## [1.4.0] - 2026-04-02

### Fixed

- **Infinite recursion in UrlGenerator** — OTel span/scope objects are now stored in a `WeakMap` instead of `$request->attributes`, preventing them from leaking into Symfony's `UrlGenerator::doGenerate()` where `array_walk_recursive` caused stack overflow on redirects (e.g. login, access denied)
- **Cache pool type errors in debug mode** — `TraceableCachePool` constructor no longer requires the inner pool to implement `CacheInterface` and `AdapterInterface` upfront; checks are deferred to methods that need them, fixing `TypeError` when Symfony wraps pools with `TraceableAdapter` in dev mode

### Added

- `DEPLOYMENT.md` — step-by-step deployment guide covering PHP extensions, FPM environment configuration, bundle setup, verification, and troubleshooting

## [1.3.3] - 2026-04-01

### Fixed

- **Memory leaks in long-running processes** — `ConsoleSubscriber`, `OpenTelemetrySubscriber`, `TraceableCachePool`, and `OpenTelemetryMiddleware` now implement `ResetInterface`, allowing Symfony's `services_resetter` to clear cached tracer/enabled state between requests in Messenger workers, Swoole, RoadRunner, and FrankenPHP
- **Orphaned console spans** — `ConsoleSubscriber` now uses `SplObjectStorage` for per-command span storage instead of single instance properties, preventing span overwrites when a command crashes before `onTerminate`
- **Twig `spl_object_id` reuse** — `OpenTelemetryTwigExtension` now uses `SplObjectStorage` instead of `spl_object_id()` keyed arrays, eliminating the theoretical risk of matching a wrong span after garbage collection

## [1.3.1] - 2026-03-12

### Added

- `http_client_excluded_hosts` configuration option — exclude specific hostnames from outgoing HTTP client tracing (e.g. your OTLP collector)
- OTLP endpoint auto-exclusion — `TraceableHttpClient` automatically skips tracing for calls matching `OTEL_EXPORTER_OTLP_ENDPOINT`, preventing instrumentation loops
- Re-entrance guard in `TraceableHttpClient` — nested HTTP calls made while a traced call is in-flight (e.g. exporter, security token validation) are passed through without creating duplicate spans
- 256 unit tests with 649 assertions (up from 250/640)

### Fixed

- **HttpClient instrumentation loop** — when `traces_enabled` and `http_client_enabled` were both active, outgoing HTTP calls from Symfony internals (security, OTLP export) could create unbounded spans leading to memory exhaustion
- **Cache `AdapterInterface` compatibility** — `TraceableCachePool` now implements `Symfony\Component\Cache\Adapter\AdapterInterface`, fixing `TypeError` with Symfony's `TraceableAdapter` (web profiler) in dev mode
- **Console scope detach notice** — `ConsoleSubscriber` now suppresses `DebugScope` notices during `__destruct` cleanup (fires when `onTerminate` never runs due to fatal error or `exit()`) and detaches scope before ending span (correct OTel ordering)
- **Memory cleanup in `OpenTelemetrySubscriber`** — span, scope, and exception references are removed from the Request attributes bag in `onFinishRequestEndSpan` and `onTerminate`, preventing accumulation in long-running processes or functional tests
- **Request body size optimization** — `onResponse` now uses the `Content-Length` header for request body size instead of reading the full body via `getContent()`, avoiding unnecessary memory allocation for large payloads
- **Doctrine DBAL 3 conflict** — added `conflict: doctrine/dbal: "<4.0"` to `composer.json` since DBAL 3's method signatures (`execute($params)`, `beginTransaction(): bool`) are incompatible with DBAL 4's abstract middleware classes; DBAL 3 is EOL

## [1.3.0] - 2026-03-12

### Added

- **Monolog log-trace correlation** — `TraceContextProcessor` automatically injects `trace_id` and `span_id` into every Monolog log record's `extra` array, enabling one-click navigation from logs to traces in your observability backend
- `monolog_enabled` configuration option (defaults to `true`) — disable with `monolog_enabled: false` when Monolog is not used
- `monolog/monolog` added to `suggest` in `composer.json`
- Auto-detection: processor is only registered when `monolog/monolog` is installed (no error if absent)
- 250 unit tests with 640 assertions (up from 241/622)

## [1.2.1] - 2026-03-12

### Added

- **`traceway.distributed_trace_id` span attribute** — captures the `traceway-trace-id` HTTP header on request spans, enabling distributed trace correlation across services
- `open-telemetry/exporter-otlp` and `php-http/guzzle7-adapter` added to `suggest` in `composer.json` for clearer onboarding

## [1.2.0] - 2026-03-12

### Added

- **Console command auto-instrumentation** — SERVER spans for every `bin/console` command with `process.command`, `process.command.args`, `process.exit_code`, and exception recording
- `console_enabled` and `console_excluded_commands` configuration options
- `symfony/console` added to `require` dependencies
- `ConsoleSubscriber` with `ConsoleEvents::COMMAND`, `ERROR`, and `TERMINATE` hooks
- **Cache pool auto-instrumentation** — INTERNAL spans for `get()` (with hit/miss detection), `delete()`, `clear()`, and `invalidateTags()` operations on all `cache.pool` tagged services
- `cache_enabled` and `cache_excluded_pools` configuration options
- `CacheTracingPass` compiler pass decorates all non-abstract cache pools; tag-aware pools get `TraceableTagAwareCachePool`
- **Twig template auto-instrumentation** — INTERNAL spans for every template render with nested template support (includes, extends)
- `twig_enabled` and `twig_excluded_templates` configuration options for excluding framework templates (e.g. `@WebProfiler/`, `@Debug/`)
- `OpenTelemetryTwigExtension` using Twig's `ProfilerNodeVisitor` to hook into template rendering
- `twig/twig` and `symfony/cache` added to `suggest` and `require-dev` dependencies
- **Messenger PRODUCER spans** — dispatch side now creates a PRODUCER span with `messaging.system`, `messaging.operation.type=publish`, and `messaging.message.class` attributes, giving full lifecycle visibility (publish → process); consume side now also records `messaging.destination.name` from the transport
- **HttpClient `url.path` and `url.scheme` attributes** — CLIENT spans now include parsed URL path and scheme for consistent filtering
- **HttpClient `http.response.body.size` tracking** — `TracedResponse` records response body size from Content-Length header or actual content
- **Doctrine `DbSpanBuilder`** — shared span-building logic extracted from `TraceableConnection` and `TraceableStatement`, eliminating code duplication
- 241 unit tests with 622 assertions (up from 172/419)

### Changed

- `OpenTelemetryTwigExtension` now uses `spl_object_id()` for span matching instead of stack-based template name matching — eliminates mismatch edge cases with duplicate template names
- `TraceableCachePool` validates `CacheInterface` in constructor instead of at method call time — misconfiguration fails early
- `Tracing`/`TracingInterface` `$kind` parameter uses `SpanKind::KIND_*` PHPDoc type instead of `@phpstan-ignore`
- `HttpClientTracingPass` adds `\assert(\is_string($tracerName))` for type safety, consistent with `CacheTracingPass`
- `OpenTelemetryMiddleware` dispatch path now wraps in a PRODUCER span instead of silently injecting context

### Fixed

- `ConsoleSubscriber` scope leak — `__destruct` guard ensures scope is detached when `TERMINATE` event never fires (e.g. fatal error, `exit()` in command)
- `TraceableConnection` and `TraceableStatement` now cache the tracer instance instead of resolving it on every query
- `OpenTelemetryTwigExtension` `__destruct` guard drains spans in LIFO order on shutdown, preventing scope leaks from unmatched `enter()`/`leave()` calls
- `url.query` attribute now omitted when query string is absent instead of being set to `null`

## [1.1.0] - 2026-03-16

### Added

- **Doctrine DBAL auto-instrumentation** — CLIENT spans for every database query with current OTel semantic conventions (`db.system.name`, `db.operation.name`, `db.namespace`, `db.query.text`, `server.address`, `server.port`)
- SQL template recording enabled by default (uses `?` placeholders, never includes parameter values)
- Transaction tracing (`BEGIN`, `COMMIT`, `ROLLBACK` spans)
- Prepared statement tracing via `TraceableStatement`
- `doctrine_enabled` and `doctrine_record_statements` configuration options
- Auto-detection of database system (MySQL, PostgreSQL, SQLite, SQL Server, Oracle)
- Exception recording on query failures
- Backward-compatible Datadog attributes (`db.system`, `db.statement`, `db.operation`, `db.name`) alongside current OTel conventions
- `url.query` attribute on HTTP spans for query parameter tracing
- Code coverage reporting via Codecov in CI
- Codecov badge in README

### Changed

- Upgraded to PHPStan 2.x level 10 (from 1.x level 9) with proper type narrowing
- Migrated from deprecated `TraceAttributes` to `Attributes\*` / `Incubating\Attributes\*` interfaces
- Updated `messaging.operation` to `messaging.operation.type` (current OTel spec)
- `TracedResponse` now finalizes span with status code from `getHeaders()`, `getContent()`, and `toArray()` with defensive try/catch around `getStatusCode()`
- `TraceableHttpClient::reset()` clears cached tracer
- UTF-8 safe SQL truncation in span names via `mb_substr()`
- Replaced `strtok()` with stateless `preg_match()` in `SqlOperationExtractor::extract()`
- Clarified `doctrine_record_statements` config description for raw SQL safety
- Dedicated `TracedResponseTest` covering getInfo, getInnerResponse, getSpan, throw=false, toArray(false), and __destruct
- `OpenTelemetryBundleTest` for getPath(), build(), and VERSION constant
- `HeadersPropagationSetterTest` and `ResponsePropagationSetterTest` unit tests
- Sub-request INTERNAL span tests, incoming trace context propagation tests, `service.version` attribute test
- `ConsumedByWorkerStamp` consume test, empty trace context stamp test
- Doctrine extension registration tests (`doctrine_enabled`, `doctrine_record_statements`, tracer name wiring)
- Extension `prepend()` tests for Messenger middleware auto-registration
- Malformed URL fallback test, REQUEST_TIME_FLOAT start timestamp test
- 172 unit tests with 419 assertions (up from 58/131)

## [1.0.1] - 2026-03-15

### Added

- GitHub Actions CI workflow (PHPStan + PHPUnit across PHP 8.1/8.2/8.4 and Symfony 6.4/7.4/8.0)
- Packagist version and downloads badges in README
- `.editorconfig` for consistent formatting
- `CONTRIBUTING.md` with setup instructions and coding standards

### Fixed

- PHPUnit bootstrap path for standalone repo (`vendor/autoload.php`)
- `.gitattributes` now excludes CI, changelog, and contributor docs from Composer installs

## [1.0.0] - 2026-03-13

### Added

- Automatic HTTP tracing with SERVER spans, route templates, and semantic conventions
- HttpClient instrumentation with CLIENT spans and W3C Trace Context propagation
- Symfony Messenger instrumentation with CONSUMER spans and trace context across transports
- Response propagation (Server-Timing, traceresponse headers)
- `Tracing` helper for one-liner manual span creation via `TracingInterface`
- Body size attributes (`http.request.body.size`, `http.response.body.size`)
- Client IP recording (`client.address`) with GDPR toggle
- Bundle version tracking (`service.version`)
- Sub-request support (INTERNAL spans)
- Exception recording with status and message
- Configurable excluded paths, error status threshold, and per-feature toggles
- Messenger root spans for task-oriented backends (Traceway, Sentry)
- 58 unit tests with 131 assertions

[1.7.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.6.1...v1.7.0
[1.6.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.4.4...v1.5.0
[1.4.4]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.4.3...v1.4.4
[1.4.3]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.3.3...v1.4.0
[1.3.3]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.3.1...v1.3.3
[1.3.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/releases/tag/v1.0.0
