<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Odm;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host user document for the MongoDB repository round-trip test.
 */
#[ODM\Document(collection: 'yopass_test_users')]
class MongoUser implements UserInterface
{
    public function __construct(
        #[ODM\Id(type: 'string', strategy: 'NONE')]
        private string $id,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
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
}
