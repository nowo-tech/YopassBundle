<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Security;

use Nowo\YopassBundle\Security\PublicEndpointRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class PublicEndpointRateLimiterTest extends TestCase
{
    public function testDisabledWhenLimitIsZero(): void
    {
        $limiter = new PublicEndpointRateLimiter(new ArrayAdapter(), 0, 60);
        $request = Request::create('/share/test/consume', 'POST');

        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume($request, 'consume');
        }

        self::assertTrue(true);
    }

    public function testDisabledWhenCachePoolIsNull(): void
    {
        $limiter = new PublicEndpointRateLimiter(null, 10, 60);
        $limiter->consume(Request::create('/share/test'), 'show');

        self::assertTrue(true);
    }

    public function testThrowsWhenLimitExceeded(): void
    {
        $limiter = new PublicEndpointRateLimiter(new ArrayAdapter(), 2, 60);
        $request = Request::create('/share/test/consume', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);

        $limiter->consume($request, 'consume');
        $limiter->consume($request, 'consume');

        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->consume($request, 'consume');
    }

    public function testSeparateCountersPerAction(): void
    {
        $limiter = new PublicEndpointRateLimiter(new ArrayAdapter(), 1, 60);
        $request = Request::create('/share/test', server: ['REMOTE_ADDR' => '203.0.113.11']);

        $limiter->consume($request, 'show');
        $limiter->consume($request, 'consume');

        self::assertTrue(true);
    }
}
