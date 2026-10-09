# Doctor

`bin/console traceway:doctor` runs diagnostic checks against the bundle's wiring, the SDK environment variables, and OTLP endpoint reachability. Use it after install, after env-var changes, or in CI to fail builds on misconfigurations.

```
$ bin/console traceway:doctor
Traceway Doctor
═══════════════

Runtime
  ○ protocol is http/json; ext-protobuf not required
  ✓ ext-opentelemetry not loaded (no conflict risk)
  ✓ ext-opentelemetry not loaded, so no contrib auto-instrumentation can run

SDK configuration
  ✓ OTEL_SERVICE_NAME = "my-symfony-app"
  ✓ OTEL_TRACES_EXPORTER = otlp
  ✓ OTEL_EXPORTER_OTLP_ENDPOINT = http://localhost/api/otel
  ✓ OTEL_EXPORTER_OTLP_PROTOCOL = http/json
  ✓ OTEL_TRACES_SAMPLER unset (defaults to parentbased_always_on)
  ✓ TracerProvider is TracerProvider

Bundle configuration
  ○ propagator=w3c, id_generator=default; X-Ray not configured
  ✓ Messenger tracing enabled and symfony/messenger is installed
  ○ logs.export.enabled is false

Connectivity
  ✓ OTLP endpoint reachable (HTTP 404, 7ms)

Results: 10 ok, 0 warning, 0 error, 3 skipped, 0 info
```

The command is also discoverable in the `debug:` namespace as `debug:traceway`.

## Flags

| Flag | Default | Purpose |
|---|---|---|
| `--format=text\|json` | `text` | `json` emits a versioned envelope for CI consumption |
| `--skip-network` | off | Skip reachability checks (useful in CI without backend access) |
| `--only=name1,name2` | all | Restrict to specific check names |
| `--fail-on=info\|warning\|error` | `error` | Severity threshold for exit code 1 |
| `--timeout=N` | `1.0` | Network probe timeout in seconds |

## CI usage

The JSON output is stable (envelope `{version, summary, checks}`) and safe for scripting:

```bash
bin/console traceway:doctor --format=json --skip-network | jq '.summary.exit_code'
```

A non-zero exit code means at least one check has severity at or above `--fail-on`. Pin doctor to a `--fail-on=warning` build step if you want CI to flag every issue, or leave the default `--fail-on=error` to fail only on hard misconfigurations.

## Running alongside contrib auto-instrumentation

The `opentelemetry-php-contrib` packages hook PHP functions through `ext-opentelemetry`. Several of them cover what this bundle already instruments, and running both records every span or log twice. `contrib_instrumentation_overlap` warns about each installed package that overlaps a bundle feature you have enabled, and prints the `OTEL_PHP_DISABLED_INSTRUMENTATIONS` value that switches the contrib side off:

| Package | Disable name | Duplicates |
|---|---|---|
| `opentelemetry-auto-symfony` | `symfony` | HTTP server, HttpClient and Messenger spans |
| `opentelemetry-auto-guzzle` | `guzzle` | Guzzle CLIENT spans |
| `opentelemetry-auto-psr18` | `psr18` | PSR-18 CLIENT spans |
| `opentelemetry-auto-http-async` | `http-async-client` | a CLIENT span above Symfony HttpClient requests made through HTTPlug |
| `opentelemetry-auto-curl` | `curl` | a CLIENT span under Symfony HttpClient and Guzzle requests sent over curl |
| `opentelemetry-auto-doctrine` | `doctrine` | Doctrine DBAL query spans |
| `opentelemetry-auto-pdo`, `-mysqli`, `-postgresql` | `pdo`, `mysqli`, `postgresql` | a query span under every DBAL query on that driver |
| `opentelemetry-auto-psr6`, `-psr16` | `psr6`, `psr16` | cache spans |
| `opentelemetry-auto-psr3` | `psr3` | log trace-context injection, and every log record in export mode |

Contrib packages that instrument what the bundle does not, such as `opentelemetry-auto-mongodb` or `opentelemetry-auto-ext-amqp`, compose with it without duplicates; keep them enabled.

## Extending with custom checks

Doctor uses the `traceway.doctor.check` service tag. To register your own check, implement `CheckInterface` and tag the service:

```php
namespace App\Doctor;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class MyCheck implements CheckInterface
{
    public function name(): string { return 'my_check'; }
    public function label(): string { return 'My custom check'; }
    public function group(): CheckGroup { return CheckGroup::Bundle; }

    public function run(CheckContext $context): CheckResult
    {
        return CheckResult::ok($this->name(), 'all good');
    }
}
```

```yaml
# config/services.yaml — autoconfigure handles the tag if you don't disable it.
services:
    App\Doctor\MyCheck:
        autoconfigure: true
```

For network-touching checks, implement `NetworkCheckInterface` instead so `--skip-network` skips your check too.
