<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventSubscriber;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class OpenTelemetrySubscriberTest extends TestCase
{
    use OTelTestTrait;

    private OpenTelemetrySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->subscriber = new OpenTelemetrySubscriber();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testSubscribedEvents(): void
    {
        $events = OpenTelemetrySubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        self::assertArrayHasKey(KernelEvents::RESPONSE, $events);
        self::assertArrayHasKey(KernelEvents::FINISH_REQUEST, $events);
        self::assertArrayHasKey(KernelEvents::TERMINATE, $events);
    }

    public function testMainRequestCreatesServerSpan(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(SpanKind::KIND_SERVER, $spans[0]->getKind());
    }

    public function testSpanNameUpdatedAfterRouting(): void
    {
        $request = Request::create('/api/items/42', 'GET');
        $request->attributes->set('_route_params', ['id' => '42']);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onRoute(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertSame('GET /api/items/{id}', $spans[0]->getName());
    }

    public function testExceptionRecordedOnSpan(): void
    {
        $request = Request::create('/api/error', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $exception = new \RuntimeException('Something broke');

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 500)));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Something broke', $spans[0]->getStatus()->getDescription());

        $events = $spans[0]->getEvents();
        self::assertNotEmpty($events);
        self::assertSame('exception', $events[0]->getName());
    }

    public function testResponseStatusCodeRecorded(): void
    {
        $request = Request::create('/api/items', 'POST');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 201)));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(201, $attributes['http.response.status_code']);
    }

    public function testErrorStatusThreshold(): void
    {
        $subscriber = new OpenTelemetrySubscriber(errorStatusThreshold: 400);
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 404)));
        $subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testExcludedPathSkipsSpan(): void
    {
        $subscriber = new OpenTelemetrySubscriber(excludedPaths: ['/health']);
        $request = Request::create('/health', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($request->attributes->get('_otel.span'));
        self::assertEmpty($this->exporter->getSpans());
    }

    public function testRecordClientIpDisabled(): void
    {
        $subscriber = new OpenTelemetrySubscriber(recordClientIp: false);
        $request = Request::create('/api/items', 'GET', server: ['REMOTE_ADDR' => '192.168.1.1']);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey('client.address', $attributes);
    }

    public function testRouteParamsReplacedLongestFirst(): void
    {
        $request = Request::create('/api/users/123/posts/12', 'GET');
        $request->attributes->set('_route_params', [
            'userId' => '123',
            'postId' => '12',
        ]);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onRoute(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertSame('GET /api/users/{userId}/posts/{postId}', $spans[0]->getName());
    }

    public function testBodySizeFromContentLengthHeader(): void
    {
        $request = Request::create('/api/items', 'POST', server: [
            'HTTP_CONTENT_LENGTH' => '256',
        ]);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $response = new Response('Hello World', 200, ['Content-Length' => '11']);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, $response));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(256, $attributes['http.request.body.size']);
        self::assertSame(11, $attributes['http.response.body.size']);
    }

    public function testBodySizeFallbackFromContent(): void
    {
        $request = Request::create('/api/items', 'POST', content: '{"name":"Test"}');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $response = new Response('{"id":1}');

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, $response));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(15, $attributes['http.request.body.size']);
        self::assertSame(8, $attributes['http.response.body.size']);
    }

    public function testRequestAttributesIncluded(): void
    {
        $request = Request::create('https://example.com/api/items', 'POST');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertSame('POST', $attributes['http.request.method']);
        self::assertSame('/api/items', $attributes['url.path']);
        self::assertSame('https', $attributes['url.scheme']);
        self::assertSame('example.com', $attributes['server.address']);
    }

    public function testQueryStringCaptured(): void
    {
        $request = Request::create('/api/items?page=2&limit=10', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertStringContainsString('page=2', $attributes['url.query']);
        self::assertStringContainsString('limit=10', $attributes['url.query']);
    }

    public function testQueryStringNullWhenAbsent(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertArrayNotHasKey('url.query', $attributes);
    }

    public function testSubRequestCreatesInternalSpan(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $mainRequest = Request::create('/main', 'GET');
        $subRequest = Request::create('/sub', 'GET');

        $this->subscriber->onRequest(new RequestEvent($kernel, $mainRequest, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onRequest(new RequestEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST));

        $this->subscriber->onResponse(new ResponseEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST, new Response()));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST));
        $this->subscriber->onFinishRequestEndSpan(new FinishRequestEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST));

        $this->subscriber->onResponse(new ResponseEvent($kernel, $mainRequest, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $mainRequest, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $mainRequest, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);

        $subSpan = $spans[0];
        $mainSpan = $spans[1];

        self::assertSame(SpanKind::KIND_INTERNAL, $subSpan->getKind());
        self::assertSame(SpanKind::KIND_SERVER, $mainSpan->getKind());

        self::assertSame(
            $mainSpan->getContext()->getTraceId(),
            $subSpan->getContext()->getTraceId(),
        );
        self::assertSame(
            $mainSpan->getContext()->getSpanId(),
            $subSpan->getParentSpanId(),
        );
    }

    public function testServiceVersionAttributePresent(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertSame(OpenTelemetryBundle::VERSION, $attributes['service.version']);
    }

    public function testIncomingTraceContextCreatesChildSpan(): void
    {
        $traceId = str_repeat('a', 32);
        $parentSpanId = str_repeat('b', 16);

        $request = Request::create('/api/items', 'GET');
        $request->headers->set('traceparent', "00-{$traceId}-{$parentSpanId}-01");
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame($traceId, $spans[0]->getContext()->getTraceId());
        self::assertSame($parentSpanId, $spans[0]->getParentSpanId());
    }

    public function testRequestTimeFloatUsedAsStartTimestamp(): void
    {
        $requestTime = 1700000000.123;
        $request = Request::create('/api/items', 'GET', server: [
            'REQUEST_TIME_FLOAT' => $requestTime,
        ]);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);

        $expectedNanos = (int) ($requestTime * 1_000_000_000);
        self::assertSame($expectedNanos, $spans[0]->getStartEpochNanos());
    }

    public function testMainRequestSpanNotEndedOnFinishRequest(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/items', 'GET');

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestEndSpan(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertEmpty($this->exporter->getSpans());

        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testErrorStatusNotOverwrittenByThresholdWhenExceptionWasRecorded(): void
    {
        $subscriber = new OpenTelemetrySubscriber(errorStatusThreshold: 500);
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $exception = new \RuntimeException('Oops');

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 500)));
        $subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Oops', $spans[0]->getStatus()->getDescription());
    }

    public function testOnRouteWithNonArrayRouteParams(): void
    {
        $request = Request::create('/api/items', 'GET');
        $request->attributes->set('_route_params', null);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onRoute(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        self::assertSame('GET /api/items', $spans[0]->getName());
    }

    public function testOnRouteWithoutSpanIsNoop(): void
    {
        $subscriber = new OpenTelemetrySubscriber(excludedPaths: ['/api']);
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onRoute(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testOnExceptionWithoutSpanIsNoop(): void
    {
        $subscriber = new OpenTelemetrySubscriber(excludedPaths: ['/api']);
        $request = Request::create('/api/error', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('err')));

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testOnResponseWithoutSpanIsNoop(): void
    {
        $subscriber = new OpenTelemetrySubscriber(excludedPaths: ['/api']);
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testTracewayDistributedTraceIdHeaderRecorded(): void
    {
        $request = Request::create('/api/items', 'GET');
        $request->headers->set('traceway-trace-id', 'abc-123-distributed');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertSame('abc-123-distributed', $attributes['traceway.distributed_trace_id']);
    }

    public function testTracewayDistributedTraceIdAbsentWhenHeaderMissing(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onFinishRequestDetachScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertArrayNotHasKey('traceway.distributed_trace_id', $attributes);
    }
}
