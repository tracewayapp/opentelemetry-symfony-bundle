# traceway/opentelemetry-symfony — Documentation

Pure-PHP OpenTelemetry instrumentation for Symfony. Automatic tracing across HTTP requests, Console commands, HttpClient, Messenger, Doctrine DBAL, Cache, Twig, Scheduler, and Mailer; Monolog log-trace correlation and native OTel log export; a complete metrics rollout (Doctrine, HTTP server, HTTP client, Messenger consume + dispatch, Mailer); and a lightweight `Tracing` helper for manual instrumentation.

No C extension required (`ext-protobuf` recommended for production exports). Supports PHP 8.1+ on Symfony 6.4 LTS / 7.x / 8.x.

## Where to look

- **[README](../README.md)** — quick start, configuration reference, instrumentation matrix, manual instrumentation helper, environment variables, performance notes.
- **[CHANGELOG](../CHANGELOG.md)** — release history. v2.0.0 restructures the config to a nested signal-grouped shape (`traces:` / `metrics:` / `logs:`).
- **[UPGRADE-2.0](../UPGRADE-2.0.md)** — flat → nested key mapping, before/after YAML, the `logs.export.unprefixed_attributes` default flip, and multi-file conflict caveat for users upgrading from v1.x.

## Reporting issues

[github.com/tracewayapp/opentelemetry-symfony-bundle/issues](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues)

## License

MIT. See [LICENSE](../LICENSE).
