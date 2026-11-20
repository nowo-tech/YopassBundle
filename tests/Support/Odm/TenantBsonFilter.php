<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Odm;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Query\Filter\BsonFilter;

/**
 * A BSON filter the application enables per request (a tenant): the private document manager mirrors it (1.22.0).
 */
final class TenantBsonFilter extends BsonFilter
{
    public function addFilterCriteria(ClassMetadata $class): array
    {
        return [];
    }
}
