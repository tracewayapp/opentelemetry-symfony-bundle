<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Traceway\OpenTelemetryBundle\Util\ProtocolVersion;
use Traceway\OpenTelemetryBundle\Util\UrlParts;

/**
 * Wraps a response to finalize HTTP client metrics once the response resolves.
 *
 * Symfony HttpClient responses are lazy: the actual HTTP call happens when
 * the caller first accesses getStatusCode(), getHeaders() or getContent().
 * This wrapper ensures metrics are recorded at that moment with the correct
 * status code and body size, mirroring {@see TracedResponse} on the trace side.
 */
final class MeteredResponse implements ResponseInterface, StreamableInterface
{
    private bool $finalized = false;

    /**
     * @param array<non-empty-string, string|int> $attributes
     */
    public function __construct(
        private readonly ResponseInterface $response,
        private readonly MeteredHttpClient $recorder,
        private readonly int|float $start,
        private array $attributes,
        private readonly ?int $requestBodySize = null,
    ) {
    }

    /**
     * Backfills required server.address/port from the effective URL when the
     * original request URL was relative (base_uri option).
     */
    private function backfillServerAttributes(): void
    {
        if (isset($this->attributes['server.address'])) {
            return;
        }

        try {
            $effectiveUrl = $this->response->getInfo('url');
        } catch (\Throwable) {
            return;
        }

        if (!\is_string($effectiveUrl) || !\is_array($parsed = parse_url($effectiveUrl)) || null === ($host = UrlParts::host($parsed))) {
            return;
        }

        $this->attributes['server.address'] = $host;

        $port = UrlParts::port($parsed);
        if (null !== $port) {
            $this->attributes['server.port'] = $port;
        }
    }

    public function getStatusCode(): int
    {
        try {
            $statusCode = $this->response->getStatusCode();
        } catch (\Throwable $e) {
            $this->finalizeWithError($e);
            throw $e;
        }

        $this->finalize($statusCode, null);

        return $statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        try {
            $headers = $this->response->getHeaders($throw);
        } catch (\Throwable $e) {
            $this->finalizeWithError($e);
            throw $e;
        }

        $bodySize = null;
        if (isset($headers['content-length'][0]) && is_numeric($headers['content-length'][0])) {
            $bodySize = (int) $headers['content-length'][0];
        }

        $this->safeFinalize($bodySize);

        return $headers;
    }

    public function getContent(bool $throw = true): string
    {
        try {
            $content = $this->response->getContent($throw);
        } catch (\Throwable $e) {
            $this->finalizeWithError($e);
            throw $e;
        }

        $bodySize = $this->contentLengthFromHeaders() ?? ('' !== $content ? \strlen($content) : null);
        $this->safeFinalize($bodySize);

        return $content;
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

    /**
     * @return array<mixed>
     */
    public function toArray(bool $throw = true): array
    {
        try {
            $array = $this->response->toArray($throw);
        } catch (\Throwable $e) {
            $this->finalizeWithError($e);
            throw $e;
        }

        $this->safeFinalize(null);

        return $array;
    }

    public function cancel(): void
    {
        if (!$this->finalized) {
            $this->finalized = true;
            $this->backfillServerAttributes();

            // If headers already arrived, a status was received and semconv requires it.
            try {
                $code = $this->response->getInfo('http_code');
                if (\is_int($code) && $code > 0) {
                    $this->attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $code;
                }
            } catch (\Throwable) {
            }

            $this->recorder->recordCancellation($this->start, $this->attributes, $this->requestBodySize);
        }

        $this->response->cancel();
    }

    /**
     * @return resource
     */
    public function toStream(bool $throw = true)
    {
        try {
            if ($throw) {
                $this->getHeaders(true);
            }

            if (!$this->response instanceof StreamableInterface) {
                throw new \LogicException('Response does not implement StreamableInterface.');
            }

            $stream = $this->response->toStream(false);
        } catch (\Throwable $e) {
            $this->finalizeWithError($e);
            throw $e;
        }

        $this->safeFinalize(null);

        return $stream;
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    public function getInnerResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function finalizeFromStream(): void
    {
        $this->safeFinalize(null);
    }

    public function finalizeStreamError(\Throwable $e): void
    {
        $this->finalizeWithError($e);
    }

    public function __destruct()
    {
        if ($this->finalized) {
            return;
        }

        try {
            $statusCode = $this->response->getInfo('http_code');
            if (\is_int($statusCode) && $statusCode > 0) {
                $this->finalize($statusCode, null);
            } else {
                $this->finalized = true;
            }
        } catch (\Throwable) {
            // Destructor must not throw.
        }
    }

    private function safeFinalize(?int $bodySize): void
    {
        try {
            $this->finalize($this->response->getStatusCode(), $bodySize);
        } catch (\Throwable $e) {
            $this->finalizeWithError($e);
        }
    }

    private function finalize(int $statusCode, ?int $bodySize): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;
        $this->backfillServerAttributes();
        $this->enrichProtocolVersion();
        $this->recorder->recordResponse($this->start, $this->attributes, $statusCode, $bodySize, $this->requestBodySize);
    }

    private function enrichProtocolVersion(): void
    {
        try {
            $version = ProtocolVersion::fromTransportInfo($this->response->getInfo('http_version'));
        } catch (\Throwable) {
            return;
        }

        if (null !== $version) {
            $this->attributes['network.protocol.version'] = $version;
        }
    }

    private function finalizeWithError(\Throwable $e): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;
        $this->backfillServerAttributes();
        $this->enrichProtocolVersion();

        // A status may have been received even though the accessor threw (e.g. getContent(true) on a 5xx).
        try {
            $code = $this->response->getInfo('http_code');
            if (\is_int($code) && $code > 0) {
                $this->attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $code;
            }
        } catch (\Throwable) {
        }

        $this->recorder->recordFailure($this->start, $this->attributes, $e, $this->requestBodySize);
    }
}
