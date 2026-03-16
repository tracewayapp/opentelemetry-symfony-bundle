# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.1]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tracewayapp/opentelemetry-symfony-bundle/releases/tag/v1.0.0
