<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
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
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
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
        // REQ-SEC-006 (1.20.4): binary NO PAD; the PAD SPACE utf8mb4_bin only on MySQL < 8.0.17, with a warning
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $level . ' ' . strtr((string) $message, ['{version}' => (string) ($context['version'] ?? '')]);
            }
        };
        $cases = [
            [new MySQL80Platform(), ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], []],
            [new MySQL84Platform(), ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], ['serverVersion' => '8.4.3']],
            [new MariaDBPlatform(), ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_nopad_bin'], ['serverVersion' => '11.4.0-MariaDB']],
            [new MySQL80Platform(), ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], ['primary' => ['serverVersion' => '8.0']]],
            [new MySQLPlatform(), ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], ['serverVersion' => '5.7.44']],
            [new SQLServerPlatform(), ['collation' => 'Latin1_General_100_BIN2'], []],
            [new PostgreSQLPlatform(), null, []],
            [null, null, []],
        ];
        foreach ($cases as [$platform, $expected, $params]) {
            $metadata        = new ClassMetadata(SecureShare::class);
            $metadata->table = ['name' => 'yopass_secure_shares'];
            $metadata->mapManyToOne(['fieldName' => 'creator', 'targetEntity' => UserInterface::class, 'joinColumns' => [['name' => 'creator_id', 'referencedColumnName' => 'id']]]);

            $connection = $this->createStub(Connection::class);
            if ($platform instanceof AbstractPlatform) {
                $connection->method('getDatabasePlatform')->willReturn($platform);
            } else {
                $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('no server'));
            }
            $connection->method('getParams')->willReturn($params);
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($connection);

            (new SecureShareMetadataListener('yopass_secure_shares', 'App\\Entity\\User', null, CreatorMapping::ID, $logger))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));

            self::assertSame($expected, $metadata->fieldMappings['creator']->options);
            self::assertSame(['columns' => ['creator_id']], $metadata->table['indexes']['yopass_secure_shares_creator_idx']);
        }
        self::assertCount(2, $logger->records);
        self::assertStringStartsWith('warning NowoYopassBundle: the creator id column (user_mapping: id) gets the PAD SPACE collation utf8mb4_bin (MySQL server version "8.0")', $logger->records[0]);
        self::assertStringContainsString('"5.7.44"', $logger->records[1]);
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
