<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\EventListener;

use Nowo\YopassBundle\EventListener\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersListenerTest extends TestCase
{
    private const ROUTES = ['nowo_yopass_index', 'nowo_yopass_create', 'nowo_yopass_extend', 'nowo_yopass_public_share'];

    public function testSubscribesLate(): void
    {
        self::assertSame([KernelEvents::RESPONSE => ['onKernelResponse', SecurityHeadersListener::PRIORITY]], SecurityHeadersListener::getSubscribedEvents());
    }

    public function testEveryBundleRouteGetsTheHardeningHeaders(): void
    {
        foreach (self::ROUTES as $route) {
            $response = new JsonResponse(['status' => 'ok']);
            $response->headers->set('Cache-Control', 'public, max-age=3600');
            $this->dispatch($route, $response);

            self::assertSame('no-store, private', $response->headers->get('Cache-Control'), $route);
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
            self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
            self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        }
    }

    public function testFrameAncestorsIsAppendedToTheHostPolicyUnlessPresent(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', ["script-src 'self';", "default-src 'self'; frame-ancestors 'self'"]);
        $response->headers->set('Content-Security-Policy-Report-Only', "script-src 'none'");

        $this->dispatch('nowo_yopass_index', $response);

        self::assertSame(
            ["script-src 'self'; frame-ancestors 'none'", "default-src 'self'; frame-ancestors 'self'"],
            $response->headers->all('content-security-policy'),
        );
        self::assertSame("script-src 'none'", $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testOtherRoutesSubRequestsAndDisabledAreUntouched(): void
    {
        foreach ([
            [new SecurityHeadersListener(self::ROUTES), 'app_home', HttpKernelInterface::MAIN_REQUEST],
            [new SecurityHeadersListener(self::ROUTES), null, HttpKernelInterface::MAIN_REQUEST],
            [new SecurityHeadersListener(self::ROUTES), 'nowo_yopass_index', HttpKernelInterface::SUB_REQUEST],
            [new SecurityHeadersListener(self::ROUTES, false), 'nowo_yopass_index', HttpKernelInterface::MAIN_REQUEST],
        ] as [$listener, $route, $type]) {
            $response = new Response();
            $request  = new Request();
            if ($route !== null) {
                $request->attributes->set('_route', $route);
            }
            $listener->onKernelResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $type, $response));

            self::assertFalse($response->headers->has('X-Frame-Options'));
            self::assertFalse($response->headers->has('X-Robots-Tag'));
            self::assertFalse($response->headers->has('Content-Security-Policy'));
        }
    }

    private function dispatch(string $route, Response $response): void
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        (new SecurityHeadersListener(self::ROUTES))->onKernelResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
    }
}
