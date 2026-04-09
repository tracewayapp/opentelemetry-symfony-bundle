# OpenTelemetry Symfony Bundle

[![CI](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle/graph/badge.svg)](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle)
[![Packagist Version](https://img.shields.io/packagist/v/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![Packagist Downloads](https://img.shields.io/packagist/dt/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/symfony-%3E%3D6.4-000000.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Pure-PHP OpenTelemetry instrumentation for Symfony — automatic tracing for HTTP, Console, HttpClient, Messenger, Doctrine DBAL, Cache, and Twig, plus Monolog log-trace correlation. No C extension required.

Works with any OpenTelemetry-compatible backend: [Traceway](https://tracewayapp.com), [Jaeger](https://www.jaegertracing.io/), [Zipkin](https://zipkin.io/), [Datadog](https://www.datadoghq.com/), [Grafana Tempo](https://grafana.com/oss/tempo/), [Honeycomb](https://www.honeycomb.io/), and more.

## Quick Start

```bash
composer require traceway/opentelemetry-symfony
```

```env
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=my-symfony-app
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

> Use `http/json` by default. Switch to `http/protobuf` only if you have `ext-protobuf` installed — see [Performance](#performance).

Optionally, it is possible to also provide a version identifier using e.g.:

```env
OTEL_RESOURCE_ATTRIBUTES=service.version=1.0
```

That's it — every HTTP request, console command, outgoing HTTP call, Messenger job, DB query, cache operation, and Twig render is now traced automatically.

## What Gets Traced

| Component | Span Kind | What's captured |
|---|---|---|
| **HTTP requests** | SERVER | Route templates (`GET /api/items/{id}`), status codes, body sizes, client IP, exceptions, sub-requests |
| **Console commands** | SERVER | Command name, arguments, exit code, exceptions |
| **HttpClient** | CLIENT | Outgoing requests with W3C context propagation, OTLP endpoint auto-excluded, re-entrance guard |
| **Messenger** | PRODUCER/CONSUMER | Message class, transport, W3C context propagation across async boundaries |
| **Doctrine DBAL** | CLIENT | SQL queries (parameterized), transactions, db system/namespace auto-detection. Requires `doctrine/dbal` ^3.6 or ^4.0 |
| **Cache** | INTERNAL | `get` (hit/miss), `delete`, `invalidateTags` with pool name. Requires `symfony/cache` |
| **Twig** | INTERNAL | Template name, nested includes. Requires `twig/twig` |
| **Monolog** | — | Injects `trace_id` + `span_id` into every log record. Requires `monolog/monolog` |

Additional: response propagation (Server-Timing headers), `Tracing` helper for manual spans, full [OTel semantic conventions](https://opentelemetry.io/docs/specs/semconv/http/).

## Requirements

- PHP >= 8.1, Symfony >= 6.4, OpenTelemetry PHP SDK >= 1.0
- Doctrine DBAL >= 3.6 *(optional)*, Twig >= 3.0 *(optional)*

## Configuration

All options are optional — the bundle works out of the box with zero configuration. Create `config/packages/open_telemetry.yaml` to customize:

```yaml
open_telemetry:
    traces_enabled: true
    tracer_name: 'opentelemetry-symfony'

    excluded_paths: [/health, /_profiler, /_wdt]
    record_client_ip: true           # disable for GDPR
    error_status_threshold: 500      # 400-599

    console_enabled: true
    console_excluded_commands: [cache:clear, assets:install]

    http_client_enabled: true
    http_client_excluded_hosts: []   # OTLP endpoint is auto-excluded

    messenger_enabled: true
    messenger_root_spans: false      # true = standalone traces per consumed message

    doctrine_enabled: true
    doctrine_record_statements: true # false = hide SQL from spans

    cache_enabled: true
    cache_excluded_pools: [cache.system, cache.validator, cache.serializer]

    twig_enabled: true
    twig_excluded_templates: ['@WebProfiler/', '@Debug/']

    monolog_enabled: true
```

### Environment Variables

| Variable | Example | Description |
|---|---|---|
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` | Enable SDK auto-initialization |
| `OTEL_SERVICE_NAME` | `my-symfony-app` | Service name shown in your backend |
| `OTEL_TRACES_EXPORTER` | `otlp` | Exporter type (`otlp`, `zipkin`, `console`, `none`) |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Collector/backend endpoint |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/json` | Protocol (`http/json`, `http/protobuf`, `grpc`) |

See the [OpenTelemetry SDK docs](https://opentelemetry.io/docs/languages/php/exporters/) for all available options.

## Manual Instrumentation

Inject `TracingInterface` for one-liner span creation:

```php
use Traceway\OpenTelemetryBundle\TracingInterface;

class OrderService
{
    public function __construct(private readonly TracingInterface $tracing) {}

    public function process(int $orderId): void
    {
        $this->tracing->trace('order.validate', function () use ($orderId) {
            // validation logic...
        });

        $this->tracing->trace('order.fulfill', function () {
            $this->tracing->trace('inventory.reserve', fn () => $this->reserve());
            $this->tracing->trace('payment.charge', fn () => $this->charge());
        });
    }
}
```

Mock in tests:

```php
$tracing = $this->createStub(TracingInterface::class);
$tracing->method('trace')->willReturnCallback(fn ($name, $cb) => $cb());
```

## Performance

The bundle adds **near-zero overhead** when the SDK is not active — every component checks `isEnabled()` and short-circuits immediately.

When tracing is active, most overhead comes from the SDK's span export, not instrumentation. PHP-FPM has no background thread, so `BatchSpanProcessor` flushes during request shutdown.

### Export Protocol

| Setup | Recommendation |
|---|---|
| `ext-protobuf` **installed** | Use `http/protobuf` — smallest payloads, fastest serialization |
| `ext-protobuf` **not installed** | Use `http/json` — `json_encode()` is native C in PHP, much faster than pure-PHP protobuf |

> **Do not** use `http/protobuf` without `ext-protobuf`. The pure-PHP protobuf encoder adds significant CPU overhead under load.

### Tips

1. **Local OTel Collector** — export to `localhost:4318` (sub-ms latency), let the Collector forward asynchronously
2. **Sampling** — `OTEL_TRACES_SAMPLER=parentbased_traceidratio` + `OTEL_TRACES_SAMPLER_ARG=0.1` traces 10% of requests
3. **Install `ext-protobuf`** — if using `http/protobuf`, the C extension reduces serialization overhead dramatically ([pecl.php.net/protobuf](https://pecl.php.net/package/protobuf))
4. **Exclude noisy paths** — use `excluded_paths` and `cache_excluded_pools` to reduce span volume

## Contributing

```bash
git clone https://github.com/tracewayapp/opentelemetry-symfony-bundle.git
cd opentelemetry-symfony-bundle
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

## License

[MIT](LICENSE)
