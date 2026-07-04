<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Stub;

use Symfony\Component\Security\Core\User\UserInterface;

final class TestUser implements UserInterface
{
    public function __construct(
        private readonly string $id = 'user-1',
        private readonly string $email = 'demo@example.com',
        /** @var list<string> */
        private readonly array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }
}
