<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;
use Traceway\OpenTelemetryBundle\Util\UrlSanitizer;

/**
 * Wraps a response to finalize the CLIENT span once the status code is read.
 *
 * Symfony HttpClient responses are lazy — the actual HTTP call happens when
 * you first access getStatusCode(), getHeaders(), or getContent(). This
 * wrapper ensures the span captures the real status code and ends at the
 * right time.
 */
final class TracedResponse implements ResponseInterface
{
    private bool $spanEnded = false;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly SpanInterface $span,
        private readonly bool $hasServerAddress = true,
    ) {}

    public function getStatusCode(): int
    {
        try {
            $statusCode = $this->response->getStatusCode();
        } catch (\Throwable $e) {
            $this->finalizeSpanWithError($e);
            throw $e;
        }

        $this->finalizeSpan($statusCode);

        return $statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        try {
            $headers = $this->response->getHeaders($throw);
        } catch (\Throwable $e) {
            $this->finalizeSpanWithError($e);
            throw $e;
        }

        if (!$this->spanEnded && isset($headers['content-length'][0])) {
            $this->span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, (int) $headers['content-length'][0]);
        }

        $this->safeFinalize();

        return $headers;
    }

    public function getContent(bool $throw = true): string
    {
        try {
            $content = $this->response->getContent($throw);
        } catch (\Throwable $e) {
            $this->finalizeSpanWithError($e);
            throw $e;
        }

        if ('' !== $content && !$this->spanEnded) {
            $this->span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, \strlen($content));
        }

        $this->safeFinalize();

        return $content;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(bool $throw = true): array
    {
        try {
            $array = $this->response->toArray($throw);
        } catch (\Throwable $e) {
            $this->finalizeSpanWithError($e);
            throw $e;
        }

        $this->safeFinalize();

        return $array;
    }

    public function cancel(): void
    {
        $this->endSpan();
        $this->response->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    public function getInnerResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function getSpan(): SpanInterface
    {
        return $this->span;
    }

    public function __destruct()
    {
        $this->endSpan();
    }

    private function safeFinalize(): void
    {
        try {
            $this->finalizeSpan($this->response->getStatusCode());
        } catch (\Throwable $e) {
            $this->finalizeSpanWithError($e);
        }
    }

    private function finalizeSpan(int $statusCode): void
    {
        if ($this->spanEnded) {
            return;
        }

        $this->enrichFromTransportInfo();
        $this->span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        if ($statusCode >= 400) {
            $this->span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->endSpan();
    }

    private function finalizeSpanWithError(\Throwable $e): void
    {
        if ($this->spanEnded) {
            return;
        }

        $this->enrichFromTransportInfo();
        $this->span->recordException($e);
        $this->span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($e));
        $this->span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        $this->endSpan();
    }

    private function endSpan(): void
    {
        if ($this->spanEnded) {
            return;
        }

        $this->spanEnded = true;
        $this->span->end();
    }

    /**
     * Backfills attributes only known after the transport ran: peer address,
     * protocol version, and — for relative base_uri requests — the required
     * server.address/port and absolute url.full from the effective URL.
     */
    private function enrichFromTransportInfo(): void
    {
        try {
            $info = $this->response->getInfo();
        } catch (\Throwable) {
            return;
        }

        if (!\is_array($info)) {
            return;
        }

        $primaryIp = $info['primary_ip'] ?? null;
        if (\is_string($primaryIp) && '' !== $primaryIp) {
            $this->span->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $primaryIp);

            $primaryPort = $info['primary_port'] ?? null;
            if (\is_int($primaryPort) && $primaryPort > 0) {
                $this->span->setAttribute(NetworkAttributes::NETWORK_PEER_PORT, $primaryPort);
            }
        }

        $version = self::normalizeProtocolVersion($info['http_version'] ?? null);
        if (null !== $version) {
            $this->span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $version);
        }

        if (!$this->hasServerAddress) {
            $effectiveUrl = $info['url'] ?? null;
            if (\is_string($effectiveUrl) && \is_array($parsed = parse_url($effectiveUrl)) && isset($parsed['host'])) {
                $this->span->setAttribute(ServerAttributes::SERVER_ADDRESS, $parsed['host']);
                $this->span->setAttribute(UrlAttributes::URL_FULL, UrlSanitizer::sanitizeUrl($effectiveUrl));

                $port = $parsed['port'] ?? match (strtolower($parsed['scheme'] ?? '')) {
                    'https' => 443,
                    'http' => 80,
                    default => null,
                };
                if (null !== $port) {
                    $this->span->setAttribute(ServerAttributes::SERVER_PORT, (int) $port);
                }
            }
        }
    }

    /**
     * Curl reports http_version as a CURL_HTTP_VERSION_* int; strings pass through.
     */
    private static function normalizeProtocolVersion(mixed $raw): ?string
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
