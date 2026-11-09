<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Orm;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A second identity domain (back-office admins) whose ids overlap with GuardUser ids (audit JX-Y1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'guard_admins')]
class GuardAdmin implements UserInterface
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'admin-' . $this->id;
    }
}
