<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Service\ShareFileHandlerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function sprintf;

/**
 * Wires the configured file handler after application services are registered.
 */
final class FileHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_yopass.file_handler')) {
            return;
        }

        $fileHandlerId = $container->getParameter('nowo_yopass.file_handler');
        if (!is_string($fileHandlerId) || $fileHandlerId === '') {
            return;
        }

        if (!$container->hasDefinition($fileHandlerId) && !$container->hasAlias($fileHandlerId)) {
            throw new InvalidConfigurationException(sprintf(
                'The option "nowo_yopass.file_handler" must reference an existing service id ("%s" not found).',
                $fileHandlerId,
            ));
        }

        $container->setAlias(ShareFileHandlerInterface::class, $fileHandlerId);
        $container->getDefinition(ShareManageController::class)
            ->setArgument('$fileHandler', new Reference(ShareFileHandlerInterface::class));
    }
}
