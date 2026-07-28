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

Pure-PHP OpenTelemetry instrumentation for Symfony. Automatic tracing for HTTP, Console, HttpClient, Messenger, Mailer, Scheduler, Doctrine DBAL, Cache, and Twig — plus Monolog log-trace correlation, OTel log export, and opt-in metrics. **No C extension required.**

Works with any OpenTelemetry-compatible backend: [Traceway](https://tracewayapp.com), [Jaeger](https://www.jaegertracing.io/), [Zipkin](https://zipkin.io/), [Datadog](https://www.datadoghq.com/), [Grafana Tempo](https://grafana.com/oss/tempo/), [Honeycomb](https://www.honeycomb.io/), [AWS X-Ray](docs/aws-xray.md), and more.

- **Pure PHP** — installs on every managed Symfony host
- **Production-ready** — stable since v1.0, PHPStan level 10 with no baseline, Symfony 6.4 LTS through 8.x
- **Correct under load** — Messenger context propagates across async boundaries, DBAL 3 and 4 CI-tested, re-entrance guards on HttpClient and the log handler

## Installation

```bash
composer require traceway/opentelemetry-symfony
```

Symfony Flex registers the bundle automatically. Without Flex, add it to `config/bundles.php`:

```php
return [
    // ...
    Traceway\OpenTelemetryBundle\OpenTelemetryBundle::class => ['all' => true],
];
```

## Quick Start

```env
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=my-symfony-app
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

That's it. Every HTTP request, console command, outgoing call, Messenger job, DB query, cache operation, and Twig render is now traced.

Prefer configuring the SDK via the bundle instead of raw env vars (works with DotEnv and Symfony Secrets)? See the `open_telemetry.sdk` section in [docs/configuration.md](docs/configuration.md).

Verify the wiring:

```bash
bin/console traceway:doctor
```

## What Gets Traced

HTTP requests (with route templates), Console commands, HttpClient, Messenger, Scheduler, Mailer, Doctrine DBAL, Cache, and Twig — plus Monolog `trace_id`/`span_id` correlation and opt-in OTel log export. Each subsystem is individually toggleable. See **[docs/index.md](docs/index.md)** for the per-component span breakdown.

## Semantic Conventions

Audited against the current [OTel semantic conventions](https://opentelemetry.io/docs/specs/semconv/): every stable MUST/Required/Conditionally-Required rule is implemented, Recommended attributes are emitted wherever the data exists, and all deviations are deliberate and spec-permitted. See **[docs/semantic-conventions.md](docs/semantic-conventions.md)** for the conformance statement, deviations, and known limitations.

## Configuration

All options are optional — the bundle works out of the box. A minimal `config/packages/open_telemetry.yaml`:

```yaml
open_telemetry:
    traces:
        excluded_paths: [/health, /_profiler, /_wdt]
    metrics:
        enabled: true
    logs:
        correlation:
            enabled: true
```

See **[docs/configuration.md](docs/configuration.md)** for the full reference and environment variables.

## Manual Instrumentation

Inject `TracingInterface` for one-liner span creation:

```php
use Traceway\OpenTelemetryBundle\TracingInterface;

class OrderService
{
    public function __construct(private readonly TracingInterface $tracing) {}

    public function process(int $orderId): void
    {
        $this->tracing->trace('order.validate', fn () => $this->validate($orderId));
        $this->tracing->trace('order.fulfill', function () {
            $this->tracing->trace('inventory.reserve', fn () => $this->reserve());
            $this->tracing->trace('payment.charge', fn () => $this->charge());
        });
    }
}
```

Mock in tests with `$this->createStub(TracingInterface::class)` and have `trace()` invoke the callback directly.

## Metrics

Opt-in OpenTelemetry metrics — Messenger, Doctrine DBAL, HTTP server/client, and Mailer — alongside a `MeterRegistryInterface` for custom counters/histograms/gauges. See **[docs/metrics.md](docs/metrics.md)**.

## Doctor

`bin/console traceway:doctor` runs diagnostic checks against the bundle's wiring, SDK environment variables, and OTLP endpoint reachability — text or JSON, scriptable in CI. See **[docs/doctor.md](docs/doctor.md)** for flags, JSON envelope schema, and custom checks.

## Documentation

| | |
|---|---|
| [Configuration](docs/configuration.md) | Full config reference and environment variables |
| [Metrics](docs/metrics.md) | Instrument list, manual metrics, exemplars |
| [AWS X-Ray](docs/aws-xray.md) | Native `propagator` / `id_generator` keys |
| [Doctor](docs/doctor.md) | `traceway:doctor` flags, JSON output, custom checks |
| [Performance](docs/performance.md) | Overhead, sampling, exporter choice |
| [Upgrade from v1.x](UPGRADE-2.0.md) | Flat → nested config migration |
| [Changelog](CHANGELOG.md) | Release history |

## Contributing

```bash
git clone https://github.com/tracewayapp/opentelemetry-symfony-bundle.git
cd opentelemetry-symfony-bundle
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Join the conversation on [Discord](https://discord.gg/9tPn2SB3).

## License

[MIT](LICENSE)
