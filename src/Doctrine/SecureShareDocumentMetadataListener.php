<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\ODM\MongoDB\Event\LoadClassMetadataEventArgs;
use Nowo\YopassBundle\Document\SecureShareDocument;

/**
 * Applies configurable collection name and user document mapping to SecureShareDocument.
 */
final class SecureShareDocumentMetadataListener
{
    public function __construct(
        private readonly string $collectionName,
        private readonly string $userClass,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if ($metadata->getName() !== SecureShareDocument::class) {
            return;
        }

        $metadata->setCollection($this->collectionName);

        if (isset($metadata->associationMappings['creator'])) {
            $mapping                                  = $metadata->associationMappings['creator'];
            $mapping['targetDocument']                = $this->userClass;
            $metadata->associationMappings['creator'] = $mapping; // @phpstan-ignore assign.propertyType
        }
    }
}
