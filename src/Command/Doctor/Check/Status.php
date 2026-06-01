<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check;

enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Skipped = 'skipped';
    case Info = 'info';

    public function severity(): Severity
    {
        return match ($this) {
            self::Error => Severity::Error,
            self::Warning => Severity::Warning,
            self::Ok, self::Info, self::Skipped => Severity::Info,
        };
    }
}
