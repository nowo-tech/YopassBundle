<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Ensures Symfony SecurityBundle is present when the manage Web UI requires authentication.
 */
final class ManageWebUiSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_yopass.web_ui.enabled')) {
            return;
        }

        if (!$container->getParameter('nowo_yopass.web_ui.enabled')) {
            return;
        }

        /** @var array<string, mixed> $security */
        $security = $container->getParameter('nowo_yopass.security');

        if (($security['allow_unauthenticated'] ?? false) === true) {
            return;
        }

        if (!$container->hasExtension('security')) {
            throw new LogicException('The manage Web UI requires Symfony SecurityBundle when "nowo_yopass.security.allow_unauthenticated" is false. Install symfony/security-bundle or set "nowo_yopass.security.allow_unauthenticated: true" for demo-only use.');
        }
    }
}
