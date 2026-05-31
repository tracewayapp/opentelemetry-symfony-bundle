<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check;

enum CheckGroup: string
{
    case Runtime = 'runtime';
    case Sdk = 'sdk';
    case Bundle = 'bundle';
    case Connectivity = 'connectivity';

    public function label(): string
    {
        return match ($this) {
            self::Runtime => 'Runtime',
            self::Sdk => 'SDK configuration',
            self::Bundle => 'Bundle configuration',
            self::Connectivity => 'Connectivity',
        };
    }
}
