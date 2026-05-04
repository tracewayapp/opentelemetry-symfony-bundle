<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Util;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

final class ErrorTypeResolverTest extends TestCase
{
    public function testRootNamespaceExceptionReturnsShortName(): void
    {
        self::assertSame('RuntimeException', ErrorTypeResolver::resolve(new \RuntimeException()));
    }

    public function testNamespacedExceptionReturnsFqcn(): void
    {
        $exception = new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('bad');

        self::assertSame(
            \Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class,
            ErrorTypeResolver::resolve($exception),
        );
    }

    public function testAnonymousExceptionFallsBackToParentClass(): void
    {
        $exception = new class('boom') extends \RuntimeException {};

        self::assertSame('RuntimeException', ErrorTypeResolver::resolve($exception));
    }

    public function testAnonymousExceptionWithThrowableParentFallsBackToThrowable(): void
    {
        $exception = new class('boom') extends \Exception {};

        self::assertSame('Exception', ErrorTypeResolver::resolve($exception));
    }
}
