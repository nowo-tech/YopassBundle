<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Role-based default implementation of {@see YopassAccessPolicyInterface} and of the deprecated
 * {@see YopassAccessCheckerInterface}: it decides on the roles of the current security token (role hierarchy
 * included), whatever user is passed, so both forms give the same answer. Deny by default: an empty role list grants
 * nothing.
 */
final readonly class ConfigurableYopassAccessChecker implements YopassAccessPolicyInterface, YopassAccessCheckerInterface
{
    /**
     * Creates the checker with the configured role lists (an empty list grants nothing).
     *
     * @param list<string> $adminRoles
     * @param list<string> $accessRoles
     * @param list<string> $createRoles
     * @param list<string> $listRoles
     * @param list<string> $revokeRoles
     */
    public function __construct(
        private Security $security,
        private array $adminRoles,
        private array $accessRoles,
        private array $createRoles,
        private array $listRoles,
        private array $revokeRoles,
    ) {
    }

    /**
     * Whether the current user may open the manage UI.
     */
    public function canAccess(UserRef|UserInterface|null $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->accessRoles);
    }

    /**
     * Whether the current user may create shares.
     */
    public function canCreate(UserRef|UserInterface|null $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->createRoles);
    }

    /**
     * Whether the current user may list shares.
     */
    public function canList(UserRef|UserInterface|null $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->listRoles);
    }

    /**
     * Whether the current user may revoke shares.
     */
    public function canRevoke(UserRef|UserInterface|null $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->revokeRoles);
    }

    private function isAdmin(): bool
    {
        foreach ($this->adminRoles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $roles
     */
    private function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
