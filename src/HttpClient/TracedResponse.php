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
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;
use Traceway\OpenTelemetryBundle\Util\ProtocolVersion;
use Traceway\OpenTelemetryBundle\Util\UrlParts;
use Traceway\OpenTelemetryBundle\Util\UrlSanitizer;

/**
 * Wraps a response to finalize the CLIENT span once the status code is read.
 *
 * Symfony HttpClient responses are lazy — the actual HTTP call happens when
 * you first access getStatusCode(), getHeaders(), or getContent(). This
 * wrapper ensures the span captures the real status code and ends at the
 * right time.
 */
final class TracedResponse implements ResponseInterface, StreamableInterface
{
    private bool $spanEnded = false;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly SpanInterface $span,
        private readonly bool $hasServerAddress = true,
    ) {
    }

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

        if (!$this->spanEnded && isset($headers['content-length'][0]) && is_numeric($headers['content-length'][0])) {
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
            $this->span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $this->contentLengthFromHeaders() ?? \strlen($content));
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
        if (!$this->spanEnded) {
            // If headers already arrived, a status was received and semconv requires it.
            $received = $this->receivedStatusCode();
            if (null !== $received) {
                $this->span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $received);
            }

            $this->span->setAttribute(ErrorAttributes::ERROR_TYPE, 'cancelled');
            $this->endSpan();
        }

        $this->response->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    /**
     * @return resource
     */
    public function toStream(bool $throw = true)
    {
        try {
            if ($throw) {
                // Via the wrapper so the content-length attribute is captured before the span ends.
                $this->getHeaders(true);
            }

            if (!$this->response instanceof StreamableInterface) {
                throw new \LogicException('Response does not implement StreamableInterface.');
            }

            $stream = $this->response->toStream(false);
        } catch (\Throwable $e) {
            $this->finalizeSpanWithError($e);
            throw $e;
        }

        $this->safeFinalize();

        return $stream;
    }

    public function getInnerResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function getSpan(): SpanInterface
    {
        return $this->span;
    }

    public function finalizeFromStream(): void
    {
        $this->safeFinalize();
    }

    public function finalizeStreamError(\Throwable $e): void
    {
        $this->finalizeSpanWithError($e);
    }

    public function __destruct()
    {
        if ($this->spanEnded) {
            return;
        }

        try {
            $statusCode = $this->response->getInfo('http_code');
            if (\is_int($statusCode) && $statusCode > 0) {
                $this->finalizeSpan($statusCode);

                return;
            }
        } catch (\Throwable) {
            // Destructor must not throw.
        }

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

    private function contentLengthFromHeaders(): ?int
    {
        try {
            $headers = $this->response->getHeaders(false);
        } catch (\Throwable) {
            return null;
        }

        if (isset($headers['content-length'][0]) && is_numeric($headers['content-length'][0])) {
            return (int) $headers['content-length'][0];
        }

        return null;
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

        // A status may have been received even though the accessor threw (e.g. getContent(true) on a 5xx).
        $received = $this->receivedStatusCode();
        if (null !== $received) {
            $this->span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $received);
        }

        $this->span->recordException($e);
        $this->span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($e));
        $this->span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        $this->endSpan();
    }

    private function receivedStatusCode(): ?int
    {
        try {
            $code = $this->response->getInfo('http_code');
        } catch (\Throwable) {
            return null;
        }

        return \is_int($code) && $code > 0 ? $code : null;
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

        $version = ProtocolVersion::fromTransportInfo($info['http_version'] ?? null);
        if (null !== $version) {
            $this->span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $version);
        }

        if (!$this->hasServerAddress) {
            $effectiveUrl = $info['url'] ?? null;
            if (\is_string($effectiveUrl) && \is_array($parsed = parse_url($effectiveUrl)) && null !== ($host = UrlParts::host($parsed))) {
                $this->span->setAttribute(ServerAttributes::SERVER_ADDRESS, $host);
                $this->span->setAttribute(UrlAttributes::URL_FULL, UrlSanitizer::sanitizeUrl($effectiveUrl));

                $port = UrlParts::port($parsed);
                if (null !== $port) {
                    $this->span->setAttribute(ServerAttributes::SERVER_PORT, $port);
                }
            }
        }
    }
}
