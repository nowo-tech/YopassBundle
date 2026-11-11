<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Throwable;

use function array_replace_recursive;
use function is_array;
use function is_string;
use function ltrim;
use function sprintf;

/**
 * Applies configurable table name and user entity mapping to SecureShare: a relation to `user_class` (default) or,
 * with `nowo_yopass.user_mapping: id` (1.18.0), a string creator id column without any relation to the host.
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
        private string $userMapping = CreatorMapping::RELATION,
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
        if ($this->userMapping === CreatorMapping::ID) {
            $this->mapCreatorIdColumn($secureShareMetadata, $this->binaryCollation($args));

            return;
        }

        $this->remapCreatorAssociation($secureShareMetadata);
    }

    /**
     * Id mapping (1.18.0): replaces the relation to the host user by a string column of the same name holding the
     * creator id (no foreign key, no join to the host users table).
     *
     * The column compares byte for byte (binary collation where the platform defaults to a case-insensitive one,
     * LQ-Y-L1) and is indexed (the foreign key used to provide one).
     *
     * @param ClassMetadata<SecureShare> $metadata
     * @param array<string, string> $columnOptions
     */
    private function mapCreatorIdColumn(ClassMetadata $metadata, array $columnOptions): void
    {
        $mapping    = $metadata->associationMappings['creator']->toArray();
        $joinColumn = is_array($mapping['joinColumns'] ?? null) && is_array($mapping['joinColumns'][0] ?? null) ? $mapping['joinColumns'][0] : [];

        unset($metadata->associationMappings['creator']);

        $column = is_string($joinColumn['name'] ?? null) ? $joinColumn['name'] : 'creator_id';
        $metadata->mapField([
            'fieldName'  => 'creator',
            'columnName' => $column,
            'type'       => 'string',
            'length'     => CreatorMapping::ID_LENGTH,
            'nullable'   => false,
        ] + ($columnOptions !== [] ? ['options' => $columnOptions] : []));

        $indexes                                    = $metadata->table['indexes'] ?? [];
        $indexes[$this->tableName . '_creator_idx'] = ['columns' => [$column]];
        $metadata->table['indexes']                 = $indexes;
    }

    /**
     * Column options that make the creator id compare byte for byte (1.18.0, LQ-Y-L1): MySQL / MariaDB and SQL Server
     * default to case-insensitive collations (`abc` would match `AbC`); PostgreSQL, SQLite and Oracle need nothing.
     *
     * @return array<string, string>
     */
    private function binaryCollation(LoadClassMetadataEventArgs $args): array
    {
        try {
            $platform = $args->getEntityManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable) {
            return [];
        }

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'],
            $platform instanceof SQLServerPlatform     => ['collation' => 'Latin1_General_100_BIN2'],
            default                                    => [],
        };
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
