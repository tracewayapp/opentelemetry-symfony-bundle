# Upgrade from v1.x to v2.0

v2.0 restructures the bundle's configuration to a **nested, signal-grouped shape** (`traces:` / `metrics:` / `logs:`) that aligns with the OpenTelemetry specification, the `OTEL_*` environment variable convention, and the existing `metrics:` node we've shipped since v1.7.0.

**Your v1.x config keeps working** in v2.0. Every flat key emits a Symfony deprecation pointing at its new nested location. Flat keys are scheduled for removal in v3.0.

## TL;DR

- Existing config: keeps working, emits one deprecation per flat key.
- New config: nest your keys under `traces:` / `logs:` (see mapping below).
- Default change: `logs.export.unprefixed_attributes` now defaults to `true`. If you depend on the v1.x `monolog.context.*` / `monolog.extra.*` prefixed shape, set it to `false` explicitly.

## Why nested

- **OTel-aligned.** OTel organizes everything by signal (traces, metrics, logs). Our env-var contract already follows this (`OTEL_TRACES_*`, `OTEL_METRICS_*`, `OTEL_LOGS_*`). The config now matches.
- **Consistency.** Our `metrics:` node has been nested since v1.7.0. Mixing nested metrics with flat tracing keys was inconsistent. v2.0 fixes it.
- **Future-proofing.** OTel has profiles and events on the roadmap as new signals. Signal grouping has an obvious slot for them.

## Flat → Nested mapping

| Legacy key (v1.x) | New nested location (v2.0) |
|---|---|
| `traces_enabled` | `traces.enabled` |
| `tracer_name` | `traces.tracer_name` |
| `excluded_paths` | `traces.excluded_paths` |
| `record_client_ip` | `traces.record_client_ip` |
| `error_status_threshold` | `traces.error_status_threshold` |
| `console_enabled` | `traces.console.enabled` |
| `console_excluded_commands` | `traces.console.excluded_commands` |
| `http_client_enabled` | `traces.http_client.enabled` |
| `http_client_excluded_hosts` | `traces.http_client.excluded_hosts` |
| `messenger_enabled` | `traces.messenger.enabled` |
| `messenger_root_spans` | `traces.messenger.root_spans` |
| `doctrine_enabled` | `traces.doctrine.enabled` |
| `doctrine_record_statements` | `traces.doctrine.record_statements` |
| `cache_enabled` | `traces.cache.enabled` |
| `cache_excluded_pools` | `traces.cache.excluded_pools` |
| `twig_enabled` | `traces.twig.enabled` |
| `twig_excluded_templates` | `traces.twig.excluded_templates` |
| `scheduler_enabled` | `traces.scheduler.enabled` |
| `mailer_enabled` | `traces.mailer.enabled` |
| `mailer_record_subject` | `traces.mailer.record_subject` |
| `monolog_enabled` | `logs.correlation.enabled` |
| `log_export_enabled` | `logs.export.enabled` |
| `log_export_level` | `logs.export.level` |
| `log_export_capture_code_attributes` | `logs.export.capture_code_attributes` |
| `log_export_unprefixed_attributes` | `logs.export.unprefixed_attributes` (**default flipped to `true`**) |

`metrics:` is unchanged — it was already nested.

## Before / after

### v1.x

```yaml
open_telemetry:
    traces_enabled: true
    tracer_name: 'opentelemetry-symfony'
    excluded_paths: [/health, /_profiler, /_wdt]
    record_client_ip: true
    error_status_threshold: 500
    console_enabled: true
    console_excluded_commands: [cache:clear]
    http_client_enabled: true
    http_client_excluded_hosts: []
    messenger_enabled: true
    messenger_root_spans: false
    doctrine_enabled: true
    doctrine_record_statements: true
    cache_enabled: true
    cache_excluded_pools: [cache.system]
    twig_enabled: true
    twig_excluded_templates: ['@WebProfiler/']
    scheduler_enabled: true
    mailer_enabled: true
    mailer_record_subject: false
    monolog_enabled: true
    log_export_enabled: false
    log_export_level: debug
    log_export_capture_code_attributes: false
    log_export_unprefixed_attributes: false
    metrics:
        enabled: false
        # ... unchanged
```

### v2.0

