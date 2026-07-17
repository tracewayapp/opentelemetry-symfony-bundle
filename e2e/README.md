# End-to-end harness

Boots a real Symfony kernel with this bundle installed via a path repository, exports over a real OTLP `http/json` pipeline to a real `opentelemetry-collector`, and asserts on the spans the collector actually received — the failure class unit tests with mocks cannot catch.

```bash
bash e2e/run.sh
```

Requires Docker (Compose v2) and PHP >= 8.1 with Composer. Runs in CI via `.github/workflows/e2e.yml`.

What it asserts: SERVER span named `GET /hello/{name}` with `http.route`, status code, method, `network.peer.address`, and `service.name`; the manual `TracingInterface` span; the `cache.get` miss span; and that all spans share one trace.

To extend, add scenario steps in `scenario.php` and matching checks in `assert.php` — the collector's raw output lands in `output/traces.jsonl` for inspection.
