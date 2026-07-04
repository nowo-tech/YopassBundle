<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit;

use Nowo\YopassBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\YopassBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class YopassBundleTest extends TestCase
{
    public function testTranslationDomainConstant(): void
    {
        self::assertSame('NowoYopassBundle', YopassBundle::TRANSLATION_DOMAIN);
    }

    public function testBuildRegistersTwigPathsPass(): void
    {
        $container = new ContainerBuilder();
        (new YopassBundle())->build($container);

        $passes = $container->getCompilerPassConfig()->getPasses();
        self::assertNotEmpty(array_filter(
            $passes,
            static fn (CompilerPassInterface $pass): bool => $pass instanceof TwigPathsPass,
        ));
    }

    public function testGetContainerExtension(): void
    {
        $bundle = new YopassBundle();
        self::assertInstanceOf(YopassExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
    }
}