```yaml
open_telemetry:
    traces:
        enabled: true
        tracer_name: 'opentelemetry-symfony'
        excluded_paths: [/health, /_profiler, /_wdt]
        record_client_ip: true
        error_status_threshold: 500
        console:
            enabled: true
            excluded_commands: [cache:clear]
        http_client:
            enabled: true
            excluded_hosts: []
        messenger:
            enabled: true
            root_spans: false
        doctrine:
            enabled: true
            record_statements: true
        cache:
            enabled: true
            excluded_pools: [cache.system]
        twig:
            enabled: true
            excluded_templates: ['@WebProfiler/']
        scheduler:
            enabled: true
        mailer:
            enabled: true
            record_subject: false

    metrics:
        # unchanged from v1.x
        enabled: false

    logs:
        correlation:
            enabled: true                  # was monolog_enabled
        export:
            enabled: false                 # was log_export_enabled
            level: debug
            capture_code_attributes: false
            unprefixed_attributes: true    # DEFAULT FLIPPED — see below
```

## Default change: `logs.export.unprefixed_attributes`

The previous v1.x default was `false`, which emitted Monolog context and extra fields under `monolog.context.*` / `monolog.extra.*` namespaces.

v2.0 flips the default to `true`, emitting these as flat OTel attributes. This matches every other ecosystem (Java logback, Python `LoggingHandler`, .NET, JS Winston) and is what we recommended in the v1.8.0 release notes when the toggle was introduced.

**If your dashboards depend on the `monolog.context.*` / `monolog.extra.*` prefixed shape**, set it explicitly:

```yaml
open_telemetry:
    logs:
        export:
            unprefixed_attributes: false
```

This is the only default change in v2.0. Everything else preserves v1.x behavior.

## Conflict detection

If you set both a legacy flat key and its nested equivalent in the **same configuration block**, the bundle throws `InvalidConfigurationException` at container compile time:

```yaml
open_telemetry:
    doctrine_enabled: false           # legacy
    traces:
        doctrine: { enabled: true }   # nested — conflicts
```

```
InvalidConfigurationException:
Cannot set both legacy "open_telemetry.doctrine_enabled" and nested
"open_telemetry.traces.doctrine.enabled" in the same configuration
block. Use the nested form only.
```

**Across separate files** (e.g. `config/packages/open_telemetry.yaml` vs `config/packages/dev/open_telemetry.yaml`), Symfony processes each block independently and then merges. The bundle cannot detect the conflict in this case; standard Symfony merge semantics will pick one deterministically (typically the later file wins). **Don't split flat and nested for the same setting across files.**

## Dependency floors raised

The v1.x `composer.json` declared `^1.0` floors for the OpenTelemetry PHP packages, which let `composer update --prefer-lowest` resolve to combinations (e.g. SDK 1.0 + sem-conv 1.0) that don't match any real-world install and break under modern SDK code paths. v2.0 aligns floors with the tested baseline:

| Package | v1.x floor | v2.0 floor |
|---|---|---|
| `open-telemetry/api` | `^1.0` | `^1.9` |
| `open-telemetry/context` | `^1.0` | `^1.5` |
| `open-telemetry/sdk` | `^1.0` | `^1.14` |
| `open-telemetry/sem-conv` | `^1.0` | `^1.38` |
| `symfony/phpunit-bridge` (dev) | `^6.4 \|\| ^7.0 \|\| ^8.0` | `^7.2 \|\| ^8.0` |

In practice, anyone running `composer require traceway/opentelemetry-symfony` against a project on modern Symfony (6.4 LTS / 7.x / 8.x) since late 2024 is already on versions well above these floors — `composer update` did the right thing automatically. If you pin OpenTelemetry PHP packages explicitly in your own `composer.json` to a version below these new floors, run `composer require open-telemetry/sdk:^1.14 open-telemetry/sem-conv:^1.38` to update.

The `symfony/phpunit-bridge` bump only affects bundle developers running the test suite — its floor moved because `ExpectUserDeprecationMessageTrait` (the cross-version polyfill we use for legacy-key deprecation tests) was added in bridge 7.2. The bundle's runtime is unaffected.

## Migration recipe

1. Upgrade to v2.0 — your existing config keeps working.
2. Run `bin/console cache:clear` and watch the Symfony deprecation log for the per-key messages.
3. Move keys one signal group at a time: `traces:` first, then `logs:`. The `metrics:` node is unchanged.
4. Decide on `logs.export.unprefixed_attributes` — accept the new `true` default (recommended; matches OTel ecosystem) or pin it to `false` explicitly to keep v1.x dashboards.
5. Verify with `bin/console debug:config open_telemetry` — the processed config should show the nested shape.

## Timeline

- **v2.0**: legacy flat keys still accepted, emit deprecations.
- **v3.0**: legacy flat keys still accepted (removal deferred — the v2.0 deprecation window was too short to drop them safely).
- **v4.0**: legacy flat keys removed entirely. Migrate before then.
