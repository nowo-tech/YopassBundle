<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\YopassBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class YopassBundleIntegrationTest extends TestCase
{
    public function testExtensionAliasMatchesBundleConfiguration(): void
    {
        $bundle = new YopassBundle();
        self::assertSame('nowo_yopass', $bundle->getContainerExtension()?->getAlias());
    }

    public function testContainerBuildsCoreServicesFromMinimalConfig(): void
    {
        $container = new ContainerBuilder();
        (new YopassExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        self::assertTrue($container->hasAlias(YopassAccessCheckerInterface::class));
        self::assertTrue($container->hasDefinition(\Nowo\YopassBundle\Routing\YopassRouteLoader::class));
        self::assertTrue($container->hasAlias(ShareRepositoryInterface::class));
        self::assertTrue($container->hasDefinition(\Nowo\YopassBundle\Service\ShareRetriever::class));
    }
}
