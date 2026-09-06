# Metrics

Off by default. Enable to export OpenTelemetry metrics alongside traces, with opt-in automatic instrumentation for Messenger, Doctrine DBAL, HTTP server/client, and Mailer.

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

## What Gets Measured

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

## HTTP Client (outgoing requests)

| Instrument | Kind | Unit | Stability | Attributes |
|---|---|---|---|---|
| `http.client.request.duration` | Histogram | `s` | **Stable** | `http.request.method`, `server.address`, `server.port`, `url.scheme`, `http.response.status_code` on response, `error.type` on transport failure |
| `http.client.request.body.size` | Histogram | `By` | Development | Same as duration (emitted when `Content-Length` header or a string body is present) |
| `http.client.response.body.size` | Histogram | `By` | Development | Same as duration (emitted when response `Content-Length` is set or the body is fully read) |

`http_client.excluded_hosts` skips matching hostnames; the OTLP endpoint (from `OTEL_EXPORTER_OTLP_ENDPOINT`) is always auto-excluded to prevent instrumentation loops.

## Mailer (outbound transport sends)

| Instrument | Kind | Unit | Stability | Attributes |
|---|---|---|---|---|
| `messaging.client.operation.duration` | Histogram | `s` | Development | `messaging.system=symfony_mailer`, `messaging.operation.name=send`, `messaging.operation.type=send`, `messaging.destination.name` from `X-Transport` header when present, `error.type` on failure |
| `messaging.client.sent.messages` | Counter | `{message}` | Development | Same as duration |

Decoration sits inside `TraceableTransports` so metric points record while the trace span is still active — backends that support exemplars can link directly from a metric data point to the corresponding trace.

## When Measurements Are Exported

Metrics differ from the other two signals in how they leave the process, and the difference decides whether they arrive at all.

Spans and log records are pushed by their batch processors, which reconsider their schedule inside `BatchSpanProcessor::onEnd()` and `BatchLogRecordProcessor::onEmit()` — every finished span, every emitted record, drives the check. Traffic keeps those exporters running by itself.

A metric data point is not an event that ends; it is an update to a running aggregation, so there is no equivalent moment to hook into. The SDK collects metrics only when the `MeterProvider` is flushed or shut down, and the single trigger it registers is a shutdown hook. PHP has no background thread, so `OTEL_METRIC_EXPORT_INTERVAL` is a documented default that nothing acts upon.

Under PHP-FPM the shutdown hook is enough: the process ends on every request. Under FrankenPHP, RoadRunner or Swoole the worker serves thousands of requests before it is recycled, and until then the measurements stay in memory — the application looks like it emits no metrics at all, while its traces and logs arrive normally.

`metrics.flush` closes that gap by exporting from the application's own lifecycle:

```yaml
open_telemetry:
    metrics:
        enabled: true
        flush:
            enabled: true      # default
            interval: ~        # default: follow OTEL_METRIC_EXPORT_INTERVAL
```

Left unset, the interval is whatever `OTEL_METRIC_EXPORT_INTERVAL` says, falling back to the SDK's own default for it — 60 s. The bundle deliberately does not restate that number: an application already configured through the standard OTel variables stays consistent without repeating itself in YAML, and there is one place to change it.

Three moments are covered: `kernel.terminate` for HTTP, `console.terminate` for commands, and `WorkerRunningEvent` for Messenger consumers — the last matters because a consumer runs for hours and `console.terminate` fires only when it stops.

### Nothing changes under PHP-FPM

The first flush in a process is deliberately skipped. A process that ends there — every request under PHP-FPM, every one-shot command — is served by the shutdown hook on its way out, and exporting first would only send the same cumulative state twice. Only a process that reaches a second flush is one the hook will not serve in time, which is what a worker is.

That rule is what makes the option safe to leave on everywhere: it costs a request-per-process runtime exactly nothing, and needs no detection of which runtime is in use.

"First in a process" means the process, not the service instance. Runtimes that rebuild the container between requests — `Kernel::reboot()`, some RoadRunner and Swoole setups — would otherwise hand each request a fresh flusher, make every call look like the first, and export nothing until the worker was recycled.

