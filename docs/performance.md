# Performance

Near-zero overhead when the SDK is inactive — every component short-circuits via `isEnabled()`. When tracing is on, almost all cost is in span export, not instrumentation. PHP-FPM has no background thread, so `BatchSpanProcessor` flushes during request shutdown.

**Use `http/json` unless you have `ext-protobuf` installed.** PHP's native `json_encode()` is faster than the pure-PHP protobuf encoder, which adds significant CPU overhead under load. Switch to `http/protobuf` only with the C extension installed.

For high-traffic apps:

- Run a local OTel Collector at `localhost:4318` (sub-ms latency) and let it forward asynchronously.
- Enable head sampling: `OTEL_TRACES_SAMPLER=parentbased_traceidratio` + `OTEL_TRACES_SAMPLER_ARG=0.1`.
- Use `traces.excluded_paths` / `traces.cache.excluded_pools` to drop noisy spans.
