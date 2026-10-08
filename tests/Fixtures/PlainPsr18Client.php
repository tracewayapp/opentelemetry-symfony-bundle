<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Fixtures;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

final class PlainPsr18Client implements ClientInterface, ResetInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \LogicException('not used');
    }

    public function reset(): void
    {
    }
}
