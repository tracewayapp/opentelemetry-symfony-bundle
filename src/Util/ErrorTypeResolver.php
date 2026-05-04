<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

final class ErrorTypeResolver
{
    public static function resolve(\Throwable $exception): string
    {
        $type = $exception::class;

        if (str_contains($type, '@anonymous')) {
            $type = get_parent_class($exception) ?: \Throwable::class;
        }

        return $type;
    }
}
