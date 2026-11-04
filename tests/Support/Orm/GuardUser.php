<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Orm;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host user entity for the creator-subject guard regression test (EL-L1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'guard_users')]
class GuardUser implements UserInterface
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
        #[ORM\Column(type: Types::STRING, length: 64)]
        public string $name,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->name;
    }
}
