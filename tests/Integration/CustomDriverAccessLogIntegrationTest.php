<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Support\Orm\GuardTenant;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 2.0 (REQ-ARCH-001 rule 6): with `database.driver: custom` on a relational platform, the shares come from the host's
 * repository and the bundle's access log is written through the bundle's own entity manager ({@see YopassEntityManager})
 * on the connection of `database.entity_manager`, the same table as before. The application's entity manager is never
 * flushed: a change it left pending stays pending.
 */
final class CustomDriverAccessLogIntegrationTest extends TestCase
{
    private EntityManager $host;

    private ManagerRegistry $registry;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-custom-log-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->host = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->host))->createSchema($this->host->getMetadataFactory()->getAllMetadata());
        $this->host->persist(new GuardUser(7, 'alice'));
        $this->host->flush();
        $this->host->clear();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->host);
        $this->registry = $registry;
    }

    public function testTheAccessLogGoesThroughTheBundlesManagerAndTheApplicationsIsNeverFlushed(): void
    {
        // The host's custom repository: here an ORM repository working on the application's own manager.
        $hostShares = new DoctrineOrmShareRepository($this->host);
        $alice      = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $hostShares->persist($this->share('00000000-0000-4000-8000-0000000000c1', $alice));
        $hostShares->flush();

        // The application leaves an update (GuardUser) and an insert (GuardTenant) pending on its own manager.
        $alice->name = 'alice-renamed';
        $this->host->persist(new GuardTenant(1));

        $own    = new YopassEntityManager($this->registry, 'default');
        $logger = new ShareAccessLogger(new DoctrineOrmShareAccessLogRepository($this->host, null, null, $own), true);
        $result = (new ShareRetriever($hostShares))->consume('00000000-0000-4000-8000-0000000000c1', static function (SecureShare $share) use ($logger): void {
            $logger->logSuccessfulConsume($share, Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.9']));
        });
        self::assertSame('ok', $result['status']);

        // The row is in the shared table, related to the share, written by the bundle's manager only.
        $rows = $this->host->getConnection()->fetchAllAssociative('SELECT share_id, read_number, ip_address FROM yopass_share_access_logs');
        self::assertSame([['share_id' => '00000000-0000-4000-8000-0000000000c1', 'read_number' => 1, 'ip_address' => '203.0.113.9']], array_map(static fn (array $row): array => ['share_id' => $row['share_id'], 'read_number' => (int) $row['read_number'], 'ip_address' => $row['ip_address']], $rows));
        foreach ($this->host->getUnitOfWork()->getIdentityMap() as $class => $entities) {
            self::assertNotSame(ShareAccessLog::class, $class, 'the log row never enters the application\'s identity map');
        }

        // The application's pending changes were not written by the access log.
        $this->host->getUnitOfWork()->computeChangeSets();
        self::assertSame(['name' => ['alice', 'alice-renamed']], $this->host->getUnitOfWork()->getEntityChangeSet($alice));
        self::assertSame(0, (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM guard_tenants'));
        self::assertSame('alice', $this->host->getConnection()->fetchOne('SELECT name FROM guard_users WHERE id = 7'));

        // A share the bundle's manager loaded itself is related as is (the ORM driver's path).
        $logs = new DoctrineOrmShareAccessLogRepository($this->host, null, null, $own);
        $own->get()->clear();
        $loaded = $own->get()->find(SecureShare::class, '00000000-0000-4000-8000-0000000000c1');
        self::assertInstanceOf(SecureShare::class, $loaded);
        $logs->persist(new ShareAccessLog('00000000-0000-4000-8000-00000000a0c2', $loaded, 2));
        $logs->flush();
        self::assertCount(2, $logs->findByShareId('00000000-0000-4000-8000-0000000000c1'));
        self::assertCount(2, $logs->findByShare($hostShares->find('00000000-0000-4000-8000-0000000000c1') ?? $loaded));
    }

    /**
     * A failing access log insert closes only the bundle's manager; the application's manager stays open with its
     * pending changes. The custom driver gets no bundle write unit (docs/CONCURRENCY.md): the host repository stored the
     * read on its own before the access log ran, exactly as in 1.x.
     */
    public function testAFailingAccessLogInsertClosesOnlyTheBundlesManager(): void
    {
        $hostShares = new DoctrineOrmShareRepository($this->host);
        $alice      = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $share = $this->share('00000000-0000-4000-8000-0000000000c3', $alice);
        $hostShares->persist($share);
        $hostShares->flush();
        $alice->name = 'alice-pending';

        $own  = new YopassEntityManager($this->registry, 'default');
        $logs = new DoctrineOrmShareAccessLogRepository($this->host, null, null, $own);
        $logs->persist(new ShareAccessLog('00000000-0000-4000-8000-00000000a0c3', $share, 1));
        $logs->flush();

        $own->get()->clear();
        $logs->persist(new ShareAccessLog('00000000-0000-4000-8000-00000000a0c3', $share, 2));
        try {
            $logs->flush();
            self::fail('A duplicate access log id must fail.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertTrue($this->host->isOpen(), 'the application\'s manager is never closed');
        $this->host->getUnitOfWork()->computeChangeSets();
        self::assertSame(['name' => ['alice', 'alice-pending']], $this->host->getUnitOfWork()->getEntityChangeSet($alice));
        self::assertSame(1, (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM yopass_share_access_logs'));
        // The bundle's manager is replaced at its next use.
        self::assertTrue($own->get()->isOpen());
    }

    private function share(string $id, object $creator): SecureShare
    {
        $share = new SecureShare($id, $creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 day'))->setMaxReads(3);

        return $share;
    }
}
