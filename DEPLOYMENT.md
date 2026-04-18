# OpenTelemetry Symfony Bundle - Deployment Guide

## Prerequisites

- PHP >= 8.1 with `ext-opentelemetry` and `ext-protobuf`
- Symfony >= 6.4
- PHP-FPM (nginx)

## 1. Install PHP Extensions

```bash
# Install the OpenTelemetry extension
pecl install opentelemetry
echo "extension=opentelemetry.so" | sudo tee /etc/php/<VERSION>/mods-available/opentelemetry.ini
sudo phpenmod opentelemetry

# Install the Protobuf extension (required for http/protobuf protocol)
pecl install protobuf
echo "extension=protobuf.so" | sudo tee /etc/php/<VERSION>/mods-available/protobuf.ini
sudo phpenmod protobuf

# Verify
php -m | grep opentelemetry
php -m | grep protobuf
```

## 2. Install the Bundle

```bash
composer require traceway/opentelemetry-symfony
```

## 3. Configure PHP-FPM Environment Variables

Edit your FPM pool config (e.g. `/etc/php/<VERSION>/fpm/pool.d/www.conf`).

Only `OTEL_PHP_AUTOLOAD_ENABLED` belongs here — it must be set in FPM because Symfony's `.env` loads too late for the OTel extension to read it. The remaining OTel variables should live in your application's `.env` (see step 4), so each server / environment can carry its own values without rewriting the FPM pool config.

Add at the bottom:

```ini
; OpenTelemetry
clear_env = no
env[OTEL_PHP_AUTOLOAD_ENABLED] = "true"
```

**Important notes:**

- `OTEL_PHP_AUTOLOAD_ENABLED` MUST be quoted as `"true"`. Without quotes, FPM converts `true` to `1`, which the OTel SDK rejects.
- If `clear_env` is already set elsewhere in the file, don't add it again (FPM will fail to start with duplicate directives). Just uncomment or change the existing one.

## 4. Add Variables to Symfony .env

Set the remaining OTel variables in your Symfony `.env` (or `.env.local` / per-environment file). Keeping them at the application level means each deployed instance can use its own service name, endpoint, or token without touching the FPM pool config.

```env
###> opentelemetry ###
OTEL_SERVICE_NAME=your-service-name
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=https://cloud.tracewayapp.com/api/otel
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer <YOUR_TOKEN>"
###< opentelemetry ###
```

**Important notes:**

- `OTEL_EXPORTER_OTLP_PROTOCOL` MUST be `http/protobuf`. The `http/json` protocol is not supported by Traceway.
- Do **not** put `OTEL_PHP_AUTOLOAD_ENABLED` here — it is read by the OTel extension before Symfony's `.env` loader runs.

## 5. Configure the Bundle

Create `config/packages/open_telemetry.yaml`:

```yaml
open_telemetry:
  traces_enabled: true
  tracer_name: 'opentelemetry-symfony'

  excluded_paths:
    - /health
    - /_profiler
    - /_wdt

  record_client_ip: true
  error_status_threshold: 500

  console_enabled: false
  console_excluded_commands:
    - cache:clear
    - assets:install

  http_client_enabled: false
  messenger_enabled: false
  messenger_root_spans: false
  doctrine_enabled: false
  doctrine_record_statements: false
  cache_enabled: false
  twig_enabled: false
  monolog_enabled: true
```

## 6. Restart Services

```bash
sudo systemctl restart php<VERSION>-fpm
```

## 7. Verify

```bash
# Test from CLI
cd /var/www/your-project && php -r "
require 'vendor/autoload.php';
\$tracer = OpenTelemetry\API\Globals::tracerProvider()->getTracer('test');
\$span = \$tracer->spanBuilder('deployment-test')->startSpan();
\$span->end();
OpenTelemetry\API\Globals::tracerProvider()->forceFlush();
OpenTelemetry\API\Globals::tracerProvider()->shutdown();
echo 'Done' . PHP_EOL;
"

# Make a web request and check Traceway dashboard
curl -sk https://your-domain.com/ -o /dev/null -w "%{http_code}\n"
```

## Troubleshooting

### Traces not appearing

1. Check FPM has the env vars:
   ```bash
   # Add temporarily to public/index.php after <?php:
   file_put_contents('/tmp/otel_debug.txt', print_r(array_filter(getenv(), fn($k) => str_starts_with($k, 'OTEL'), ARRAY_FILTER_USE_KEY), true));
   ```
   Make a request, then `cat /tmp/otel_debug.txt`. Remove the debug line after.

2. Verify `OTEL_PHP_AUTOLOAD_ENABLED` is `true` (not `1`):
   ```bash
   OTEL_PHP_AUTOLOAD_ENABLED=1 php -r "
   require 'vendor/autoload.php';
   echo get_class(OpenTelemetry\API\Globals::tracerProvider()) . PHP_EOL;
   "
   # If it prints NoopTracerProvider, the value is wrong
   ```

3. Test connectivity to Traceway:
   ```bash
   curl -v https://cloud.tracewayapp.com/api/otel/v1/traces \
     -H "Authorization: Bearer <YOUR_TOKEN>" \
     -H "Content-Type: application/x-protobuf" \
     -d ''
   # Should return HTTP 200
   ```

### FPM fails to start after config changes

- Check for duplicate `clear_env` directives: `grep clear_env /etc/php/*/fpm/pool.d/www.conf`
- Check for syntax errors in env values (spaces in values need quotes)
- Check logs: `tail -20 /var/log/php<VERSION>-fpm.log`
