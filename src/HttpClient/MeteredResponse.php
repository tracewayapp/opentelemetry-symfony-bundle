<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Wraps a response to finalize HTTP client metrics once the response resolves.
 *
 * Symfony HttpClient responses are lazy: the actual HTTP call happens when
 * the caller first accesses getStatusCode(), getHeaders() or getContent().
 * This wrapper ensures metrics are recorded at that moment with the correct
 * status code and body size, mirroring {@see TracedResponse} on the trace side.
 */
final class MeteredResponse implements ResponseInterface
{
    private bool $finalized = false;

    /**
     * @param array<non-empty-string, string|int> $attributes
     */
    public function __construct(
        private readonly ResponseInterface $response,
        private readonly MeteredHttpClient $recorder,
        private readonly int|float $start,
        private readonly array $attributes,
    ) {}

    public function getStatusCode(): int
    {
        $statusCode = $this->response->getStatusCode();
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

        $bodySize = '' !== $content ? \strlen($content) : null;
        $this->safeFinalize($bodySize);

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
            $this->finalizeWithError($e);
            throw $e;
        }

        $this->safeFinalize(null);

        return $array;
    }

    public function cancel(): void
    {
        $this->finalized = true;
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

    public function __destruct()
    {
        if ($this->finalized) {
            return;
        }

        try {
            $this->finalize($this->response->getStatusCode(), null);
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
        $this->recorder->recordResponse($this->start, $this->attributes, $statusCode, $bodySize);
    }

    private function finalizeWithError(\Throwable $e): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;
        $this->recorder->recordFailure($this->start, $this->attributes, $e);
    }
}
