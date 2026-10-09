<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function trigger_error;

use const E_USER_WARNING;

/**
 * Warns when public share rate limiting is enabled but no "cache.app" pool exists.
 *
 * Runs on the merged container (the extension's isolated container cannot see framework services);
 * without cache.app, PublicEndpointRateLimiter receives a null pool and skips limiting.
 */
final class PublicRateLimitCachePass implements CompilerPassInterface
{
    public const ENABLED_PARAMETER = 'nowo_yopass.public_rate_limit.enabled';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::ENABLED_PARAMETER) || $container->getParameter(self::ENABLED_PARAMETER) !== true) {
            return;
        }

        if ($container->has('cache.app')) {
            return;
        }

        trigger_error(
            'nowo_yopass.public_rate_limit is enabled but the "cache.app" service is missing; public rate limiting will be skipped. Enable framework cache or set public_rate_limit.enabled: false.',
            E_USER_WARNING,
        );
    }
}
