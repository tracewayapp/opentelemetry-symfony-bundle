<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

/**
 * Carried in a request's `extra` options. Retry decorators re-issue a request
 * with the same options, so every attempt sees the same counter instance.
 *
 * @internal
 */
final class ResendCounter
{
    public const OPTION = 'traceway_resend_counter';

    private int $attempts = 0;

    /** Returns how many times the request was sent before this attempt. */
    public function next(): int
    {
        return $this->attempts++;
    }
}
