<?php

declare(strict_types=1);

namespace Nowo\YopassBundle;

use Nowo\YopassBundle\DependencyInjection\Compiler\FileHandlerPass;
use Nowo\YopassBundle\DependencyInjection\Compiler\ShareFileHandlerPass;
use Nowo\YopassBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Yopass-style E2E encrypted secret sharing for Symfony applications.
 */
final class YopassBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoYopassBundle';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new FileHandlerPass());
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new ShareFileHandlerPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new YopassExtension();
        }

        return $this->extension;
    }
}
