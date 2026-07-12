<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Mailer;

use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/** Resolves the transport name from a message's X-Transport header. */
final class TransportNameResolver
{
    public static function fromMessage(RawMessage $message): ?string
    {
        if (!$message instanceof Message) {
            return null;
        }

        $header = $message->getHeaders()->get('X-Transport');
        if (null === $header) {
            return null;
        }

        $value = $header->getBody();

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
