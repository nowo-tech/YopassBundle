<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Odm;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Query\Filter\BsonFilter;
use Nowo\YopassBundle\Document\SecureShareDocument;

/**
 * A tenant filter keeping its tenant in a PROPERTY set by the application (a setter, since ODM filters cannot use DI),
 * not in a filter parameter (audit XC-M1: a copied filter would lose it and run unscoped).
 */
final class PropertyTenantBsonFilter extends BsonFilter
{
    private ?string $tenant = null;

    public function setTenant(string $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function addFilterCriteria(ClassMetadata $class): array
    {
        if ($class->getName() !== SecureShareDocument::class) {
            return [];
        }

        // No tenant yet: nothing matches (fail closed).
        return ['creator' => $this->tenant ?? ['$in' => []]];
    }
}
