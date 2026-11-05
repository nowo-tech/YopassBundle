<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;
use function is_string;
use function preg_match;
use function rtrim;

/**
 * Hardening headers on every bundle route (KIT-70; `nowo_yopass.security_headers.enabled`, default true): manage list,
 * create, created, preview, extend, revoke, delete, delete all, public reveal and consume.
 *
 * - `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer` (pages and JSON that handle secret material);
 * - `X-Robots-Tag: noindex, nofollow`;
 * - `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'`: appended to every existing `Content-Security-Policy`
 *   value that has no `frame-ancestors` directive, or sent alone when the response has no policy (it restricts framing
 *   only). Report-Only policies are not touched.
 *
 * Main requests only (a host page that forwards to the list owns its own response headers). Runs late on
 * kernel.response so the host / NelmioSecurityBundle CSP header already exists.
 */
final readonly class SecurityHeadersListener implements EventSubscriberInterface
{
    /** After the usual CSP listeners and {@see WasmCspListener}. */
    public const PRIORITY = -130;

    /**
     * @param list<string> $routeNames Names of every bundle route (`nowo_yopass.routes.*.name`)
     */
    public function __construct(
        private array $routeNames,
        private bool $enabled = true,
    ) {
    }

    /**
     * Subscribes to kernel.response with a late priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', self::PRIORITY]];
    }

    /**
     * Adds the hardening headers to a bundle route response.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !in_array($route, $this->routeNames, true)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('X-Frame-Options', 'DENY');

        $policies = $headers->all('content-security-policy');
        if ($policies === []) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'none'");

            return;
        }

        $updated = [];
        foreach ($policies as $policy) {
            $policy    = (string) $policy;
            $updated[] = preg_match('/(^|[;,])\s*frame-ancestors\s/i', $policy) === 1
                ? $policy
                : rtrim($policy, ' ;') . "; frame-ancestors 'none'";
        }
        $headers->set('Content-Security-Policy', $updated);
    }
}
