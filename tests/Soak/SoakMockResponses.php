<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Soak;

use Symfony\Component\HttpClient\Response\MockResponse;

final class SoakMockResponses
{
    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        return new MockResponse('{"ok":true}', ['http_code' => 200, 'response_headers' => ['content-length' => '11']]);
    }
}
