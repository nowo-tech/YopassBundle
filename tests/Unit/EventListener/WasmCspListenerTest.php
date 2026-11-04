<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\EventListener;

use Nowo\YopassBundle\EventListener\WasmCspListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class WasmCspListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function policies(): iterable
    {
        yield 'script-src appended' => [
            "default-src 'self'; script-src 'self' 'nonce-abc' 'strict-dynamic'",
            "default-src 'self'; script-src 'self' 'nonce-abc' 'strict-dynamic' 'wasm-unsafe-eval'",
        ];
        yield 'already allowed' => ["script-src 'self' 'wasm-unsafe-eval'", "script-src 'self' 'wasm-unsafe-eval'"];
        yield 'unsafe-eval already covers wasm' => ["script-src 'self' 'unsafe-eval'", "script-src 'self' 'unsafe-eval'"];
        yield 'script none untouched' => ["script-src 'none'", "script-src 'none'"];
        yield 'empty script-src untouched' => ['script-src; img-src *', 'script-src; img-src *'];
        yield 'default-src derived' => [
            "default-src 'self' https://cdn.example.test; img-src 'self'",
            "default-src 'self' https://cdn.example.test; img-src 'self'; script-src 'self' https://cdn.example.test 'wasm-unsafe-eval'",
        ];
        yield 'default-src none untouched' => ["default-src 'none'", "default-src 'none'"];
        yield 'default-src with wasm untouched' => ["default-src 'self' 'wasm-unsafe-eval'", "default-src 'self' 'wasm-unsafe-eval'"];
        yield 'no script restriction untouched' => ["img-src 'self'; frame-ancestors 'none'", "img-src 'self'; frame-ancestors 'none'"];
        yield 'case-insensitive directive' => ["SCRIPT-SRC 'self'", "SCRIPT-SRC 'self' 'wasm-unsafe-eval'"];
        yield 'several policies' => [
            "script-src 'self', default-src 'self'",
            "script-src 'self' 'wasm-unsafe-eval', default-src 'self'; script-src 'self' 'wasm-unsafe-eval'",
        ];
    }

    #[DataProvider('policies')]
    public function testAllowWasm(string $policy, string $expected): void
    {
        self::assertSame($expected, WasmCspListener::allowWasm($policy));
    }

    public function testRouteNamesFromRoutesMap(): void
    {
        $routes = [
            'manage'         => ['path' => '/m', 'name' => 'app_secrets'],
            'created'        => ['path' => '/c', 'name' => 'app_secrets_created'],
            'public_show'    => ['path' => '/s', 'name' => 'app_secret_public'],
            'public_consume' => ['path' => '/s/c', 'name' => 'app_secret_consume'],
            'create'         => ['path' => '/n', 'name' => 'app_secrets_create'],
        ];

        self::assertSame(['app_secrets', 'app_secrets_created', 'app_secret_public'], WasmCspListener::routeNames($routes));
        self::assertSame([], WasmCspListener::routeNames([]));
        self::assertSame(['manage', 'created', 'public_show'], WasmCspListener::routeKeys());
    }

    public function testSubscribesLateToKernelResponse(): void
    {
        self::assertSame([KernelEvents::RESPONSE => ['onKernelResponse', WasmCspListener::PRIORITY]], WasmCspListener::getSubscribedEvents());
    }

    public function testEditsBothHeadersOnYopassRoutesWhenEnabled(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "script-src 'self'");
        $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'");

        (new WasmCspListener(['nowo_yopass_index'], true))->onKernelResponse($this->event('nowo_yopass_index', $response));

        self::assertSame("script-src 'self' 'wasm-unsafe-eval'", $response->headers->get('Content-Security-Policy'));
        self::assertSame("default-src 'self'; script-src 'self' 'wasm-unsafe-eval'", $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testNeverCreatesAPolicy(): void
    {
        $response = new Response();

        (new WasmCspListener(['nowo_yopass_index'], true))->onKernelResponse($this->event('nowo_yopass_index', $response));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testLeavesOtherRoutesSubRequestsAndDisabledAlone(): void
    {
        $policy = "script-src 'self'";

        foreach ([
            [new WasmCspListener(['nowo_yopass_index'], true), 'app_home', HttpKernelInterface::MAIN_REQUEST],
            [new WasmCspListener(['nowo_yopass_index'], true), null, HttpKernelInterface::MAIN_REQUEST],
            [new WasmCspListener(['nowo_yopass_index'], true), 'nowo_yopass_index', HttpKernelInterface::SUB_REQUEST],
            [new WasmCspListener(['nowo_yopass_index']), 'nowo_yopass_index', HttpKernelInterface::MAIN_REQUEST],
        ] as [$listener, $route, $type]) {
            $response = new Response();
            $response->headers->set('Content-Security-Policy', $policy);
            $listener->onKernelResponse($this->event($route, $response, $type));
            self::assertSame($policy, $response->headers->get('Content-Security-Policy'));
        }
    }

    private function event(?string $route, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $type, $response);
    }
}
