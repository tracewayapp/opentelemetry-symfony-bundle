<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\Context\ContextInterface;

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

    /** The context active when the request was made parents every attempt. */
    public function __construct(
        public readonly ?ContextInterface $parent = null,
    ) {
    }

    /** Returns how many times the request was sent before this attempt. */
    public function next(): int
    {
        return $this->attempts++;
    }
}
