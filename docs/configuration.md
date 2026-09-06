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
        record_exception_min_status: 0        # e.g. 500 = skip recordException() for HTTP exceptions below 500 (404 bot probes); 0 = record all

        console:
            enabled: true
            excluded_commands: [cache:clear, assets:install]   # always merged with the long-running defaults below
            trace_long_running_commands: false                 # true = also trace messenger:consume(-messages)

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
            only_with_parent: true            # false = emit DB spans even without an active parent span (pre-3.4.1 behavior, incl. messenger transport poll noise)
                                              # a long-running command's own span does not count as a parent, so worker poll queries stay suppressed

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

        flush:
            enabled: true                     # required in worker runtimes; see docs/metrics.md
            interval: ~                       # unset follows OTEL_METRIC_EXPORT_INTERVAL (SDK default: 60 s)

        messenger:
            enabled: false
            excluded_queues: []

        doctrine:
            enabled: false

        http_server:
            enabled: false
            excluded_paths: []                # same prefix-match rules as tracing excluded_paths; set separately from traces

        http_client:
            enabled: false
            excluded_hosts: []                # OTLP endpoint is auto-excluded; set separately from traces

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
            excluded_http_codes: []           # e.g. [404, 405] drops records whose exception is an HttpExceptionInterface with that code (bot-probe noise)
            excluded_channels: []             # e.g. [deprecation, php] drops whole Monolog channels before export
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
| `OTEL_LOGRECORD_ATTRIBUTE_VALUE_LENGTH_LIMIT` | `2048` | Truncate long log attribute values (stack traces, dumped context). Records in the field average ~4 KB; most of it is context. |
| `OTEL_PHP_DEBUG_SCOPES_DISABLED` | `1` | Skip OTel's `DebugScope` (a `debug_backtrace()` per span activation) when `zend.assertions=1`. The bundle sets it automatically when `kernel.debug` is false. |

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

## Keeping telemetry volume down

A few defaults exist because they are the difference between a few MB and a few GB per day. `bin/console traceway:doctor` flags the runtime ones.

**Worker commands.** `messenger:consume` and `messenger:consume-messages` are excluded from console tracing: their span would stay open for the life of the process, and every idle transport poll (`BEGIN` / `SELECT messenger_messages` / `COMMIT`) would be recorded under it. Per-message tracing comes from the Messenger middleware instead. Anything you put in `traces.console.excluded_commands` is *added* to that list, so you cannot lose it by accident; `trace_long_running_commands: true` opts back in deliberately.

`traces.doctrine.only_with_parent` (on by default) is the second line of defence: DB queries with no active parent span are dropped, and a long-running command's own span does not count as a parent — so poll queries stay out even when you trace the worker.

**Assertions.** With `zend.assertions=1`, OpenTelemetry's `Context::activate()` wraps every scope in a `DebugScope`, which captures a `debug_backtrace()`. `php.ini-production` ships `zend.assertions=0`; staging boxes copied from a dev config often do not. The bundle sets `OTEL_PHP_DEBUG_SCOPES_DISABLED` at boot when `kernel.debug` is false, and the doctor warns when assertions are on.

**Log volume.** The bundle filters log records by level (`logs.export.level`), by channel (`excluded_channels`), and by HTTP status of a context exception (`excluded_http_codes`). All three are static predicates — none of them can cap *repetition*, so one record logged in a loop still exports once per iteration. If that becomes a problem, the levers are `level`, `OTEL_LOGRECORD_ATTRIBUTE_VALUE_LENGTH_LIMIT` to cut the bytes per record, and a Collector in front of your backend (`filterprocessor`, or a log-dedup processor) for anything policy-shaped. OpenTelemetry defines no dedup or sampling for logs at the SDK level — sampling is a trace concept.

**Log channels.** `logs.export.excluded_channels` drops whole Monolog channels before export — `deprecation` and `php` are the usual candidates, since framework diagnostics otherwise land in your log storage. monolog-bundle's own `channels` key on the `opentelemetry` handler covers inclusive/exclusive rules if you need them.

**Metrics exclusions.** `metrics.http_server.excluded_paths` and `metrics.http_client.excluded_hosts` are independent of their tracing counterparts, so excluding `/health` from traces still measures it — usually what you want for an uptime signal. Repeat the list under `metrics` when you want a path or host dropped from both signals.
