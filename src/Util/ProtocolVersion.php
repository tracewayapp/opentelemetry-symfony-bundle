<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

/**
 * Normalizes protocol versions to semconv network.protocol.version values.
 */
final class ProtocolVersion
{
    /** From $_SERVER-style "HTTP/1.1" strings. */
    public static function fromServerProtocol(?string $protocol): ?string
    {
        if (null === $protocol || '' === $protocol) {
            return null;
        }

        $version = str_replace('HTTP/', '', $protocol);

        return match ($version) {
            '' => null,
            '2.0' => '2',
            '3.0' => '3',
            default => $version,
        };
    }

    /** From HttpClient getInfo('http_version'): CURL_HTTP_VERSION_* ints or strings. */
    public static function fromTransportInfo(mixed $raw): ?string
    {
        if (\is_int($raw)) {
            // CURL_HTTP_VERSION_* values, as literals so ext-curl is not required.
            return match ($raw) {
                1 => '1.0',
                2 => '1.1',
                3 => '2',
                30 => '3',
                default => null,
            };
        }

        if (\is_string($raw) && '' !== $raw) {
            return match ($raw) {
                '2.0' => '2',
                '3.0' => '3',
                default => $raw,
            };
        }

        return null;
    }
}
