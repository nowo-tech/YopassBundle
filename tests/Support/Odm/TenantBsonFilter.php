<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Odm;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Query\Filter\BsonFilter;
use Nowo\YopassBundle\Document\SecureShareDocument;

/**
 * A tenant filter keeping its tenant in a filter PARAMETER (`setParameter('tenant', ...)`): shares of other creators
 * are filtered out (audit XC-M1 scoping tests).
 */
final class TenantBsonFilter extends BsonFilter
{
    public function addFilterCriteria(ClassMetadata $class): array
    {
        if ($class->getName() !== SecureShareDocument::class) {
            return [];
        }

        return ['creator' => $this->getParameter('tenant')];
    }
}
