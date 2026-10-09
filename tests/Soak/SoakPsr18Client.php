<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Soak;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class SoakPsr18Client implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Length' => '2'], 'ok');
    }
}
