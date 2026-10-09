# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 4.x | Yes |
| < 4.0 | No |

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability.

Report it privately through GitHub's advisory form:
https://github.com/tracewayapp/opentelemetry-symfony-bundle/security/advisories/new

Include the bundle version, PHP and Symfony versions, a description of the issue, and a reproduction if you have one.

## What to expect

- Acknowledgement within 3 business days, and in every case within 14 days.
- An assessment of severity and affected versions within 14 days of the report.
- A fix or mitigation for confirmed vulnerabilities of medium or higher severity within 60 days of the report, released as a patch version of the supported line.
- Credit in the release notes and the GitHub advisory unless you ask otherwise.

Reports are kept private until a fix is released. We will coordinate the disclosure date with you.

## Scope

In scope: this bundle's own code, its default configuration, and the way it handles data that ends up in telemetry (for example, credentials in `url.full`, SQL text, request headers).

Out of scope: vulnerabilities in dependencies (report those upstream, we will update promptly), and misconfiguration by the application such as enabling `record_statements` on a database that stores secrets in query literals.
