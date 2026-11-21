<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Service\ShareCommands;
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
final class ShareFileHandlerPass implements CompilerPassInterface
{
    /**
     * Wires the configured share file handler into the manage controller.
     */
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
            throw new InvalidConfigurationException(sprintf('The option "nowo_yopass.file_handler" must reference an existing service id ("%s" not found).', $fileHandlerId));
        }

        $container->setAlias(ShareFileHandlerInterface::class, $fileHandlerId);

        if ($container->hasDefinition(ShareManageController::class)) {
            $container->getDefinition(ShareManageController::class)
                ->setArgument('$fileHandler', new Reference(ShareFileHandlerInterface::class));
        }

        // 2.1.0: the id-based create accepts file payloads only with a file handler, like the manage UI.
        if ($container->hasDefinition(ShareCommands::class)) {
            $container->getDefinition(ShareCommands::class)->setArgument('$fileSharesEnabled', true);
        }
    }
}
