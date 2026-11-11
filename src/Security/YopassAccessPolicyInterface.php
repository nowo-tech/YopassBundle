<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

/**
 * Application-specific access rules for the Yopass manage routes, framework-free (1.19.0, REQ-ARCH-001 rule 3): the
 * signed-in user arrives as a {@see UserRef} (null when nobody is signed in). Set your service as
 * `nowo_yopass.security.access_checker`.
 *
 * Replaces {@see YopassAccessCheckerInterface} (deprecated since 1.19.0, removed in 2.0). A service implementing both is
 * called through this interface. Deny by default: return true only for what you grant.
 */
interface YopassAccessPolicyInterface
{
    /**
     * Whether the user may open the manage UI.
     */
    public function canAccess(?UserRef $user = null): bool;

    /**
     * Whether the user may create shares.
     */
    public function canCreate(?UserRef $user = null): bool;

    /**
     * Whether the user may list shares.
     */
    public function canList(?UserRef $user = null): bool;

    /**
     * Whether the user may revoke shares.
     */
    public function canRevoke(?UserRef $user = null): bool;
}
