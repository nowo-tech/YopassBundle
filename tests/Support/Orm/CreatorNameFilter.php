<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Orm;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Host SQL filter used to check that the bundle's own entity manager applies the application's enabled filters.
 */
final class CreatorNameFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return $targetEntity->getName() === GuardUser::class ? $targetTableAlias . '.name = ' . $this->getParameter('name') : '';
    }
}
