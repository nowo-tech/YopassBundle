<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

use function http_build_query;
use function in_array;
use function is_string;
use function preg_match;
use function trim;

use const PHP_QUERY_RFC3986;

/**
 * Moves decryption material out of the query string of the public share page (REQ-SEC-004 M1).
 *
 * One-click links created up to 1.8.0 carry the key or password as `?decrypt_key=` (or the legacy `?key=` /
 * `?password=`), so the browser sends it to the server. This listener runs before the router (and therefore before
 * its "Matched route" log entry and any controller), and answers `303 See Other` to the same path with
 * the material moved into the URL fragment (`#decrypt_key=…`). The fragment is never sent back to the server, the
 * reveal script reads it, and the key no longer stays in the address bar, the history or any later request.
 *
 * The very first request of such a legacy link still reaches the web server / reverse proxy, which may log it; links
 * built by the bundle now use the fragment form directly and never do.
 */
final readonly class ShareKeyQueryRedirectListener implements EventSubscriberInterface
{
    /** Canonical parameter name (fragment form). */
    public const FRAGMENT_PARAM = 'decrypt_key';

    /**
     * Query parameters that may carry decryption material, in priority order.
     *
     * @var list<string>
     */
    public const KEY_QUERY_PARAMS = ['decrypt_key', 'key', 'password'];

    /** Runs before {@see RouterListener} (priority 32). */
    public const PRIORITY = 64;

    private string $publicShowRegex;

    /**
     * @param array<string, array{path: string, name: string}> $routes Resolved {@code nowo_yopass.routes}
     */
    public function __construct(array $routes, string $routePrefix)
    {
        $this->publicShowRegex = (new Route($routePrefix . $routes['public_show']['path']))->compile()->getRegex();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY]];
    }

    /**
     * Redirects `GET|HEAD /share/{id}?decrypt_key=…` to `/share/{id}#decrypt_key=…`.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
            return;
        }

        $material = $this->extractMaterial($request);

        if ($material === null || preg_match($this->publicShowRegex, $request->getPathInfo()) !== 1) {
            return;
        }

        $query = $request->query->all();

        foreach (self::KEY_QUERY_PARAMS as $param) {
            unset($query[$param]);
        }

        $location = $request->getBaseUrl() . $request->getPathInfo();

        if ($query !== []) {
            $location .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $location .= '#' . http_build_query([self::FRAGMENT_PARAM => $material], '', '&', PHP_QUERY_RFC3986);

        $response = new RedirectResponse($location, Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex');

        $event->setResponse($response);
    }

    /**
     * Returns the first non-empty decryption material found in the query string, or null.
     */
    private function extractMaterial(Request $request): ?string
    {
        $query = $request->query->all();

        foreach (self::KEY_QUERY_PARAMS as $param) {
            $value = $query[$param] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
