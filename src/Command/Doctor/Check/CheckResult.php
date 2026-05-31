<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check;

/** @phpstan-type DetailValue scalar|null|array<int|string, scalar|null> */
final class CheckResult
{
    /**
     * @param array<string, scalar|null|array<int|string, scalar|null>> $details
     */
    private function __construct(
        public readonly string $name,
        public readonly Status $status,
        public readonly string $message,
        public readonly ?string $remediation = null,
        public readonly array $details = [],
    ) {}

    /**
     * @param array<string, scalar|null|array<int|string, scalar|null>> $details
     */
    public static function ok(string $name, string $message, array $details = []): self
    {
        return new self($name, Status::Ok, $message, null, $details);
    }

    /**
     * @param array<string, scalar|null|array<int|string, scalar|null>> $details
     */
    public static function warning(string $name, string $message, ?string $remediation = null, array $details = []): self
    {
        return new self($name, Status::Warning, $message, $remediation, $details);
    }

    /**
     * @param array<string, scalar|null|array<int|string, scalar|null>> $details
     */
    public static function error(string $name, string $message, ?string $remediation = null, array $details = []): self
    {
        return new self($name, Status::Error, $message, $remediation, $details);
    }

    /**
     * @param array<string, scalar|null|array<int|string, scalar|null>> $details
     */
    public static function info(string $name, string $message, array $details = []): self
    {
        return new self($name, Status::Info, $message, null, $details);
    }

    public static function skipped(string $name, string $reason): self
    {
        return new self($name, Status::Skipped, $reason);
    }
}
