<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\ODM\MongoDB\Event\LoadClassMetadataEventArgs;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Document\SecureShareDocument;

/**
 * Applies configurable collection name and user document mapping to SecureShareDocument.
 */
final readonly class SecureShareDocumentMetadataListener
{
    /**
     * Creates the listener with the configured collection name and user document class.
     */
    public function __construct(
        private string $collectionName,
        private string $userClass,
        private string $userMapping = CreatorMapping::RELATION,
    ) {
    }

    /**
     * Applies the configured collection name and the creator reference target to the share document.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if ($metadata->getName() !== SecureShareDocument::class) {
            return;
        }

        $metadata->setCollection($this->collectionName);

        if ($this->userMapping === CreatorMapping::ID) {
            // 1.18.0 id mapping: the creator is a plain string field holding the id (no reference to a user document).
            unset($metadata->associationMappings['creator'], $metadata->fieldMappings['creator']);
            $metadata->mapField(['fieldName' => 'creator', 'name' => 'creator', 'type' => 'string']);

            return;
        }

        if (isset($metadata->associationMappings['creator'])) {
            $mapping                                  = $metadata->associationMappings['creator'];
            $mapping['targetDocument']                = $this->userClass;
            $metadata->associationMappings['creator'] = $mapping; // @phpstan-ignore assign.propertyType
        }

        // The hydrator and the persister read the field mapping, not the association mapping: remap both, or
        // loading a share fails on the "UserInterface does not exist" metadata lookup.
        if (isset($metadata->fieldMappings['creator'])) {
            $field                              = $metadata->fieldMappings['creator'];
            $field['targetDocument']            = $this->userClass;
            $metadata->fieldMappings['creator'] = $field; // @phpstan-ignore assign.propertyType
        }
    }
}
