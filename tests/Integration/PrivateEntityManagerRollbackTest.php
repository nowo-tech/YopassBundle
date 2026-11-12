<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Doctrine\YopassSavepoint;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.20.0: the private entity manager after a rollback on its connection (doctrine/dbal 4 middleware) and the bundle's
 * bulk DQL writes inside a transaction the application opened (savepoints, so a failing bundle statement never aborts
 * the application's transaction).
 */
final class PrivateEntityManagerRollbackTest extends TestCase
{
    private EntityManager $host;

    private YopassEntityManager $own;

    private DoctrineOrmShareRepository $shares;

    private DoctrineCreatorReferenceResolver $creators;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            self::markTestSkipped('The rollback middleware needs doctrine/dbal 4.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-rollback-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(fn (): EntityManager => $this->host);
        $this->own = new YopassEntityManager($registry, 'default');

        $dbal       = (new DbalConfiguration())->setMiddlewares([new PrivateEntityManagerMiddleware($this->own)]);
        $this->host = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $dbal), $config, $events);
        (new SchemaTool($this->host))->createSchema($this->host->getMetadataFactory()->getAllMetadata());
        $this->host->persist(new GuardUser(7, 'alice'));
        $this->host->flush();
        $this->host->clear();

        $this->creators = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'default', new CreatorClassPolicy(GuardUser::class, [SecurityUserDto::class]), CreatorMapping::RELATION, $this->own);
        $this->shares   = new DoctrineOrmShareRepository($this->host, null, $registry, 'default', $this->creators, $this->own);
    }

    public function testAnApplicationRollbackReplacesThePrivateManager(): void
    {
        $connection = $this->host->getConnection();
        $connection->beginTransaction();
        $share  = $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data());
        $before = $this->own->get();
        self::assertTrue($before->contains($share));
        $connection->rollBack();

        self::assertTrue($this->own->isStale());
        $after = $this->own->get();
        self::assertNotSame($before, $after, 'The identity map that describes rolled-back rows is replaced');
        self::assertFalse($this->own->isStale());
        self::assertNull($this->shares->find($share->getId()));

        // A second identical share is written again (not lost as "no change").
        $again = $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares WHERE id = ?', [$again->getId()]));
    }

    public function testASavepointRollbackMarksTheManagerStale(): void
    {
        $connection = $this->host->getConnection();
        $this->own->get();
        $connection->beginTransaction();
        $connection->createSavepoint('host_sp');
        $connection->rollbackSavepoint('host_sp');
        self::assertTrue($this->own->isStale());
        $connection->commit();
    }

    public function testAFailedCommitMarksTheManagerStale(): void
    {
        $this->own->get();
        $connection = $this->host->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->beginTransaction();
        // A deferred foreign key violation makes the COMMIT fail.
        $connection->executeStatement('CREATE TEMP TABLE parent (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TEMP TABLE child (parent_id INTEGER REFERENCES parent(id) DEFERRABLE INITIALLY DEFERRED)');
        $connection->executeStatement('INSERT INTO child (parent_id) VALUES (1)');
        try {
            $connection->commit();
            self::fail('Expected the deferred foreign key to fail the commit');
        } catch (Throwable) {
            // The commit failed.
        }

        self::assertTrue($this->own->isStale());
    }

    public function testBulkWritesInsideAnApplicationTransactionUseASavepoint(): void
    {
        $share      = $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data());
        $connection = $this->host->getConnection();

        $connection->beginTransaction();
        $this->host->persist(new GuardUser(9, 'carol'));
        $this->host->flush();
        self::assertSame('ok', (new ShareRetriever($this->shares))->consume($share->getId())['status']);
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'The savepoint was released');
        $connection->commit();

        self::assertSame(0, (int) $connection->fetchOne('SELECT reads_left FROM yopass_secure_shares WHERE id = ?', [$share->getId()]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM guard_users WHERE id = 9'));
    }

    public function testAFailingBundleWriteRollsBackToItsSavepointOnly(): void
    {
        $connection = $this->host->getConnection();
        $connection->beginTransaction();
        $connection->insert('guard_users', ['id' => 9, 'name' => 'carol']);
        try {
            YopassSavepoint::write($connection, static function () use ($connection): never {
                $connection->insert('guard_users', ['id' => 10, 'name' => 'dave']);

                throw new RuntimeException('bundle write failed');
            });
            self::fail('Expected the bundle failure');
        } catch (RuntimeException $exception) {
            self::assertSame('bundle write failed', $exception->getMessage());
        }
        self::assertTrue($connection->isTransactionActive(), 'The application transaction is still usable');
        $connection->commit();

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM guard_users WHERE id = 9'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM guard_users WHERE id = 10'));
        self::assertSame(3, YopassSavepoint::write($connection, static fn (): int => 3), 'Outside a transaction it just runs');
    }

    /**
     * Audit NX: when rolling back to the savepoint fails too, the caller still gets the write's own exception.
     */
    public function testAFailingSavepointRollbackKeepsTheOriginalException(): void
    {
        $connection = $this->host->getConnection();
        $connection->beginTransaction();
        try {
            YopassSavepoint::write($connection, static function () use ($connection): never {
                // The savepoint disappears with the inner transaction rollback, so rolling back to it fails as well.
                $connection->executeStatement('ROLLBACK');

                throw new RuntimeException('bundle write failed');
            });
            self::fail('Expected the bundle failure');
        } catch (RuntimeException $exception) {
            self::assertSame('bundle write failed', $exception->getMessage());
        } finally {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // The test broke the transaction on purpose.
            }
        }
    }

    private function creator(): ShareCreator
    {
        return new ShareCreator($this->shares, DefaultShareOptions::get()['expiration_options'], null, null, $this->creators);
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = 1;

        return $data;
    }
}
