# AWS X-Ray

Native X-Ray support ships as two optional config keys that together replace the default W3C propagation and ID generation with their AWS equivalents. Both require [`open-telemetry/contrib-aws`](https://packagist.org/packages/open-telemetry/contrib-aws).

```bash
composer require open-telemetry/contrib-aws
```

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    traces:
        propagator: xray       # inject/extract X-Amzn-Trace-Id headers
        id_generator: xray     # epoch-prefixed trace IDs recognised by X-Ray's timeline UI
```

```env
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4317   # your AWS Distro for OpenTelemetry (ADOT) Collector
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
```

## `propagator` controls which headers carry trace context

| Value | Header written/read | Use when |
|---|---|---|
| `w3c` *(default)* | `traceparent` / `tracestate` | All non-X-Ray backends |
| `xray` | `X-Amzn-Trace-Id` | Full X-Ray setup |
| `w3c+xray` | Both | Migrating from W3C → X-Ray; services that accept either |

All context propagation in the bundle — outgoing HttpClient requests, Messenger stamps, incoming request extraction — uses `Globals::propagator()`, so the swap is automatic and complete across every instrumented component.

## `id_generator: xray`

Builds a `TracerProvider` using the standard SDK env-var auto-configuration (`OTEL_TRACES_EXPORTER`, `OTEL_TRACES_SAMPLER`, `OTEL_PHP_TRACES_PROCESSOR`) and substitutes in the X-Ray ID generator, which produces 32-char hex trace IDs whose first 8 characters encode the Unix timestamp of the root span. X-Ray's UI uses this to render request times in its trace list without needing to query the full trace.

> `id_generator: xray` makes the bundle own the `TracerProvider`. If you also bootstrap your own `TracerProvider` in code, set it up first (before the Symfony kernel boots) so the bundle's initializer, which registers later, takes precedence. For most setups using only `OTEL_*` env vars this is not a concern.
