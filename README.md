# OpenTelemetry Symfony Bundle

[![CI](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle/graph/badge.svg)](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle)
[![Packagist Version](https://img.shields.io/packagist/v/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![Packagist Downloads](https://img.shields.io/packagist/dt/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/symfony-%3E%3D6.4-000000.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Pure-PHP OpenTelemetry instrumentation for Symfony, **no C extension required**. Automatic HTTP, Console, HttpClient, Messenger, Doctrine DBAL, Cache, and Twig tracing with a lightweight `Tracing` helper, route templates, response propagation, and full semantic conventions.

Works with any OpenTelemetry-compatible backend: [Traceway](https://tracewayapp.com), [Jaeger](https://www.jaegertracing.io/), [Zipkin](https://zipkin.io/), [Datadog](https://www.datadoghq.com/), [Sentry](https://sentry.io/), [Grafana Tempo](https://grafana.com/oss/tempo/), [Honeycomb](https://www.honeycomb.io/), and more.

## Quick Start

```bash
composer require traceway/opentelemetry-symfony
```

Set the environment variables for your OTel backend:

```env
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=my-symfony-app
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
```

That's it. Every HTTP request, console command, outgoing HttpClient call, Messenger job, cache operation, and Twig template render is now traced automatically.

## Features

- **Automatic HTTP tracing** — SERVER spans for every request with route templates (`GET /api/items/{id}`), body size attributes, semantic conventions, sub-request support, and exception recording
- **Console command tracing** — SERVER spans for every `bin/console` command with command name, exit code, and exception recording
- **HttpClient instrumentation** — CLIENT spans for every outgoing HTTP request with W3C Trace Context propagation into downstream services
- **Response propagation** — injects trace context into response headers (Server-Timing, traceresponse) for browser-side correlation
- **Symfony Messenger instrumentation** — automatic PRODUCER spans on dispatch and CONSUMER spans on consume with W3C Trace Context propagation across transports
- **Doctrine DBAL instrumentation** — CLIENT spans for every database query with `db.system.name`, `db.operation.name`, `db.namespace`, `db.query.text`, and `server.address`/`server.port`
- **Cache pool instrumentation** — INTERNAL spans for cache `get` (with hit/miss detection), `delete`, and `invalidateTags` operations with pool name attributes
- **Twig template instrumentation** — INTERNAL spans for every template render, including nested templates and includes, with template name attributes
- **`Tracing` helper** — one-liner span creation for manual instrumentation (cache, HTTP calls, etc.)
- **Fully configurable** — exclude paths, toggle features, set error thresholds, control root span behavior, toggle SQL recording
- **No C extension required** — works on any PHP 8.1+ hosting, unlike the official `ext-opentelemetry` based package

## Requirements

- PHP >= 8.1
- Symfony >= 6.4
- OpenTelemetry PHP SDK >= 1.0
- Doctrine DBAL >= 4.0 *(optional, for database tracing)*
- Twig >= 3.0 *(optional, for template tracing)*

## Installation

```bash
composer require traceway/opentelemetry-symfony
```

If your application doesn't use Symfony Flex, enable the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    Traceway\OpenTelemetryBundle\OpenTelemetryBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/open_telemetry.yaml` (all options are optional — the bundle works out of the box with zero configuration):

```yaml
open_telemetry:
    # Enable automatic HTTP trace instrumentation (default: true)
    traces_enabled: true

    # Instrumentation library name reported to your OTel backend (default: 'opentelemetry-symfony')
    tracer_name: 'opentelemetry-symfony'

    # URL path prefixes to exclude from tracing
    excluded_paths:
        - /health
        - /_profiler
        - /_wdt

    # Record client IP on spans — disable for GDPR compliance (default: true)
    record_client_ip: true

    # HTTP status codes >= this value are marked as errors (default: 500, range: 400-599)
    error_status_threshold: 500

    # Instrument Symfony Console: SERVER spans for console commands (default: true)
    console_enabled: true

    # Console command names to exclude from tracing
    console_excluded_commands:
        - cache:clear
        - assets:install

    # Instrument Symfony HttpClient: CLIENT spans for outgoing requests (default: true)
    http_client_enabled: true

    # Instrument Symfony Messenger (default: true)
    messenger_enabled: true

    # Create root spans for consumed messages instead of linking to the
    # dispatching trace (default: false)
    messenger_root_spans: false

    # Instrument Doctrine DBAL: CLIENT spans for database queries (default: true)
    # Requires doctrine/dbal ^4.0
    doctrine_enabled: true

    # Record SQL on spans (default: true)
    # Prepared statements use ? placeholders; query()/exec() record raw SQL
    doctrine_record_statements: true

    # Instrument Symfony Cache: INTERNAL spans for get/delete (default: true)
    # Requires symfony/cache
    cache_enabled: true

    # Cache pool service IDs to exclude from tracing
    cache_excluded_pools:
        - cache.system
        - cache.validator
        - cache.serializer

    # Instrument Twig: INTERNAL spans for template rendering (default: true)
    # Requires twig/twig
    twig_enabled: true

    # Template name prefixes to exclude from tracing
    twig_excluded_templates:
        - '@WebProfiler/'
        - '@Debug/'
```

### Environment Variables

The OpenTelemetry PHP SDK is configured via standard `OTEL_*` environment variables. Set these in your `.env`, server config, or Docker environment:

| Variable | Example | Description |
|---|---|---|
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` | Enable SDK auto-initialization |
| `OTEL_SERVICE_NAME` | `my-symfony-app` | Service name shown in your backend |
| `OTEL_TRACES_EXPORTER` | `otlp` | Exporter type (`otlp`, `zipkin`, `console`, `none`) |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Your collector/backend endpoint |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` | Protocol (`http/protobuf`, `http/json`, `grpc`) |

See the [OpenTelemetry SDK docs](https://opentelemetry.io/docs/languages/php/exporters/) for all available options.

## Usage

### Automatic HTTP Tracing

Once installed, every HTTP request automatically gets a SERVER span with:

- Route template naming (`GET /api/users/{id}` instead of `GET /api/users/42`)
- Request/response attributes following [OTel semantic conventions](https://opentelemetry.io/docs/specs/semconv/http/)
- Query string attribute (`url.query`)
- Body size attributes (`http.request.body.size`, `http.response.body.size`)
- Client IP recording (`client.address`)
- Bundle version tracking (`service.version`)
- Response propagation (Server-Timing headers for browser-side tracing)
- Exception recording with stack traces
- Sub-request support (INTERNAL spans)
- W3C Trace Context propagation from incoming headers

### Automatic Console Command Tracing

Every `bin/console` command automatically gets a SERVER span with:

- Span name: the command name (e.g. `app:import-users`)
- `process.command` attribute with the command name
- `process.command.args` attribute with the input arguments (when non-empty)
- `process.exit_code` attribute with the exit code
- Exception recording on command failure
- Non-zero exit codes automatically marked as errors

Exclude noisy framework commands from tracing:

```yaml
open_telemetry:
    console_excluded_commands:
        - cache:clear
        - cache:warmup
        - assets:install
```

### Automatic HttpClient Tracing

When `symfony/http-client` is installed, every outgoing HTTP request automatically gets a CLIENT span with:

- Span name: `GET api.example.com`
- Request attributes (`http.request.method`, `url.full`, `url.path`, `url.scheme`, `server.address`, `server.port`)
- Response status code, error detection, and `http.response.body.size` tracking
- W3C Trace Context propagation into outgoing request headers (so downstream services are linked in the same trace)

Works with all Symfony HttpClient instances, including scoped clients.

### Automatic Messenger Tracing

When `symfony/messenger` is installed, the bundle automatically:

- **On dispatch:** creates a PRODUCER span (`messaging.operation.type=publish`) and injects W3C Trace Context into the message envelope so it survives serialization across transports
- **On consume:** creates a CONSUMER span (`messaging.operation.type=process`) with messaging attributes (`messaging.system`, `messaging.message.class`, `messaging.destination.name`)

#### Root Spans for Background Jobs

By default, consumed messages are linked as children of the dispatching trace. If your backend treats root spans as independent tasks/jobs (e.g. Traceway Tasks, Sentry Crons), enable root spans:

```yaml
open_telemetry:
    messenger_root_spans: true
```

Each consumed message will then start its own trace, appearing as a standalone task in your backend.

### Automatic Doctrine DBAL Tracing

When `doctrine/dbal` (^4.0) is installed, every database query automatically gets a CLIENT span with:

- Span name: the SQL template (e.g. `SELECT * FROM users WHERE id = ?`), truncated at 120 chars
- Database attributes following [OTel database semantic conventions](https://opentelemetry.io/docs/specs/semconv/database/) (`db.system.name`, `db.operation.name`, `db.namespace`, `db.query.text`)
- Server attributes (`server.address`, `server.port`)
- Exception recording on query failure
- Transaction spans (`BEGIN`, `COMMIT`, `ROLLBACK`)

SQL is recorded by default as `db.query.text`. Prepared statements use `?` placeholders (e.g. `SELECT * FROM users WHERE id = ?`), so parameter values are not recorded. However, `query()`/`exec()` calls record raw SQL which may contain literal values. Disable SQL recording if raw SQL may contain sensitive data (span name falls back to `{OPERATION} {dbName}`):

```yaml
open_telemetry:
    doctrine_record_statements: false
```

The bundle auto-detects the database system (MySQL, PostgreSQL, SQLite, SQL Server, Oracle) from the Doctrine DBAL driver.

### Automatic Cache Tracing

When `symfony/cache` is installed, every cache pool operation automatically gets an INTERNAL span with:

- Span name: `cache.get {key}`, `cache.delete {key}`, or `cache.clear`
- `cache.key` attribute with the cache key
- `cache.pool` attribute with the pool name (e.g. `cache.app`)
- `cache.hit` attribute (boolean) on `get()` operations — detects whether the callback was invoked
- `cache.tags` attribute on `invalidateTags()` operations
- Exception recording on cache failures

All services tagged with `cache.pool` are automatically decorated. Tag-aware pools also trace `invalidateTags()`.

Exclude noisy framework pools from tracing:

```yaml
open_telemetry:
    cache_excluded_pools:
        - cache.system
        - cache.validator
        - cache.serializer
```

### Automatic Twig Template Tracing

When `twig/twig` is installed, every template render automatically gets an INTERNAL span with:

- Span name: `twig.render {template}` (e.g. `twig.render home.html.twig`)
- `twig.template` attribute with the template name
- Nested template spans for includes and extends (parent-child linking)
- Only template-level rendering is traced (blocks and macros are not individually traced to keep span volume manageable)
- Configurable template exclusion via `twig_excluded_templates` to filter out framework templates

### Manual Instrumentation with `Tracing`

Inject `TracingInterface` into any service for one-liner span creation:

```php
use Traceway\OpenTelemetryBundle\TracingInterface;
use OpenTelemetry\API\Trace\SpanKind;

class OrderService
{
    public function __construct(
        private readonly TracingInterface $tracing,
    ) {}

    public function processOrder(int $orderId): void
    {
        // Simple span
        $this->tracing->trace('order.validate', function () use ($orderId) {
            // validation logic...
        });

        // Span with attributes and kind
        $result = $this->tracing->trace('db.query', fn () => $this->db->query('SELECT ...'), [
            'db.system.name' => 'mysql',
            'db.query.text' => 'SELECT * FROM orders WHERE id = ?',
        ], SpanKind::KIND_CLIENT);

        // Nested spans — parent-child linking is automatic
        $this->tracing->trace('order.fulfill', function () {
            $this->tracing->trace('inventory.reserve', fn () => $this->reserve());
            $this->tracing->trace('payment.charge', fn () => $this->charge());
            $this->tracing->trace('email.send', fn () => $this->notify());
        });
    }
}
```

The `trace()` method:
- Creates and activates a span
- Runs the callback
- Sets `OK` status on success, or records the exception and sets `ERROR` on failure
- Ends the span and detaches the scope
- Returns the callback's return value

## Mocking in Tests

The bundle provides `TracingInterface` so you can easily mock tracing in your application tests:

```php
$tracing = $this->createStub(TracingInterface::class);
$tracing->method('trace')->willReturnCallback(fn ($name, $cb) => $cb());
```

## Contributing

```bash
git clone https://github.com/tracewayapp/opentelemetry-symfony-bundle.git
cd opentelemetry-symfony-bundle
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

## License

This bundle is released under the [MIT License](LICENSE).
