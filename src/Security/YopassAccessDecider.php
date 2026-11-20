<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function trim;

/**
 * HTTP-edge adapter between the manage controller and the configured access checker (1.19.0).
 *
 * The {@see YopassAccessPolicyInterface} receives the {@see UserRef} of the signed-in user (null when nobody is signed
 * in). An unknown feature is denied.
 *
 * @internal
 */
final readonly class YopassAccessDecider
{
    public const FEATURES = ['access', 'create', 'list', 'revoke'];

    public function __construct(
        private YopassAccessPolicyInterface $checker,
    ) {
    }

    /**
     * Whether `$feature` (access, create, list, revoke) is granted.
     *
     * @param UserInterface|null $argument the user the controller passed (wins over `$current`)
     * @param UserInterface|null $current the signed-in user
     */
    public function allows(string $feature, ?UserInterface $argument = null, ?UserInterface $current = null): bool
    {
        $securityUser = $argument ?? $current;
        $user         = self::userRef($securityUser);
        if ($securityUser instanceof UserInterface && !$user instanceof UserRef) {
            // A signed-in user without a usable id or identifier is never treated as anonymous: deny.
            return false;
        }

        return match ($feature) {
            'access' => $this->checker->canAccess($user),
            'create' => $this->checker->canCreate($user),
            'list'   => $this->checker->canList($user),
            'revoke' => $this->checker->canRevoke($user),
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
