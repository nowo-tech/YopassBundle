<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
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
    /**
     * Creates the listener with the configured table names and user entity class.
     */
    public function __construct(
        private string $tableName,
        private string $userClass,
        private ?string $accessLogTableName = null,
    ) {
    }

    /**
     * Applies the configured table names and the creator relation target to the bundle entities.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if ($metadata->getName() === ShareAccessLog::class) {
            $table = $this->accessLogTableName ?? $metadata->getTableName();
            // 1.16: lookups of the trail of a deleted share (share_id NULL) go through share_ref.
            $indexes                            = $metadata->table['indexes'] ?? [];
            $indexes[$table . '_share_ref_idx'] = ['columns' => ['share_ref']];
            $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $table, 'indexes' => $indexes]));

            return;
        }

        if ($metadata->getName() !== SecureShare::class) {
            return;
        }

        // Indexes for the retention purge (created_at) and expiry lookups (expires_at), named after the configured
        // table so two prefixes never collide.
        $indexes                                       = $metadata->table['indexes'] ?? [];
        $indexes[$this->tableName . '_created_at_idx'] = ['columns' => ['created_at']];
        $indexes[$this->tableName . '_expires_at_idx'] = ['columns' => ['expires_at']];
        $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->tableName, 'indexes' => $indexes]));

        if (!isset($metadata->associationMappings['creator'])) {
            return;
        }

        /** @var ClassMetadata<SecureShare> $secureShareMetadata */
        $secureShareMetadata = $metadata;
        $this->remapCreatorAssociation($secureShareMetadata);
    }

    /**
     * @param ClassMetadata<SecureShare> $metadata
     */
    private function remapCreatorAssociation(ClassMetadata $metadata): void
    {
        $mapping      = $metadata->associationMappings['creator'];
        $targetEntity = ltrim($this->userClass, '\\');
        $newMapping   = array_replace_recursive(
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
    }
}
