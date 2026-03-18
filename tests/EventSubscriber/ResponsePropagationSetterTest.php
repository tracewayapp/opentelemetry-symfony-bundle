<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventSubscriber;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Traceway\OpenTelemetryBundle\EventSubscriber\ResponsePropagationSetter;

final class ResponsePropagationSetterTest extends TestCase
{
    public function testImplementsPropagationSetterInterface(): void
    {
        self::assertInstanceOf(PropagationSetterInterface::class, ResponsePropagationSetter::instance());
    }

    public function testInstanceReturnsSameObject(): void
    {
        self::assertSame(ResponsePropagationSetter::instance(), ResponsePropagationSetter::instance());
    }

    public function testSetInjectsHeaderIntoResponse(): void
    {
        $response = new Response();
        $setter = ResponsePropagationSetter::instance();

        $setter->set($response, 'Server-Timing', 'traceparent;desc="00-abc-def-01"');

        self::assertSame(
            'traceparent;desc="00-abc-def-01"',
            $response->headers->get('Server-Timing'),
        );
    }

    public function testSetOverwritesExistingHeader(): void
    {
        $response = new Response();
        $response->headers->set('traceresponse', 'old');
        $setter = ResponsePropagationSetter::instance();

        $setter->set($response, 'traceresponse', 'new');

        self::assertSame('new', $response->headers->get('traceresponse'));
    }
}
