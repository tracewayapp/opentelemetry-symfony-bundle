# Performance

Near-zero overhead when the SDK is inactive — every component short-circuits via `isEnabled()`. When tracing is on, almost all cost is in span export, not instrumentation. PHP-FPM has no background thread, so `BatchSpanProcessor` flushes during request shutdown.

**Use `http/json` unless you have `ext-protobuf` installed.** PHP's native `json_encode()` is faster than the pure-PHP protobuf encoder, which adds significant CPU overhead under load. Switch to `http/protobuf` only with the C extension installed.

For high-traffic apps:

- Run a local OTel Collector at `localhost:4318` (sub-ms latency) and let it forward asynchronously.
- Enable head sampling: `OTEL_TRACES_SAMPLER=parentbased_traceidratio` + `OTEL_TRACES_SAMPLER_ARG=0.1`.
- Use `traces.excluded_paths` / `traces.cache.excluded_pools` to drop noisy spans.

## Keeping span volume in check

Doctrine spans are usually the bulk of what a Symfony app exports, and two options decide how much they weigh:

- `traces.doctrine.record_statements` puts the full SQL on every DB span (`db.query.text`). It is off by default; a span with it is typically 3 to 5 times the size of one without.
- `traces.doctrine.max_spans_per_trace` caps the DB spans one trace may carry. A handler that runs thousands of single-row statements in a loop otherwise produces one span each; past the cap the statements still run, untraced, and the parent span reports how many were skipped in `traceway.db.spans_dropped`.

A whole message type can be taken out of tracing with `traces.messenger.excluded_messages`. Combined with `doctrine.only_with_parent` (the default) its handler's queries have no parent and are not traced either.

Metrics under PHP-FPM deserve the same care: every request is a process, so every request exports a complete set of histograms, and the SDK's `process` resource detector stamps `process.pid` on each export. A backend then sees one series per worker that can never aggregate. Either leave `metrics.doctrine` off under FPM, or set `OTEL_PHP_DETECTORS` to a list without `process` (for example `env,host,os,sdk,sdk_provided`) so all workers of a host share one identity.
