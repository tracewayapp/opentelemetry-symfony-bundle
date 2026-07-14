<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class ErrorTypeResolver
{
    public static function resolve(\Throwable $exception): string
    {
        // Messenger wraps handler exceptions; the constant wrapper FQCN has no diagnostic value.
        while ($exception instanceof HandlerFailedException) {
            $wrapped = $exception->getWrappedExceptions();
            if (1 !== \count($wrapped)) {
                break;
            }
            $exception = reset($wrapped);
        }

        $type = $exception::class;

        if (str_contains($type, '@anonymous')) {
            $type = get_parent_class($exception) ?: \Throwable::class;
        }

        return $type;
    }
}
