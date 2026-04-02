# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.4.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.3.3...v1.4.0
[1.3.3]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.3.1...v1.3.3
[1.3.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/releases/tag/v1.0.0
