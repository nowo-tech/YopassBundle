<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Event\LoadClassMetadataEventArgs as OdmLoadClassMetadataEventArgs;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata as OdmClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Entity\SecureShare;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.18.0 id mapping: the creator relation / reference becomes a plain string column / field.
 */
final class CreatorIdMappingListenersTest extends TestCase
{
    public function testOrmCreatorBecomesAStringColumnWithoutRelation(): void
    {
        foreach ([[['name' => 'owner_ref', 'referencedColumnName' => 'id']], []] as $joinColumns) {
            $metadata        = new ClassMetadata(SecureShare::class);
            $metadata->table = ['name' => 'yopass_secure_shares'];
            $metadata->mapManyToOne(['fieldName' => 'creator', 'targetEntity' => UserInterface::class] + ($joinColumns !== [] ? ['joinColumns' => $joinColumns] : []));
            if ($joinColumns === []) {
                $metadata->associationMappings['creator']->joinColumns = [];
            }

            $args = $this->createMock(LoadClassMetadataEventArgs::class);
            $args->method('getClassMetadata')->willReturn($metadata);
            (new SecureShareMetadataListener('yopass_secure_shares', 'App\\Entity\\User', null, CreatorMapping::ID))->loadClassMetadata($args);

            self::assertArrayNotHasKey('creator', $metadata->associationMappings);
            self::assertSame('string', $metadata->getTypeOfField('creator'));
            self::assertSame($joinColumns !== [] ? 'owner_ref' : 'creator_id', $metadata->getColumnName('creator'));
            self::assertSame(CreatorMapping::ID_LENGTH, $metadata->fieldMappings['creator']->length);
            self::assertFalse($metadata->isNullable('creator'));
        }
    }

    public function testOrmCreatorColumnGetsABinaryCollationWhereNeeded(): void
    {
        $cases = [
            [new MySQLPlatform(), ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin']],
            [new SQLServerPlatform(), ['collation' => 'Latin1_General_100_BIN2']],
            [new PostgreSQLPlatform(), null],
            [null, null],
        ];
        foreach ($cases as [$platform, $expected]) {
            $metadata        = new ClassMetadata(SecureShare::class);
            $metadata->table = ['name' => 'yopass_secure_shares'];
            $metadata->mapManyToOne(['fieldName' => 'creator', 'targetEntity' => UserInterface::class, 'joinColumns' => [['name' => 'creator_id', 'referencedColumnName' => 'id']]]);

            $connection = $this->createStub(Connection::class);
            if ($platform instanceof AbstractPlatform) {
                $connection->method('getDatabasePlatform')->willReturn($platform);
            } else {
                $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('no server'));
            }
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($connection);

            (new SecureShareMetadataListener('yopass_secure_shares', 'App\\Entity\\User', null, CreatorMapping::ID))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));

            self::assertSame($expected, $metadata->fieldMappings['creator']->options);
            self::assertSame(['columns' => ['creator_id']], $metadata->table['indexes']['yopass_secure_shares_creator_idx']);
        }
    }

    public function testOdmCreatorBecomesAStringField(): void
    {
        $metadata = new OdmClassMetadata(SecureShareDocument::class);
        $metadata->mapOneReference(['fieldName' => 'creator', 'name' => 'creator', 'targetDocument' => UserInterface::class, 'storeAs' => 'id']);

        (new SecureShareDocumentMetadataListener('yopass_secure_shares', 'App\\Entity\\User', CreatorMapping::ID))->loadClassMetadata(
            new OdmLoadClassMetadataEventArgs($metadata, $this->createMock(DocumentManager::class)),
        );

        self::assertSame('yopass_secure_shares', $metadata->collection);
        self::assertArrayNotHasKey('creator', $metadata->associationMappings);
        self::assertSame('string', $metadata->fieldMappings['creator']['type']);
    }
}
