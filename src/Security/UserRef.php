<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use InvalidArgumentException;

use function array_values;
use function in_array;
use function trim;

/**
 * Framework-free reference to the signed-in user, given to a {@see YopassAccessPolicyInterface} (1.19.0, REQ-ARCH-001
 * rule 3): the user id (`getId()` as a string, else the security identifier), the security identifier and the roles
 * the security user reports (`getRoles()`, without the role hierarchy).
 */
final readonly class UserRef
{
    /** @var list<string> */
    public array $roles;

    /**
     * @param array<string> $roles
     *
     * @throws InvalidArgumentException when the id is empty
     */
    public function __construct(
        public string $id,
        public string $identifier = '',
        array $roles = [],
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A user reference needs a non-empty id.');
        }
        $this->roles = array_values($roles);
    }

    /**
     * Whether the security user reports this role (no role hierarchy).
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
