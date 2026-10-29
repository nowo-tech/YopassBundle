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
    /**
     * Whether the user may open the manage UI.
     */
    public function canAccess(?UserInterface $user = null): bool;

    /**
     * Whether the user may create shares.
     */
    public function canCreate(?UserInterface $user = null): bool;

    /**
     * Whether the user may list shares.
     */
    public function canList(?UserInterface $user = null): bool;

    /**
     * Whether the user may revoke shares.
     */
    public function canRevoke(?UserInterface $user = null): bool;
}
