<p align="center">
  <a href="https://tracewayapp.com">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/tracewayapp/traceway/main/Traceway%20Logo%20White.png" />
      <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/tracewayapp/traceway/main/Traceway%20Logo.png" />
      <img src="https://raw.githubusercontent.com/tracewayapp/traceway/main/Traceway%20Logo.png" alt="Traceway Logo" width="200" />
    </picture>
  </a>
</p>

# OpenTelemetry Symfony Bundle

[![CI](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/tracewayapp/opentelemetry-symfony-bundle/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle/graph/badge.svg)](https://codecov.io/gh/tracewayapp/opentelemetry-symfony-bundle)
[![Packagist Version](https://img.shields.io/packagist/v/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![Packagist Downloads](https://img.shields.io/packagist/dt/traceway/opentelemetry-symfony.svg)](https://packagist.org/packages/traceway/opentelemetry-symfony)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/symfony-%3E%3D6.4-000000.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Discord](https://img.shields.io/badge/Discord-Join-5865F2?logo=discord&logoColor=white)](https://discord.gg/9tPn2SB3)

Pure-PHP OpenTelemetry instrumentation for Symfony — automatic tracing for HTTP, Console, HttpClient, Messenger, Mailer, Scheduler, Doctrine DBAL, Cache, and Twig, plus Monolog log-trace correlation, OpenTelemetry log export, and opt-in metrics for Messenger, DBAL, HTTP server/client, and Mailer. No C extension required.

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

It is also possible to use Symfony's DotEnv component in combination with the `open_telemetry.sdk` bundle configuration section for `OTEL_PHP_AUTOLOAD_ENABLED`,  `OTEL_RESOURCE_ATTRIBUTES` and `OTEL_EXPORTER_OTLP_HEADERS` to set the relevant SDK configuration, see [Configuration](#configuration) for more details. Using configuration setting `open_telemetry.sdk.autoload_enabled` registers the OpenTelemetry SDK without needing to set `OTEL_PHP_AUTOLOAD_ENABLED` before Composer's autoload is loaded. Furthermore, using `open_telemetry.sdk.resource_attributes` allows `OTEL_RESOURCE_ATTRIBUTES` to be set more conveniently and also supports the use of Symfony Secrets and the same applies with `open_telemetry.sdk.exporter_otlp_headers` for `OTEL_EXPORTER_OTLP_HEADERS`.   

> Use `http/json` unless you have `ext-protobuf` installed — see [Performance](#performance).

With Symfony Flex the bundle auto-registers; without Flex, add `Traceway\OpenTelemetryBundle\OpenTelemetryBundle::class => ['all' => true]` to `config/bundles.php`.

That's it. Every HTTP request, console command, outgoing call, Messenger job, DB query, cache operation, and Twig render is now traced.

## What Gets Traced

| Component | Span Kind | What's captured |
|---|---|---|
| **HTTP requests** | SERVER | Route templates (`GET /api/items/{id}`), status codes, body sizes, client IP, exceptions, sub-requests |
| **Console commands** | SERVER | Command name, arguments, exit code, exceptions |
| **HttpClient** | CLIENT | Outgoing requests with W3C context propagation, OTLP endpoint auto-excluded, re-entrance guard |
| **Messenger** | PRODUCER/CONSUMER | Message class, transport, W3C context propagation across async boundaries |
| **Scheduler** | CONSUMER | Per scheduled-task run: schedule name, trigger expression, next-run, cancellation marker. Requires `symfony/scheduler`. Messenger spans for scheduled envelopes are suppressed automatically |
| **Mailer** | PRODUCER + CLIENT | Two-span split: PRODUCER on `MailerInterface::send` and CLIENT on the transport. Recipient count, message-id, `X-Transport` routing. Subject opt-in. Requires `symfony/mailer` |
| **Doctrine DBAL** | CLIENT | SQL queries (parameterized), transactions, db system/namespace auto-detection. **DBAL 3.6+ and 4.x both CI-tested** |
| **Cache** | INTERNAL | `get` (hit/miss), `delete`, `invalidateTags` with pool name. Requires `symfony/cache` |
| **Twig** | INTERNAL | Template name, nested includes. Requires `twig/twig` |
| **Monolog** | — | Inject `trace_id` + `span_id` into every log record (`monolog/monolog`). Opt-in OTel Logs API export with per-channel instrumentation scope (`symfony/monolog-bundle`, **off by default**) |

Also: Server-Timing response headers, full [OTel semantic conventions](https://opentelemetry.io/docs/specs/semconv/http/).

## Configuration

All options are optional — the bundle works out of the box with zero configuration. Create `config/packages/open_telemetry.yaml` to customize:

```yaml
open_telemetry:
    traces:
        enabled: true
        tracer_name: 'opentelemetry-symfony'

        excluded_paths: [/health, /_profiler, /_wdt]
        record_client_ip: true                # disable for GDPR
        error_status_threshold: 500           # 400-599

        console:
            enabled: true
            excluded_commands: [cache:clear, assets:install]

        http_client:
            enabled: true
            excluded_hosts: []                # OTLP endpoint is auto-excluded

        messenger:
            enabled: true
            root_spans: false                 # true = standalone traces per consumed message

        scheduler:
            enabled: true                     # suppresses parallel Messenger spans for scheduled tasks

        mailer:
            enabled: true
            record_subject: false             # subjects can be PII

        doctrine:
            enabled: true
            record_statements: true           # false = hide SQL from spans

        cache:
            enabled: true
            excluded_pools: [cache.system, cache.validator, cache.serializer]

        twig:
            enabled: true
            excluded_templates: ['@WebProfiler/', '@Debug/']

    metrics:
        enabled: false
        meter_name: 'opentelemetry-symfony'

        messenger:
            enabled: false
            excluded_queues: []

        doctrine:
            enabled: false

        http_server:
            enabled: false
            excluded_paths: []                # same prefix-match rules as tracing excluded_paths

        http_client:
            enabled: false
            excluded_hosts: []                # OTLP endpoint is auto-excluded

        mailer:
            enabled: false

    logs:
        correlation:
            enabled: true                     # inject trace_id/span_id into log records

        export:
            enabled: false                    # OTel Logs API export (requires symfony/monolog-bundle)
            level: debug
            capture_code_attributes: false    # fallback debug_backtrace when IntrospectionProcessor is absent
            unprefixed_attributes: true       # flat context/extra attributes (matches Java/Python/.NET/JS)
    sdk:
        enabled: false                        # If enabled, this section can be used to configure OpenTelemetry SDK variables easier and adds support for Symfony Secrets. It defaults to `false`. It sets environment variables during bundle boot method to ensure they are set on a compiled container
        autoload_enabled: false               # If `true` OTEL_PHP_AUTOLOAD_ENABLED will be set automatically and load `_autoload.php`, if and only if Configuration::getBoolean(Variables::OTEL_PHP_AUTOLOAD_ENABLED) returns false to avoid duplicate initialization. It defaults to `false`.
        use_putenv: false                     # If `true` environment variables set by the SDK section will also use putenv(). Otherwise, only $_SERVER and $_ENV is used. Beware that putenv() is not thread safe, that\'s why it\'s not enabled by default.
        resource_attributes: []               # Merged/replaced key and value pairs with existing OTEL_RESOURCE_ATTRIBUTES variable. It defaults to an empty array. Example: "{'server.version': '1.0'}".
        exporter_otlp_headers: []             # Merged/replaced key and value pairs with existing OTEL_EXPORTER_OTLP_HEADERS variable. It defaults to an empty array. Example: "{'Authorization': 'api-key'}".
```

> Upgrading from v1.x? See [UPGRADE-2.0.md](UPGRADE-2.0.md) for the flat→nested mapping and migration notes.

### Environment Variables

| Variable | Example | Description |
|---|---|---|
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` | Enable SDK auto-initialization |
| `OTEL_SERVICE_NAME` | `my-symfony-app` | Service name shown in your backend |
| `OTEL_TRACES_EXPORTER` | `otlp` | Traces exporter (`otlp`, `zipkin`, `console`, `none`) |
| `OTEL_LOGS_EXPORTER` | `otlp` | Logs exporter (`otlp`, `console`, `none`) — only used when `logs.export.enabled: true` |
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

**Off by default.** Enable to export OpenTelemetry metrics alongside traces, with opt-in automatic instrumentation for Messenger, Doctrine DBAL, HTTP server/client, and Mailer.

```yaml
open_telemetry:
    metrics:
        enabled: true
        meter_name: 'opentelemetry-symfony'
        messenger:
            enabled: true
            excluded_queues: []
        doctrine:
            enabled: true
```

### What Gets Measured

| Instrument | Kind | Unit | Source | Attributes |
|---|---|---|---|---|
| `messaging.process.duration` | Histogram | `s` | Messenger consume | `messaging.system`, `messaging.operation.name`, `messaging.operation.type`, `messaging.destination.name`, `error.type` on failure |
| `messaging.client.consumed.messages` | Counter | `{message}` | Messenger consume | Same as above |
| `messaging.client.operation.duration` | Histogram | `s` | Messenger dispatch | Same shape, `messaging.operation.{name,type}` = `send`, destination derived from `SentStamp::getSenderAlias()` (falls back to sender FQCN) |
| `messaging.client.sent.messages` | Counter | `{message}` | Messenger dispatch | Same as above |
| `db.client.operation.duration` | Histogram | `s` | DBAL connection | `db.system.name`, `db.namespace`, `server.address`, `server.port`, `db.operation.name`, `db.collection.name` (when extractable), `error.type` on failure |
| `http.server.request.duration` | Histogram | `s` | HTTP server | `http.request.method`, `url.scheme`, `http.route` if matched, `http.response.status_code`, `server.address`, `server.port`, `error.type` on failure |
| `http.server.active_requests` | UpDownCounter | `{request}` | HTTP server | `http.request.method`, `url.scheme`, `server.address`, `server.port` |
| `http.server.request.body.size` | Histogram | `By` | HTTP server | Same as duration (emitted when `Content-Length` is set) |
| `http.server.response.body.size` | Histogram | `By` | HTTP server | Same as duration (emitted when `Content-Length` is set) |

Names and attributes follow OTel semantic conventions ([messaging](https://opentelemetry.io/docs/specs/semconv/messaging/messaging-metrics/), [database](https://opentelemetry.io/docs/specs/semconv/database/database-metrics/), [HTTP](https://opentelemetry.io/docs/specs/semconv/http/http-metrics/)). `http.server.request.duration` and `error.type` are Stable; the rest are Development.

- **HTTP server** — only main requests are measured; sub-requests are covered by the main duration. Service identity comes from the OTel resource (`OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES`), not from metric name prefixing.
- **Messenger** — `excluded_queues` matches the transport name on both sides (`ReceivedStamp::getTransportName()` on consume, `SentStamp::getSenderAlias()` on dispatch). A dispatched envelope landing on multiple transports emits one point per non-excluded transport.
- **DBAL** — records duration for `Connection::query()`/`exec()`, prepared `Statement::execute()`, and transaction control methods. SQL text is **never** recorded — only the leading keyword (`db.operation.name`) and the primary table when extractable (`db.collection.name`).

**HTTP Client** (outgoing requests):

| Instrument | Kind | Unit | Stability | Attributes |
|---|---|---|---|---|
| `http.client.request.duration` | Histogram | `s` | **Stable** | `http.request.method`, `server.address`, `server.port`, `url.scheme`, `http.response.status_code` on response, `error.type` on transport failure |
| `http.client.request.body.size` | Histogram | `By` | Development | Same as duration (emitted when `Content-Length` header or a string body is present) |
| `http.client.response.body.size` | Histogram | `By` | Development | Same as duration (emitted when response `Content-Length` is set or the body is fully read) |

`http_client.excluded_hosts` skips matching hostnames; the OTLP endpoint (from `OTEL_EXPORTER_OTLP_ENDPOINT`) is always auto-excluded to prevent instrumentation loops.

**Mailer** (outbound transport sends):

| Instrument | Kind | Unit | Stability | Attributes |
|---|---|---|---|---|
| `messaging.client.operation.duration` | Histogram | `s` | Development | `messaging.system=symfony_mailer`, `messaging.operation.name=send`, `messaging.operation.type=send`, `messaging.destination.name` from `X-Transport` header when present, `error.type` on failure |
| `messaging.client.sent.messages` | Counter | `{message}` | Development | Same as duration |

Decoration sits inside `TraceableTransports` so metric points record while the trace span is still active — backends that support exemplars can link directly from a metric data point to the corresponding trace.

### Manual Metrics

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

The registry caches instruments per name, so repeated `->counter('x')` calls return the same instance. When the OTel SDK is not configured, the NoOp meter provider returns no-op instruments — safe to inject unconditionally. The `@anonymous` guard above normalises anonymous-class names to their parent; otherwise `$e::class` embeds a filesystem path, leaking code locations and exploding label cardinality.

### Metrics Environment Variables

| Variable | Example | Description |
|---|---|---|
| `OTEL_METRICS_EXPORTER` | `otlp` | Metrics exporter (`otlp`, `console`, `none`) — only used when `metrics.enabled: true` |
| `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` | `http://localhost:4318/v1/metrics` | Override the generic `OTEL_EXPORTER_OTLP_ENDPOINT` for metrics |

## Performance

Near-zero overhead when the SDK is inactive — every component short-circuits via `isEnabled()`. When tracing is on, almost all cost is in span export, not instrumentation. PHP-FPM has no background thread, so `BatchSpanProcessor` flushes during request shutdown.

**Use `http/json` unless you have `ext-protobuf` installed.** PHP's native `json_encode()` is faster than the pure-PHP protobuf encoder, which adds significant CPU overhead under load. Switch to `http/protobuf` only with the C extension installed.

For high-traffic apps:

- Run a local OTel Collector at `localhost:4318` (sub-ms latency) and let it forward asynchronously.
- Enable head sampling: `OTEL_TRACES_SAMPLER=parentbased_traceidratio` + `OTEL_TRACES_SAMPLER_ARG=0.1`.
- Use `traces.excluded_paths` / `traces.cache.excluded_pools` to drop noisy spans.

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
