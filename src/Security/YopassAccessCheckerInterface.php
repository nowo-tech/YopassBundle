<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application-specific access rules for Yopass manage routes.
 *
 * Replace this interface in the container to integrate team-based or custom ACL logic.
 */
interface YopassAccessCheckerInterface
{
    public function canAccess(?UserInterface $user = null): bool;

    public function canCreate(?UserInterface $user = null): bool;

    public function canList(?UserInterface $user = null): bool;

    public function canRevoke(?UserInterface $user = null): bool;
}
