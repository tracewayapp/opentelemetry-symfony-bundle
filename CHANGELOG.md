# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.1.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/releases/tag/v1.0.0
