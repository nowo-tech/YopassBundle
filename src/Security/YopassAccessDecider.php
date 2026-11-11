<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function trigger_error;
use function trim;

use const E_USER_DEPRECATED;

/**
 * HTTP-edge adapter between the manage controller and the configured access checker (1.19.0).
 *
 * A {@see YopassAccessPolicyInterface} receives the {@see UserRef} of the signed-in user (null when nobody is signed
 * in). A checker implementing only the deprecated {@see YopassAccessCheckerInterface} is called exactly as before 1.19:
 * with the user the controller passed, or with no argument. An unknown feature is denied.
 *
 * @internal
 */
final readonly class YopassAccessDecider
{
    public const FEATURES = ['access', 'create', 'list', 'revoke'];

    public function __construct(
        private YopassAccessPolicyInterface|YopassAccessCheckerInterface $checker,
    ) {
        if (!$checker instanceof YopassAccessPolicyInterface) {
            @trigger_error('Since nowo-tech/yopass-bundle 1.19.0: implementing only "YopassAccessCheckerInterface" for "nowo_yopass.security.access_checker" is deprecated, implement "YopassAccessPolicyInterface" instead.', E_USER_DEPRECATED);
        }
    }

    /**
     * Whether the configured checker is a {@see YopassAccessPolicyInterface} (it needs the signed-in user).
     */
    public function usesPolicy(): bool
    {
        return $this->checker instanceof YopassAccessPolicyInterface;
    }

    /**
     * Whether `$feature` (access, create, list, revoke) is granted.
     *
     * @param UserInterface|null $argument the user a legacy checker receives (null: called without one, as before 1.19)
     * @param UserInterface|null $current the signed-in user, turned into the {@see UserRef} of a policy
     */
    public function allows(string $feature, ?UserInterface $argument = null, ?UserInterface $current = null): bool
    {
        $checker = $this->checker;
        if ($checker instanceof YopassAccessPolicyInterface) {
            $securityUser = $argument ?? $current;
            $user         = self::userRef($securityUser);
            if ($securityUser instanceof UserInterface && !$user instanceof UserRef) {
                // A signed-in user without a usable id or identifier is never treated as anonymous: deny.
                return false;
            }

            return match ($feature) {
                'access' => $checker->canAccess($user),
                'create' => $checker->canCreate($user),
                'list'   => $checker->canList($user),
                'revoke' => $checker->canRevoke($user),
                default  => false,
            };
        }

        return match ($feature) {
            'access' => $checker->canAccess($argument),
            'create' => $checker->canCreate($argument),
            'list'   => $checker->canList($argument),
            'revoke' => $checker->canRevoke($argument),
            default  => false,
        };
    }

    /**
     * The {@see UserRef} of a security user: `getId()` as a string (else the identifier, with `idIsIdentifier`), the
     * identifier, the roles.
     * Null without a user, or when the user has neither a non-empty id nor a non-empty identifier.
     */
    public static function userRef(?UserInterface $user): ?UserRef
    {
        if (!$user instanceof UserInterface) {
            return null;
        }
        $identifier = $user->getUserIdentifier();
        $id         = UserIdResolver::getId($user);
        if ($id !== null && trim($id) !== '') {
            return new UserRef($id, $identifier, $user->getRoles());
        }

        // No usable getId(): the identifier stands in for the id, flagged so a policy can tell (audit MO-Y-I4).
        return trim($identifier) === '' ? null : new UserRef($identifier, $identifier, $user->getRoles(), true);
    }
}
