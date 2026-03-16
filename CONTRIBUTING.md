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
- PHPStan level 9 clean — no baseline

## Submitting Changes

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Make your changes
4. Run tests and PHPStan to ensure nothing is broken
5. Commit your changes with a clear message
6. Push to your fork and open a Pull Request

## Reporting Bugs

Please open an issue at [github.com/tracewayapp/opentelemetry-symfony-bundle/issues](https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues) with:

- PHP and Symfony versions
- Bundle version
- Steps to reproduce
- Expected vs actual behavior
