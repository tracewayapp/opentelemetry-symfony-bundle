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

## Manual Metrics

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
