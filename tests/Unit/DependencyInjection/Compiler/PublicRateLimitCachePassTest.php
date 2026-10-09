<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\YopassBundle\DependencyInjection\Compiler\PublicRateLimitCachePass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const E_USER_WARNING;

final class PublicRateLimitCachePassTest extends TestCase
{
    public function testWarnsWhenEnabledAndCacheAppMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(PublicRateLimitCachePass::ENABLED_PARAMETER, true);

        self::assertSame(['cache.app'], $this->capturedWarnings($container));
    }

    public function testSilentWhenCacheAppExists(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(PublicRateLimitCachePass::ENABLED_PARAMETER, true);
        $container->register('cache.app', stdClass::class);

        self::assertSame([], $this->capturedWarnings($container));
    }

    public function testSilentWhenDisabledOrNotConfigured(): void
    {
        $disabled = new ContainerBuilder();
        $disabled->setParameter(PublicRateLimitCachePass::ENABLED_PARAMETER, false);

        self::assertSame([], $this->capturedWarnings($disabled));
        self::assertSame([], $this->capturedWarnings(new ContainerBuilder()));
    }

    /**
     * @return list<string>
     */
    private function capturedWarnings(ContainerBuilder $container): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if ($errno === E_USER_WARNING && str_contains($message, '"cache.app"')) {
                $warnings[] = 'cache.app';
            }

            return true;
        });

        try {
            (new PublicRateLimitCachePass())->process($container);
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }
}
