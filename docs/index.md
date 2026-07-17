# Documentation

Pure-PHP OpenTelemetry instrumentation for Symfony. Supports PHP 8.1+ on Symfony 6.4 LTS / 7.x / 8.x. No C extension required.

## What gets traced

| Component | Span Kind | What's captured |
|---|---|---|
| **HTTP requests** | SERVER | Route templates (`GET /api/items/{id}`), status codes, body sizes, client IP, network peer, exceptions, sub-requests |
| **Console commands** | INTERNAL | Command name, argv, pid, exit code, exceptions |
| **HttpClient** | CLIENT | Outgoing requests with W3C context propagation, OTLP endpoint auto-excluded, re-entrance guard |
| **Messenger** | PRODUCER/CONSUMER | Message class, transport, W3C context propagation across async boundaries |
| **Scheduler** | CONSUMER | Schedule name, trigger, next-run, cancellation marker. Requires `symfony/scheduler` |
| **Mailer** | PRODUCER + CLIENT | `create` span on `MailerInterface::send`, `send` span on the transport. Recipient count, message-id, `X-Transport` routing |
| **Doctrine DBAL** | CLIENT | Parameterised SQL (opt-in), transactions, db system/namespace auto-detection. DBAL 3.6+ and 4.x CI-tested |
| **Cache** | INTERNAL | `get` (hit/miss), `delete`, `invalidateTags` with pool name. Requires `symfony/cache` |
| **Twig** | INTERNAL | Template name, nested includes. Requires `twig/twig` |
| **Monolog** | — | Inject `trace_id`, `span_id` + `trace_flags` into every log record. Opt-in OTel Logs API export with per-channel scope |

Also: Server-Timing response headers and full [semantic-conventions conformance](semantic-conventions.md).

## Guide

- [Configuration](configuration.md) — full reference plus environment variables
- [Semantic conventions](semantic-conventions.md) — conformance statement, deviations, limitations
- [Metrics](metrics.md) — instrument list, manual metrics, exemplars
- [AWS X-Ray](aws-xray.md) — `propagator` / `id_generator` keys, ADOT setup
- [Doctor](doctor.md) — `traceway:doctor` command, JSON CI output, custom checks
- [Performance](performance.md) — overhead, sampling, exporter choice

## Project

- [Changelog](../CHANGELOG.md)
- [Upgrade from v1.x](../UPGRADE-2.0.md)
- [Contributing](../CONTRIBUTING.md)
- [Deployment notes](../DEPLOYMENT.md)
- [Issues](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues)
- [License](../LICENSE) (MIT)
