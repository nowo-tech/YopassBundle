<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Ddd;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security user of a hexagonal host: a plain read model (no Doctrine entity) carrying the id of the persisted user.
 */
final readonly class SecurityUserDto implements UserInterface
{
    public function __construct(
        private int|string $id,
        private string $identifier = 'dto',
        /** @var list<string> */
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }
}
