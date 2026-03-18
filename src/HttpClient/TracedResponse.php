<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
    ) {}

    public function getStatusCode(): int
    {
        $statusCode = $this->response->getStatusCode();
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

        $this->span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        if ($statusCode >= 400) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->endSpan();
    }

    private function finalizeSpanWithError(\Throwable $e): void
    {
        if ($this->spanEnded) {
            return;
        }

        $this->span->recordException($e);
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
}
