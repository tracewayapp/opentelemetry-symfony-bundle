<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check;

enum Severity: int
{
    case Info = 0;
    case Warning = 1;
    case Error = 2;

    public static function fromName(string $name): self
    {
        return match (strtolower($name)) {
            'info' => self::Info,
            'warning' => self::Warning,
            'error' => self::Error,
            default => throw new \InvalidArgumentException(\sprintf('Unknown severity "%s". Expected one of: info, warning, error.', $name)),
        };
    }

    public function isAtLeast(Severity $threshold): bool
    {
        return $this->value >= $threshold->value;
    }
}
