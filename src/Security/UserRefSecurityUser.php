<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Symfony\Component\Security\Core\User\UserInterface;

use function assert;

/**
 * Stand-in security user for an id-based access check (2.1.0): {@see ShareAccessCheckEvent}
 * still returns a `UserInterface` from its deprecated `getUser()`. It carries the {@see UserRef} id, identifier and
 * roles and nothing else; it is not the application's user class, so a listener that tests `instanceof` its own user
 * class does not take it for one. Listeners should use `getUserId()` / `getUserRef()`.
 *
 * @internal
 */
final readonly class UserRefSecurityUser implements UserInterface
{
    public function __construct(private UserRef $user)
    {
    }

    /**
     * The referenced user's id (null when the reference only holds an identifier).
     */
    public function getId(): ?string
    {
        return $this->user->idIsIdentifier ? null : $this->user->id;
    }

    public function getRoles(): array
    {
        return $this->user->roles;
    }

    public function getUserIdentifier(): string
    {
        $identifier = $this->user->identifier !== '' ? $this->user->identifier : $this->user->id;
        // UserRef refuses an empty id.
        assert($identifier !== '');

        return $identifier;
    }

    /**
     * Nothing to erase (no credentials are held).
     */
    public function eraseCredentials(): void
    {
    }
}
