<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

use function array_values;
use function is_array;
use function is_string;

/**
 * Ensures Symfony SecurityBundle is present when the manage Web UI requires authentication.
 */
final class ManageWebUiSecurityPass implements CompilerPassInterface
{
    /**
     * Fails compilation when the manage Web UI requires authentication but SecurityBundle is missing.
     */
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

        $this->assertSomeRoleCanAccess($security);

        if (!$container->hasExtension('security')) {
            throw new LogicException('The manage Web UI requires Symfony SecurityBundle when "nowo_yopass.security.allow_unauthenticated" is false. Install symfony/security-bundle or set "nowo_yopass.security.allow_unauthenticated: true" for demo-only use.');
        }
    }

    /**
     * Cross-bundle pattern P1: with the built-in role checker, an empty role list denies (never "allow everyone");
     * when every list is empty nobody could ever open the manage UI, which is a configuration error.
     *
     * @param array<string, mixed> $security
     */
    private function assertSomeRoleCanAccess(array $security): void
    {
        $checker = $security['access_checker'] ?? null;
        if (is_string($checker) && $checker !== '') {
            return;
        }

        $roles = is_array($security['roles'] ?? null) ? $security['roles'] : [];
        $lists = [$security['access_roles'] ?? [], ...array_values($roles)];
        foreach ($lists as $list) {
            if (is_array($list) && $list !== []) {
                return;
            }
        }

        throw new LogicException('Every "nowo_yopass.security" role list (access_roles, roles.admin, roles.create, roles.list, roles.revoke) is empty: nobody could open the manage Web UI. Grant at least one role, set "nowo_yopass.web_ui.enabled: false", or configure "security.access_checker".');
    }
}
