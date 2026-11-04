<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_split;
use function strtolower;
use function trim;

/**
 * Opt-in (`nowo_yopass.csp.wasm_unsafe_eval: true`): adds `'wasm-unsafe-eval'` to the host's Content-Security-Policy
 * on the pages that load yopass.js (libsodium compiles WebAssembly).
 *
 * Only the configured yopass.js routes ({@see self::routeNames()}) are touched, only existing
 * `Content-Security-Policy` / `Content-Security-Policy-Report-Only` headers are edited (a policy is never created),
 * and the policy is never loosened beyond that single keyword:
 * - `script-src` present: `'wasm-unsafe-eval'` is appended (unless it or `'unsafe-eval'` is already there);
 * - no `script-src` but `default-src`: a `script-src` copying the `default-src` sources plus `'wasm-unsafe-eval'`;
 * - neither, or a `'none'` script policy: unchanged (scripts are unrestricted, or not allowed at all).
 *
 * Runs late on kernel.response ({@see self::PRIORITY}) so the host / NelmioSecurityBundle header is already set.
 */
final readonly class WasmCspListener implements EventSubscriberInterface
{
    /** After the usual CSP listeners (priority 0), before the session / streamed-response listeners. */
    public const PRIORITY = -128;

    public const KEYWORD = "'wasm-unsafe-eval'";

    private const HEADERS = ['Content-Security-Policy', 'Content-Security-Policy-Report-Only'];

    /**
     * @param list<string> $routeNames Route names of the pages that load yopass.js
     * @param bool $enabled {@code nowo_yopass.csp.wasm_unsafe_eval}
     */
    public function __construct(
        private array $routeNames,
        private bool $enabled = false,
    ) {
    }

    /**
     * Route config keys ({@code nowo_yopass.routes.<key>}) of the pages that load yopass.js / libsodium.
     *
     * @return list<string>
     */
    public static function routeKeys(): array
    {
        return ['manage', 'created', 'public_show'];
    }

    /**
     * Resolves the route names of the pages that load yopass.js from the {@code nowo_yopass.routes} map.
     *
     * @param array<string, array{path: string, name: string}> $routes
     *
     * @return list<string>
     */
    public static function routeNames(array $routes): array
    {
        $names = [];
        foreach (self::routeKeys() as $key) {
            if (isset($routes[$key]['name']) && $routes[$key]['name'] !== '') {
                $names[] = $routes[$key]['name'];
            }
        }

        return $names;
    }

    /**
     * Subscribes to kernel.response with a late priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', self::PRIORITY]];
    }

    /**
     * Adds 'wasm-unsafe-eval' to the CSP headers of a yopass.js page response.
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
        foreach (self::HEADERS as $name) {
            $values = $headers->all(strtolower($name));
            if ($values === []) {
                continue;
            }

            $headers->set($name, array_map(
                static fn (?string $value): string => self::allowWasm((string) $value),
                $values,
            ));
        }
    }

    /**
     * Returns the policy (possibly several comma-separated policies) with 'wasm-unsafe-eval' allowed for scripts.
     */
    public static function allowWasm(string $policy): string
    {
        $policies = array_map(self::allowWasmInPolicy(...), explode(',', $policy));

        return implode(',', $policies);
    }

    private static function allowWasmInPolicy(string $policy): string
    {
        $directives = [];
        foreach (explode(';', $policy) as $directive) {
            $directive = trim($directive);
            if ($directive !== '') {
                $directives[] = (array) preg_split('/\s+/', $directive);
            }
        }

        $scriptIndex  = null;
        $defaultIndex = null;
        foreach ($directives as $i => $tokens) {
            $name = strtolower((string) $tokens[0]);
            if ($name === 'script-src' && $scriptIndex === null) {
                $scriptIndex = $i;
            } elseif ($name === 'default-src' && $defaultIndex === null) {
                $defaultIndex = $i;
            }
        }

        if ($scriptIndex !== null) {
            if (self::leaveUnchanged(self::sources($directives[$scriptIndex]))) {
                return $policy;
            }

            $directives[$scriptIndex][] = self::KEYWORD;
        } elseif ($defaultIndex !== null) {
            $sources = self::sources($directives[$defaultIndex]);
            if (self::leaveUnchanged($sources)) {
                return $policy;
            }

            $directives[] = ['script-src', ...$sources, self::KEYWORD];
        } else {
            return $policy;
        }

        $leading = $policy !== '' && $policy[0] === ' ' ? ' ' : '';

        return $leading . implode('; ', array_map(static fn (array $tokens): string => implode(' ', $tokens), $directives));
    }

    /**
     * @param array<int, false|string> $tokens
     *
     * @return list<string>
     */
    private static function sources(array $tokens): array
    {
        $sources = [];
        foreach ($tokens as $i => $token) {
            if ($i > 0 && is_string($token) && $token !== '') {
                $sources[] = $token;
            }
        }

        return $sources;
    }

    /**
     * True when the source list already allows WebAssembly compilation, or allows no script at all ('none', which
     * must stay alone: adding a keyword would turn it into an allow-list).
     *
     * @param list<string> $sources
     */
    private static function leaveUnchanged(array $sources): bool
    {
        $lower = array_map(strtolower(...), $sources);

        return in_array(self::KEYWORD, $lower, true)
            || in_array("'unsafe-eval'", $lower, true)
            || (count($lower) === 1 && $lower[0] === "'none'")
            || $lower === [];
    }
}
