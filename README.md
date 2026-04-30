# OpenTelemetry Symfony Bundle

[![CI](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle/graph/badge.svg)](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle)
[![Packagist Version](https://img.shields.io/packagist/v/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![Packagist Downloads](https://img.shields.io/packagist/dt/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/symfony-%3E%3D6.4-000000.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Pure-PHP OpenTelemetry instrumentation for Symfony — automatic tracing for HTTP, Console, HttpClient, Messenger, Doctrine DBAL, Cache, and Twig, plus Monolog log-trace correlation, OpenTelemetry log export, and opt-in metrics for Messenger processing. No C extension required.

Works with any OpenTelemetry-compatible backend: [Traceway](https://tracewayapp.com), [Jaeger](https://www.jaegertracing.io/), [Zipkin](https://zipkin.io/), [Datadog](https://www.datadoghq.com/), [Grafana Tempo](https://grafana.com/oss/tempo/), [Honeycomb](https://www.honeycomb.io/), and more.

- **Pure PHP** — no C extension required; installs on every managed Symfony host
- **Production-ready** — stable since v1.0, PHPStan level 10 with no baseline, supports Symfony 6.4 LTS through 8.x
- **Correct under load** — Messenger trace context propagates across async queue boundaries, Doctrine DBAL 3 and 4 both CI-tested, re-entrance guards prevent export-path recursion in HttpClient and the log handler

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
# Optional: OTEL_RESOURCE_ATTRIBUTES=service.version=1.0
```

> Use `http/json` unless you have `ext-protobuf` installed — see [Performance](#performance).

That's it. Every HTTP request, console command, outgoing call, Messenger job, DB query, cache operation, and Twig render is now traced.

## What Gets Traced

| Component | Span Kind | What's captured |
|---|---|---|
| **HTTP requests** | SERVER | Route templates (`GET /api/items/{id}`), status codes, body sizes, client IP, exceptions, sub-requests |
| **Console commands** | SERVER | Command name, arguments, exit code, exceptions |
| **HttpClient** | CLIENT | Outgoing requests with W3C context propagation, OTLP endpoint auto-excluded, re-entrance guard |
| **Messenger** | PRODUCER/CONSUMER | Message class, transport, W3C context propagation across async boundaries |
| **Doctrine DBAL** | CLIENT | SQL queries (parameterized), transactions, db system/namespace auto-detection. **DBAL 3.6+ and 4.x both CI-tested** |
| **Cache** | INTERNAL | `get` (hit/miss), `delete`, `invalidateTags` with pool name. Requires `symfony/cache` |
| **Twig** | INTERNAL | Template name, nested includes. Requires `twig/twig` |
| **Monolog: log correlation** | — | Inject `trace_id` + `span_id` into every log record. Requires `monolog/monolog` |
| **Monolog: log export** | — | Export log records via the OTel Logs API with native trace correlation and per-channel instrumentation scope. Requires `symfony/monolog-bundle`. **Off by default** |

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

    monolog_enabled: true            # inject trace_id/span_id into log records

    log_export_enabled: false        # export logs via OTel Logs API (requires symfony/monolog-bundle)
    log_export_level: debug          # debug | info | notice | warning | error | critical | alert | emergency

    # `metrics` is intentionally nested. The rest of the bundle still uses
    # flat keys for 1.x, but metrics landed nested from day one to align with
    # the planned v2.0 config rework. Flat keys for tracing/logs will migrate
    # to the nested shape in v2.0 — this is not an inconsistency, it is a
    # forward-compatible choice.
    metrics:
        enabled: false                 # register MeterRegistry for manual instrumentation
        meter_name: 'opentelemetry-symfony'
        messenger:
            enabled: false             # emit messaging.process.duration / messaging.client.consumed.messages
            excluded_queues: []
```

### Environment Variables

| Variable | Example | Description |
|---|---|---|
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` | Enable SDK auto-initialization |
| `OTEL_SERVICE_NAME` | `my-symfony-app` | Service name shown in your backend |
| `OTEL_TRACES_EXPORTER` | `otlp` | Traces exporter (`otlp`, `zipkin`, `console`, `none`) |
| `OTEL_LOGS_EXPORTER` | `otlp` | Logs exporter (`otlp`, `console`, `none`) — only used when `log_export_enabled: true` |
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

Mock in tests with `$this->createStub(TracingInterface::class)` and have `trace()` invoke the callback directly.

## Metrics

**Off by default.** Enable to export OpenTelemetry metrics alongside traces, with opt-in automatic instrumentation for Symfony Messenger.

```yaml
open_telemetry:
    metrics:
        enabled: true
        meter_name: 'opentelemetry-symfony'
        messenger:
            enabled: true
            excluded_queues: []
```

### What Gets Measured

Emitted on the consume path of the Messenger bus:

| Instrument | Kind | Unit | Attributes |
|---|---|---|---|
| `messaging.process.duration` | Histogram | `s` | `messaging.system`, `messaging.operation.name`, `messaging.operation.type`, `messaging.destination.name`, `error.type` on failure |
| `messaging.client.consumed.messages` | Counter | `{message}` | Same as above |

Names and attributes follow the [OTel messaging metrics semantic conventions](https://opentelemetry.io/docs/specs/semconv/messaging/messaging-metrics/). All messaging metrics and attributes are currently **Development** in the spec. The general `error.type` attribute is Stable. Service identity (`service.name`, `service.namespace`, `service.version`) comes from the OTel resource, set via `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES`, not from metric name prefixing.

`messenger.excluded_queues` is matched on `ReceivedStamp::getTransportName()` (consume path only). Dispatch-side exclusion and dispatch metrics (`messaging.client.sent.messages`, `messaging.client.operation.duration`) are out of scope for this first metrics drop.

### Manual Instrumentation

Inject `MeterRegistryInterface` to record your own counters, histograms, and up/down counters without touching the `MeterProvider` directly:

```php
use OpenTelemetry\API\Metrics\CounterInterface;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistryInterface;

final class MediaDownloader
{
    private readonly CounterInterface $downloads;

    public function __construct(MeterRegistryInterface $metrics)
    {
        $this->downloads = $metrics->counter(
            'media.download.count',
            description: 'Media downloads by outcome',
        );
    }

    public function download(string $url): void
    {
        try {
            // ... download logic
            $this->downloads->add(1, ['outcome' => 'success']);
        } catch (\Throwable $e) {
            $type = $e::class;
            if (str_contains($type, '@anonymous')) {
                $type = get_parent_class($e) ?: \Throwable::class;
            }
            $this->downloads->add(1, ['outcome' => 'error', 'error.type' => $type]);
            throw $e;
        }
    }
}
```

The registry caches instruments per name, so repeated `->counter('x')` calls return the same instance. When the OTel SDK is not configured, the NoOp meter provider returns no-op instruments and calls silently do nothing — safe to inject unconditionally.

The `@anonymous` guard normalises anonymous class names to their parent: `$e::class` would otherwise embed a filesystem path (`class@anonymous\0/var/www/src/Foo.php:42$0`), which leaks code locations and explodes label cardinality.

### Environment Variables

| Variable | Example | Description |
|---|---|---|
| `OTEL_METRICS_EXPORTER` | `otlp` | Metrics exporter (`otlp`, `console`, `none`) — only used when `metrics.enabled: true` |
| `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` | `http://localhost:4318/v1/metrics` | Override the generic `OTEL_EXPORTER_OTLP_ENDPOINT` for metrics |

## Performance

Near-zero overhead when the SDK is inactive — every component short-circuits via `isEnabled()`. When tracing is on, almost all cost is in span export, not instrumentation. PHP-FPM has no background thread, so `BatchSpanProcessor` flushes during request shutdown.

**Use `http/json` unless you have `ext-protobuf` installed.** PHP's native `json_encode()` is faster than the pure-PHP protobuf encoder, which adds significant CPU overhead under load. Switch to `http/protobuf` only with the C extension installed.

For high-traffic apps: run a local OTel Collector at `localhost:4318` (sub-ms latency) and let it forward asynchronously, enable head sampling with `OTEL_TRACES_SAMPLER=parentbased_traceidratio` + `OTEL_TRACES_SAMPLER_ARG=0.1`, and use `excluded_paths` / `cache_excluded_pools` to drop noisy spans.

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
