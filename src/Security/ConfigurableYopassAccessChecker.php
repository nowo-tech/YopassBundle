<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Role-based default implementation of {@see YopassAccessCheckerInterface}.
 */
final class ConfigurableYopassAccessChecker implements YopassAccessCheckerInterface
{
    /**
     * @param list<string> $adminRoles
     * @param list<string> $accessRoles
     * @param list<string> $createRoles
     * @param list<string> $listRoles
     * @param list<string> $revokeRoles
     */
    public function __construct(
        private readonly Security $security,
        private readonly array $adminRoles,
        private readonly array $accessRoles,
        private readonly array $createRoles,
        private readonly array $listRoles,
        private readonly array $revokeRoles,
    ) {
    }

    public function canAccess(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->accessRoles);
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->createRoles);
    }

    public function canList(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->listRoles);
    }

    public function canRevoke(?UserInterface $user = null): bool
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
