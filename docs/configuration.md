# Configuration

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

        propagator: w3c                       # w3c (default) | xray | w3c+xray — see [AWS X-Ray](aws-xray.md)
        id_generator: default                 # default | xray — see [AWS X-Ray](aws-xray.md)

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
        enabled: false                        # Implicitly true when any other sdk.* key is set.
        autoload_enabled: false               # Toggle OTEL_PHP_AUTOLOAD_ENABLED from bundle config (Dotenv/Secrets run too late).
        use_putenv: false                     # Also write via putenv() (not thread-safe).
        resource_attributes: []               # Merged into OTEL_RESOURCE_ATTRIBUTES; bundle wins. Example: {'service.version': '%env(APP_VERSION)%'}
        exporter_otlp_headers: []             # Merged into OTEL_EXPORTER_OTLP_HEADERS; bundle wins. Example: {'Authorization': 'Bearer %env(OTEL_API_BEARER_TOKEN)%'}
```

Upgrading from v1.x? See [UPGRADE-2.0.md](../UPGRADE-2.0.md) for the flat→nested mapping and migration notes.

## Environment Variables

| Variable | Example | Description |
|---|---|---|
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` | Enable SDK auto-initialization |
| `OTEL_SERVICE_NAME` | `my-symfony-app` | Service name shown in your backend |
| `OTEL_TRACES_EXPORTER` | `otlp` | Traces exporter (`otlp`, `zipkin`, `console`, `none`) |
| `OTEL_LOGS_EXPORTER` | `otlp` | Logs exporter (`otlp`, `console`, `none`) — only used when `logs.export.enabled: true` |
| `OTEL_METRICS_EXPORTER` | `otlp` | Metrics exporter (`otlp`, `console`, `none`) — only used when `metrics.enabled: true` |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Collector/backend endpoint |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/json` | Protocol (`http/json`, `http/protobuf`, `grpc`). `grpc` is **not** included out of the box — see [gRPC transport](#grpc-transport) below. |
| `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` | `http://localhost:4318/v1/metrics` | Override the generic endpoint for metrics |

See the [OpenTelemetry SDK docs](https://opentelemetry.io/docs/languages/php/exporters/) for all available options.

### gRPC transport

The bundle ships only the pure-PHP OTLP transports (`http/json`, `http/protobuf`). To use `OTEL_EXPORTER_OTLP_PROTOCOL=grpc` you must additionally install:

- `ext-grpc` (PECL extension)
- `open-telemetry/transport-grpc` (composer package)
- `ext-protobuf` is also recommended (already covered by the protobuf protocol guidance above).

```bash
pecl install grpc
composer require open-telemetry/transport-grpc
```

Run `bin/console traceway:doctor` after installing — it will warn if `grpc` is selected without the required pieces.