The rule assumes the shutdown hook exists, which it does whenever the SDK is autoloaded (`OTEL_PHP_AUTOLOAD_ENABLED=true`) — and when it is not, `Globals::meterProvider()` is a noop and there is nothing to export in the first place. The one setup that falls between the two is a hand-built SDK `MeterProvider` registered without auto-shutdown: there, a request-per-process runtime has nothing to serve its measurements on the way out. Register the provider through `Sdk::builder()` with auto-shutdown, as the autoloader does.

### Why the interval matters

`interval` is not a tuning knob to be minimised. Every export carries the whole cumulative state — each stream with each histogram bucket — so its size follows the number of attribute combinations the worker has accumulated, not the number of requests since the last export. Exporting per request therefore multiplies volume without carrying more information, and a collector will start refusing data long before the application notices.

Nothing is lost by skipping an export: cumulative instruments carry their totals into the next one. Set `interval: 0` only when you want an export on every hook — and note that an idle Messenger worker still posts an empty batch each time the loop comes round, since the reader short-circuits only when no stream exists at all.

### Choosing a temporality

`OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` is an SDK setting rather than a bundle one, but it changes what a flush costs, so it belongs in the same conversation.

Cumulative — the SDK default — reports a running total per stream, and every export repeats every stream whether or not it saw traffic. Delta reports only what happened since the previous collection, and a stream with no activity contributes no data points at all. Collected after each of eight requests that each touch a new route, then three times while idle:

| Export | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | idle | idle | idle |
|---|---|---|---|---|---|---|---|---|---|---|---|
| cumulative | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 8 | 8 | 8 |
| delta | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 0 | 0 | 0 |

Under cumulative the export grows with everything the worker has ever seen; under delta it follows the traffic. That is why `flush.interval` matters so much more with the default: it is the only thing standing between a busy worker and a collector that starts refusing data.

Delta also fits how PHP produces metrics. Cumulative assumes one long-lived owner per series, while PHP gives a process per request under FPM and several independent workers side by side otherwise. Five FPM requests under cumulative arrive as five totals of `1`, each with its own start timestamp — a series that never grows, and whose resets a backend cannot see because the value never falls. The same five under delta simply sum to five.

There is a third value, `lowmemory`, which the specification defines as delta for synchronous instruments and cumulative for asynchronous ones. The PHP exporter implements it by expressing no preference and letting each stream answer for itself, which comes to the same thing: counters and histograms — everything this bundle records — export delta, while observable instruments stay cumulative. It is a reasonable middle setting when an application also registers its own observable gauges.

The catch is on the receiving side: Prometheus and its ecosystem are natively cumulative. Either the backend accepts delta directly, or something converts it on the way — the OpenTelemetry Collector and Grafana Alloy both ship a `deltatocumulative` processor for this, which also puts the accumulation in a component that outlives the producers, where it belongs.

`traceway:doctor` reports the combination in use and warns about the one that silently produces meaningless data: cumulative temporality with no per-process identity.

### One instance identity per worker

Worker runtimes need one more thing from the resource, and it is not the bundle's to set. Each worker holds its own `MeterProvider` with its own cumulative counters; without a unique `service.instance.id` they all land on the same series, and a backend that reads OTLP as Prometheus sees one counter that repeatedly falls backwards.

The SDK omits that attribute by default, deliberately — a random UUID is useless in shared-nothing setups. In a worker it is exactly what is wanted, so opt the detector in:

```dotenv
OTEL_PHP_DETECTORS=host,process,service_instance
```

`env`, `sdk` and `service` are always applied and need not be listed.

Where the platform already knows the identity, state it instead of deriving one — a pod or task name survives a restart's worth of correlation in a way a fresh UUID does not:

```dotenv
OTEL_RESOURCE_ATTRIBUTES=service.instance.id=${POD_NAME}
```

`traceway:doctor` accepts either source.

## Manual Metrics

Inject `MeterRegistryInterface` to record your own counters, histograms, up/down counters, and gauges without touching the `MeterProvider` directly:

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
