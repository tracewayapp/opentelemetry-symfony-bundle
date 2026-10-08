# Contributing

Thank you for considering contributing to the OpenTelemetry Symfony Bundle!

## Getting Started

```bash
git clone https://github.com/tracewayapp/opentelemetry-symfony-bundle.git
cd opentelemetry-symfony-bundle
composer install
```

## Running Tests

```bash
vendor/bin/phpunit
```

## Running Static Analysis

```bash
vendor/bin/phpstan analyse
```

## Coding Standards

- PHP 8.1+ compatible (no typed constants, no `readonly class`)
- `declare(strict_types=1)` in every file
- `final` classes by default
- Constructor property promotion where possible
- PHPStan 2.x level 10 clean — no baseline

## Tests

Every change in behavior comes with a test. New functionality needs tests that fail without it; a bug fix needs a test that reproduces the bug. Pull requests that change `src/` without touching `tests/` are asked for one before review.

Tests run on every push and pull request across the PHP, Symfony and DBAL matrix in `.github/workflows/ci.yml`, plus the end-to-end harness in `e2e/` against a real OpenTelemetry collector. All of it must pass before a merge.

## Submitting Changes

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Make your changes, with tests
4. Run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix`
5. Add a line to the Unreleased section of `CHANGELOG.md`
6. Commit your changes with a clear message
7. Push to your fork and open a Pull Request

Every pull request is reviewed by a maintainer before merge. Changes to span names, attribute names or metric names are checked against the OpenTelemetry semantic conventions release named in `docs/semantic-conventions.md`.

## Security

Do not report vulnerabilities in issues. See [SECURITY.md](SECURITY.md) for the private reporting channel and the response times we commit to.

## Releases

Releases are managed by maintainers. When a new version is ready, a git tag is pushed (e.g., `v1.5.0`) and a GitHub Release is created with the changelog entry.

## Reporting Bugs

Please open an issue at [github.com/tracewayapp/opentelemetry-symfony-bundle/issues](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues) with:

- PHP and Symfony versions
- Bundle version
- Steps to reproduce
- Expected vs actual behavior
