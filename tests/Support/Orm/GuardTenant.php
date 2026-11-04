<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Orm;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Host tenant entity whose id collides with a user id (EL-L1 PoC: tenant 7 vs user 7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'guard_tenants')]
class GuardTenant
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
    ) {
    }
}
