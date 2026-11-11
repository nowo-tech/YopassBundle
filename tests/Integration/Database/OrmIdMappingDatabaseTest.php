<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function dirname;
use function getenv;
use function is_string;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * LQ-Y-L1 (1.18.0) on a database server (MySQL, MariaDB, PostgreSQL): with `user_mapping: id` the creator id column is
 * indexed and compares byte for byte (`utf8mb4_bin` on MySQL / MariaDB), so `abc` never matches `AbC`; the repository
 * post-filters keep lists, counts and deletions exact even when a host's column uses a case-insensitive collation. Set
 * YOPASS_TEST_DATABASE_URL to run (CI job "Id mapping on database servers").
 */
#[Group('database')]
final class OrmIdMappingDatabaseTest extends TestCase
{
    private EntityManager $em;

    private DoctrineOrmShareRepository $repository;

    private ShareCreator $creator;

    protected function setUp(): void
    {
        $url = getenv('YOPASS_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set YOPASS_TEST_DATABASE_URL to run the id-mapping tests on a database server.');
        }

        $paths  = [dirname(__DIR__, 3) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-mysql-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new SecureShareMetadataListener('yopass_secure_shares', SecurityUserDto::class, 'yopass_share_access_logs', CreatorMapping::ID));
        $connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url), $config);
        $this->em   = new EntityManager($connection, $config, $events);
        $tool       = new SchemaTool($this->em);
        $metadata   = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $resolver         = new DoctrineCreatorReferenceResolver(SecurityUserDto::class, null, null, new CreatorClassPolicy(SecurityUserDto::class), CreatorMapping::ID);
        $this->repository = new DoctrineOrmShareRepository($this->em, null, null, null, $resolver);
        $this->creator    = new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, null, $resolver);
    }

    public function testCreatorIdColumnIsBinaryAndIndexed(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertSame('utf8mb4_bin', $connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'yopass_secure_shares' AND COLUMN_NAME = 'creator_id'"));
        }
        $leading = array_map(static fn (Index $index): string => array_values($index->getColumns())[0] ?? '', $connection->createSchemaManager()->listTableIndexes('yopass_secure_shares'));
        self::assertContains('creator_id', $leading);

        $this->seed();
        $this->assertExact();
    }

    public function testPostFiltersKeepEveryPathExactUnderACaseInsensitiveCollation(): void
    {
        $this->foldCase($this->em->getConnection());
        $this->seed();
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM yopass_secure_shares WHERE creator_id = 'abc'"), 'The collation folds case.');

        $this->assertExact();
    }

    /**
     * Gives `creator_id` a case-insensitive collation: `utf8mb4_unicode_ci` on MySQL / MariaDB, a nondeterministic ICU
     * collation on PostgreSQL.
     */
    private function foldCase(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('ALTER TABLE yopass_secure_shares MODIFY creator_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');

            return;
        }

        self::assertInstanceOf(PostgreSQLPlatform::class, $platform, 'Run this group on MySQL, MariaDB or PostgreSQL.');
        $connection->executeStatement("CREATE COLLATION IF NOT EXISTS yopass_test_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false)");
        $connection->executeStatement('ALTER TABLE yopass_secure_shares ALTER COLUMN creator_id TYPE VARCHAR(128) COLLATE yopass_test_ci');
    }

    private function seed(): void
    {
        $this->creator->create(new SecurityUserDto('AbC'), $this->data());
        $this->creator->create(new SecurityUserDto('abc'), $this->data());
        $this->em->clear();
    }

    private function assertExact(): void
    {
        $lower = new SecurityUserDto('abc');

        self::assertSame(['abc'], array_map(static fn (SecureShare $s): ?string => $s->getCreatorId(), $this->repository->findByCreator($lower)));
        self::assertSame(['abc'], array_map(static fn (SecureShare $s): ?string => $s->getCreatorId(), $this->repository->findByCreatorPaginated($lower, 10, 0)));
        self::assertSame(1, $this->repository->countByCreator($lower));
        self::assertSame(1, $this->repository->countByCreatorId('AbC'));
        self::assertSame(0, $this->repository->removeByCreatorOlderThan($lower, new DateTimeImmutable('-1 day')));
        self::assertSame(1, $this->repository->removeAllByCreatorId('abc'));
        self::assertSame(1, $this->repository->countByCreatorId('AbC'), 'The other user keeps their share.');
        self::assertSame(0, $this->repository->countByCreatorId('abc'));
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = 1;

        return $data;
    }
}
