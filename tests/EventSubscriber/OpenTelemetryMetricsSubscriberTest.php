<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventSubscriber;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetryMetricsSubscriber;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class OpenTelemetryMetricsSubscriberTest extends TestCase
{
    use OTelTestTrait;

    private OpenTelemetryMetricsSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->subscriber = new OpenTelemetryMetricsSubscriber('test');
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testSubscribedEvents(): void
    {
        $events = OpenTelemetryMetricsSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        self::assertArrayHasKey(KernelEvents::RESPONSE, $events);
        self::assertArrayHasKey(KernelEvents::FINISH_REQUEST, $events);
    }

    public function testMainRequestEmitsDurationAndActiveCount(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();

        self::assertArrayHasKey('http.server.request.duration', $metrics);
        $duration = $metrics['http.server.request.duration'];
        self::assertSame('s', $duration->unit);

        $durationPoints = [...$duration->data->dataPoints];
        self::assertCount(1, $durationPoints);
        self::assertSame(1, $durationPoints[0]->count);

        $attr = $durationPoints[0]->attributes->toArray();
        self::assertSame('GET', $attr['http.request.method']);
        self::assertSame('http', $attr['url.scheme']);
        self::assertSame(200, $attr['http.response.status_code']);
        self::assertArrayNotHasKey('error.type', $attr);

        self::assertArrayHasKey('http.server.active_requests', $metrics);
        $active = [...$metrics['http.server.active_requests']->data->dataPoints];
        self::assertSame(0, array_sum(array_map(static fn ($p): int|float => $p->value, $active)));
    }

    public function testRouteAttributeUsesTemplate(): void
    {
        $request = Request::create('/api/items/42', 'GET');
        $request->attributes->set('_route_params', ['id' => '42']);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onRoute(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.server.request.duration']->data->dataPoints];
        self::assertSame('/api/items/{id}', $points[0]->attributes->toArray()['http.route']);
    }

    public function testExceptionAddsErrorType(): void
    {
        $request = Request::create('/api/error', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $exception = new \RuntimeException('boom');

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 500)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.server.request.duration']->data->dataPoints];
        self::assertSame('RuntimeException', $points[0]->attributes->toArray()['error.type']);
    }

    public function testNamespacedExceptionUsesFqcn(): void
    {
        $request = Request::create('/api/error', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $exception = new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('bad');

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 400)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.server.request.duration']->data->dataPoints];
        self::assertSame(
            \Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class,
            $points[0]->attributes->toArray()['error.type'],
        );
    }

    public function testAnonymousExceptionFallsBackToParentClass(): void
    {
        $request = Request::create('/api/error', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $exception = new class('boom') extends \RuntimeException {};

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 500)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.server.request.duration']->data->dataPoints];
        self::assertSame('RuntimeException', $points[0]->attributes->toArray()['error.type']);
    }

    public function testDurationHistogramUsesSecondBasedBuckets(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.server.request.duration']->data->dataPoints];
        self::assertSame(
            OpenTelemetryMetricsSubscriber::DURATION_BUCKET_BOUNDARIES,
            $points[0]->explicitBounds,
        );
    }

    public function testExcludedPathEmitsNothing(): void
    {
        $subscriber = new OpenTelemetryMetricsSubscriber('test', ['/health']);
        $request = Request::create('/health', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame([], $this->collectMetrics());
    }

    public function testSubRequestEmitsNothing(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, new Response()));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));

        self::assertSame([], $this->collectMetrics());
    }

    public function testBodySizesFromContentLengthHeaders(): void
    {
        $request = Request::create('/upload', 'POST');
        $request->headers->set('Content-Length', '1024');
        $response = new Response('hello', 200);
        $response->headers->set('Content-Length', '5');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();

        self::assertArrayHasKey('http.server.request.body.size', $metrics);
        $reqPts = [...$metrics['http.server.request.body.size']->data->dataPoints];
        self::assertSame(1024, $reqPts[0]->sum);

        self::assertArrayHasKey('http.server.response.body.size', $metrics);
        $resPts = [...$metrics['http.server.response.body.size']->data->dataPoints];
        self::assertSame(5, $resPts[0]->sum);
    }

    public function testNoBodySizeEmittedWithoutContentLength(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        self::assertArrayNotHasKey('http.server.request.body.size', $metrics);
        self::assertArrayNotHasKey('http.server.response.body.size', $metrics);
    }

    public function testActiveRequestsIncrementsAndDecrements(): void
    {
        $request1 = Request::create('/a', 'GET');
        $request2 = Request::create('/b', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request1, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onRequest(new RequestEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request1, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request1, HttpKernelInterface::MAIN_REQUEST));

        // Snapshot while request2 is still in flight
        $mid = $this->collectMetrics();
        $active = $mid['http.server.active_requests'] ?? null;
        self::assertNotNull($active);

        $sum = 0;
        foreach ($active->data->dataPoints as $point) {
            $sum += $point->value;
        }
        self::assertSame(1, $sum, 'One request still active');

        $this->subscriber->onResponse(new ResponseEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST));
    }

    public function testResetClearsCachedMeter(): void
    {
        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $this->subscriber->reset();

        $request2 = Request::create('/api/items', 'GET');
        $this->subscriber->onRequest(new RequestEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('http.server.request.duration', $metrics);
        $points = [...$metrics['http.server.request.duration']->data->dataPoints];
        self::assertSame(2, $points[0]->count);
    }

    public function testOnRequestSwallowsActiveCounterFailure(): void
    {
        $this->injectBrokenActiveRequestsCounter();

        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('http.server.request.duration', $metrics);
    }

    public function testOnResponseSwallowsDurationRecordingFailure(): void
    {
        $this->injectBrokenDurationHistogram();

        $request = Request::create('/api/items', 'GET');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $this->subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertTrue(true);
    }

    private function injectBrokenActiveRequestsCounter(): void
    {
        $broken = $this->createMock(UpDownCounterInterface::class);
        $broken->method('add')->willThrowException(new \RuntimeException('metrics down'));

        $reflection = new \ReflectionClass($this->subscriber);
        $property = $reflection->getProperty('activeRequests');
        $property->setAccessible(true);
        $property->setValue($this->subscriber, $broken);
    }

    private function injectBrokenDurationHistogram(): void
    {
        $broken = $this->createMock(HistogramInterface::class);
        $broken->method('record')->willThrowException(new \RuntimeException('metrics down'));

        $reflection = new \ReflectionClass($this->subscriber);
        $property = $reflection->getProperty('duration');
        $property->setAccessible(true);
        $property->setValue($this->subscriber, $broken);
    }
}
