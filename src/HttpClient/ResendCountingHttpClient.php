<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sits outside Symfony's RetryableHttpClient and gives each request a
 * {@see ResendCounter}, so {@see TraceableHttpClient}, which sits inside it,
 * can set http.request.resend_count on every retried attempt.
 */
final class ResendCountingHttpClient implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait;

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $extra = isset($options['extra']) && \is_array($options['extra']) ? $options['extra'] : [];
        if (!($extra[ResendCounter::OPTION] ?? null) instanceof ResendCounter) {
            $extra[ResendCounter::OPTION] = new ResendCounter();
            $options['extra'] = $extra;
        }

        return $this->client->request($method, $url, $options);
    }
}
