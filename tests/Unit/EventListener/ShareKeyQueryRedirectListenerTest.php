<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\EventListener;

use Nowo\YopassBundle\EventListener\ShareKeyQueryRedirectListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ShareKeyQueryRedirectListenerTest extends TestCase
{
    private const SHARE_ID = '00000000-0000-4000-8000-000000000001';

    public function testSubscribesBeforeTheRouterListener(): void
    {
        $events = ShareKeyQueryRedirectListener::getSubscribedEvents();

        self::assertSame(['onKernelRequest', ShareKeyQueryRedirectListener::PRIORITY], $events[KernelEvents::REQUEST]);
        // RouterListener::onKernelRequest runs at priority 32 and logs the matched request URI.
        self::assertGreaterThan(32, ShareKeyQueryRedirectListener::PRIORITY);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyLinks(): iterable
    {
        yield 'decrypt_key' => ['decrypt_key=REDACTED-TEST-KEY', '#decrypt_key=REDACTED-TEST-KEY'];
        yield 'legacy key' => ['key=REDACTED-TEST-KEY', '#decrypt_key=REDACTED-TEST-KEY'];
        yield 'legacy password' => ['password=pass%20word%2B1', '#decrypt_key=pass%20word%2B1'];
        yield 'decrypt_key wins over legacy names' => ['key=other&decrypt_key=first', '#decrypt_key=first'];
        yield 'empty decrypt_key falls back' => ['decrypt_key=&password=fallback', '#decrypt_key=fallback'];
    }

    #[DataProvider('legacyLinks')]
    public function testRedirectsLegacyQueryKeyToTheFragment(string $query, string $expectedFragment): void
    {
        $event = $this->dispatch(Request::create('/share/' . self::SHARE_ID . '?' . $query));

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/share/' . self::SHARE_ID . $expectedFragment, $response->getTargetUrl());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertTrue($event->isPropagationStopped());
    }

    public function testKeepsOtherQueryParametersAndTheRoutePrefixAndBaseUrl(): void
    {
        $request = Request::create(
            '/app/index.php/admin/share/' . self::SHARE_ID . '?utm=mail&decrypt_key=REDACTED-TEST-KEY&key=x',
            'GET',
            [],
            [],
            [],
            ['SCRIPT_FILENAME' => '/srv/app/index.php', 'SCRIPT_NAME' => '/app/index.php', 'PHP_SELF' => '/app/index.php'],
        );

        $event = $this->dispatch($request, '/admin');

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            '/app/index.php/admin/share/' . self::SHARE_ID . '?utm=mail#decrypt_key=REDACTED-TEST-KEY',
            $response->getTargetUrl(),
        );
    }

    public function testRedirectsHeadRequests(): void
    {
        $event = $this->dispatch(Request::create('/share/' . self::SHARE_ID . '?decrypt_key=k', 'HEAD'));

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function untouchedRequests(): iterable
    {
        yield 'short link' => [Request::create('/share/' . self::SHARE_ID)];
        yield 'unrelated query' => [Request::create('/share/' . self::SHARE_ID . '?utm=mail')];
        yield 'empty key values' => [Request::create('/share/' . self::SHARE_ID . '?decrypt_key=%20&key=')];
        yield 'array key value' => [Request::create('/share/' . self::SHARE_ID . '?decrypt_key[]=x')];
        yield 'consume endpoint' => [Request::create('/share/' . self::SHARE_ID . '/consume?decrypt_key=k', 'POST')];
        yield 'consume path with GET' => [Request::create('/share/' . self::SHARE_ID . '/consume?decrypt_key=k')];
        yield 'other route' => [Request::create('/login?password=k')];
        yield 'POST on the share page' => [Request::create('/share/' . self::SHARE_ID . '?decrypt_key=k', 'POST')];
    }

    #[DataProvider('untouchedRequests')]
    public function testLeavesOtherRequestsAlone(Request $request): void
    {
        $event = $this->dispatch($request);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $listener = new ShareKeyQueryRedirectListener($this->routes(), '');
        $event    = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/share/' . self::SHARE_ID . '?decrypt_key=k'),
            HttpKernelInterface::SUB_REQUEST,
        );

        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testHonoursACustomPublicPath(): void
    {
        $routes                        = $this->routes();
        $routes['public_show']['path'] = '/s/{id}';
        $listener                      = new ShareKeyQueryRedirectListener($routes, '');
        $event                         = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/s/abc?decrypt_key=k'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/s/abc#decrypt_key=k', $event->getResponse()->getTargetUrl());
    }

    private function dispatch(Request $request, string $routePrefix = ''): RequestEvent
    {
        $listener = new ShareKeyQueryRedirectListener($this->routes(), $routePrefix);
        $event    = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelRequest($event);

        return $event;
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function routes(): array
    {
        return [
            'public_show'    => ['path' => '/share/{id}', 'name' => 'nowo_yopass_public_share'],
            'public_consume' => ['path' => '/share/{id}/consume', 'name' => 'nowo_yopass_public_consume'],
        ];
    }
}
