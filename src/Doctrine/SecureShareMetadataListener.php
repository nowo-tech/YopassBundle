<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;

use function array_replace_recursive;
use function ltrim;
use function sprintf;

/**
 * Applies configurable table name and user entity mapping to SecureShare.
 */
final readonly class SecureShareMetadataListener
{
    public function __construct(
        private string $tableName,
        private string $userClass,
        private ?string $accessLogTableName = null,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if ($metadata->getName() === ShareAccessLog::class) {
            if ($this->accessLogTableName !== null) {
                $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->accessLogTableName]));
            }

            return;
        }

        if ($metadata->getName() !== SecureShare::class) {
            return;
        }

        $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->tableName]));

        if (!isset($metadata->associationMappings['creator'])) {
            return;
        }

        $this->remapCreatorAssociation($metadata);
    }

    private function remapCreatorAssociation(ClassMetadata $metadata): void
    {
        $mapping      = $metadata->associationMappings['creator'];
        $targetEntity = ltrim($this->userClass, '\\');

        if ($mapping instanceof AssociationMapping) {
            $newMapping = array_replace_recursive(
                $mapping->toArray(),
                ['targetEntity' => $targetEntity],
            );
            $newMapping['fieldName'] = $mapping->fieldName;

            unset($metadata->associationMappings['creator']);

            match ($mapping->type()) {
                ClassMetadata::MANY_TO_ONE => $metadata->mapManyToOne($newMapping),
                ClassMetadata::ONE_TO_ONE  => $metadata->mapOneToOne($newMapping),
                default                    => throw new LogicException(sprintf('Unsupported association type for creator: %d', $mapping->type())),
            };

            return;
        }

        /** @var array<string, mixed> $legacyMapping */
        $legacyMapping                            = $mapping;
        $legacyMapping['targetEntity']            = $targetEntity;
        $metadata->associationMappings['creator'] = $legacyMapping;
    }
}
