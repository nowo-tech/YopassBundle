<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use InvalidArgumentException;
use LogicException;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Tests\Support\Orm\CreatorNameFilter;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use Nowo\YopassBundle\Tests\Support\SecretCapturingLogger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The bundle's own entity manager (1.19.0): built on the source manager, reused, replaced when closed or when the
 * source connection changed, emptied at every boundary (REQ-RUNTIME-002 rule 4), never the application's manager.
 */
final class YopassEntityManagerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    public function testItIsAPrivateManagerOnTheSourceConnection(): void
    {
        $source = $this->source();
        $own    = new YopassEntityManager($this->registry($source));

        $em = $own->get();
        self::assertNotSame($source, $em);
        self::assertSame($source->getConnection(), $em->getConnection());
        self::assertNotSame($source->getConfiguration(), $em->getConfiguration(), 'A copy with its own repository factory');
        self::assertSame($source->getConfiguration()->getMetadataDriverImpl(), $em->getConfiguration()->getMetadataDriverImpl());
        self::assertSame($source->getConfiguration()->getMetadataCache(), $em->getConfiguration()->getMetadataCache());
        self::assertNotSame($source->getConfiguration()->getRepositoryFactory(), $em->getConfiguration()->getRepositoryFactory());
        self::assertSame($source->getEventManager(), $em->getEventManager());
        self::assertSame($em, $own->get(), 'Reused while open');
        self::assertSame($source, $own->source());
    }

    public function testAClosedManagerOrANewConnectionIsReplaced(): void
    {
        $sources  = [$this->source(), $this->source()];
        $current  = 0;
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static function () use (&$sources, &$current): ObjectManager {
            return $sources[$current];
        });
        $own = new YopassEntityManager($registry, 'default');

        $first = $own->get();
        $first->close();
        $second = $own->get();
        self::assertNotSame($first, $second);
        self::assertTrue($second->isOpen());

        $current = 1;
        $third   = $own->get();
        self::assertNotSame($second, $third);
        self::assertSame($sources[1]->getConnection(), $third->getConnection());
    }

    public function testBoundariesEmptyTheIdentityMap(): void
    {
        $own = new YopassEntityManager($this->registry($this->source()));
        $own->clear();
        $own->reset();

        $em     = $own->get();
        $kernel = $this->createStub(HttpKernelInterface::class);

        $share = $em->getReference(ShareAccessLog::class, 'log-1');
        self::assertIsObject($share);
        $own->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertTrue($em->getUnitOfWork()->isInIdentityMap($share), 'A sub-request keeps it');

        $own->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($em->getUnitOfWork()->isInIdentityMap($share), 'The main request empties it');

        $em->getReference(ShareAccessLog::class, 'log-2');
        $own->clear();
        self::assertSame([], $em->getUnitOfWork()->getIdentityMap(), 'terminate / console / Messenger');

        $own->reset();
        self::assertNotSame($em, $own->get(), 'kernel.reset drops it');
    }

    public function testResetDiscardsPendingWorkAndWarns(): void
    {
        $logger = new SecretCapturingLogger();
        $own    = new YopassEntityManager($this->registry($this->source()), 'default', $logger);
        self::assertFalse($own->isCreated());
        self::assertSame(0, $own->pendingCount(), 'No manager is created to count');
        $own->reset();
        self::assertSame([], $logger->records);

        $em  = $own->get();
        $log = new GuardUser(1, 'alice');
        $em->persist($log);
        self::assertSame(1, $own->pendingCount());
        $own->reset();
        self::assertFalse($own->isCreated());
        self::assertCount(1, $logger->records);
        self::assertSame(['count' => 1], $logger->records[0]['context']);
        self::assertNotSame($em, $own->get());
    }

    /**
     * Audit NX Y1: computing the change sets (and clearing) can throw; the boundary still drops the broken manager, logs
     * a generic warning and the next call gets a fresh, working one.
     */
    public function testAThrowingChangeSetComputationNeverKeepsTheBrokenManager(): void
    {
        $logger = new SecretCapturingLogger();
        $own    = new YopassEntityManager($this->registry($this->source()), 'default', $logger);
        $own->get();

        $broken = $this->createMock(EntityManagerInterface::class);
        $broken->method('isOpen')->willReturn(true);
        $broken->method('getUnitOfWork')->willThrowException(new RuntimeException('computeChangeSets failed'));
        $broken->expects(self::once())->method('clear')->willThrowException(new RuntimeException('clear failed'));
        (new ReflectionProperty(YopassEntityManager::class, 'entityManager'))->setValue($own, $broken);

        $own->reset();

        self::assertFalse($own->isCreated(), 'The broken manager is dropped first');
        self::assertCount(2, $logger->records);
        self::assertSame(['exception' => RuntimeException::class], $logger->records[0]['context']);
        self::assertSame(['exception' => RuntimeException::class], $logger->records[1]['context']);
        $fresh = $own->get();
        self::assertNotSame($broken, $fresh);
        self::assertTrue($fresh->isOpen());
        self::assertSame(0, $own->pendingCount());
        $own->reset();
        self::assertCount(2, $logger->records, 'A healthy drop logs nothing');
    }

    public function testAClosedManagerIsDroppedWithoutInspection(): void
    {
        $own = new YopassEntityManager($this->registry($this->source()), 'default', new SecretCapturingLogger());
        $em  = $own->get();
        $em->close();
        $own->reset();
        self::assertFalse($own->isCreated());
        self::assertNotSame($em, $own->get());
    }

    public function testARollbackReplacesTheManagerUnlessItHasPendingWork(): void
    {
        $own = new YopassEntityManager($this->registry($this->source()));
        $own->connectionRolledBack();
        self::assertFalse($own->isStale(), 'Nothing to mark without a manager');

        $first = $own->get();
        $first->persist(new GuardUser(2, 'bob'));
        $own->connectionRolledBack();
        self::assertTrue($own->isStale());
        self::assertSame($first, $own->get(), 'Kept while it has scheduled work');

        $first->clear();
        $second = $own->get();
        self::assertNotSame($first, $second, 'Replaced once nothing is pending');
        self::assertFalse($own->isStale());

        $second->close();
        $own->clear();
        self::assertSame(0, $own->pendingCount(), 'A closed manager has nothing pending');
    }

    public function testTheApplicationsEnabledFiltersAndParametersAreApplied(): void
    {
        $source = $this->source();
        $source->getConfiguration()->addFilter('creator_name', CreatorNameFilter::class);
        $source->getConfiguration()->addFilter('other', CreatorNameFilter::class);
        $own = new YopassEntityManager($this->registry($source));
        self::assertSame([], $own->get()->getFilters()->getEnabledFilters());

        $source->getFilters()->enable('creator_name')->setParameter('name', 'alice');
        $source->getFilters()->enable('other')->setParameterList('names', ['a', 'b']);
        $filters = $own->get()->getFilters();
        self::assertTrue($filters->isEnabled('creator_name'));
        self::assertSame((string) $source->getFilters()->getFilter('creator_name'), (string) $filters->getFilter('creator_name'));
        self::assertSame((string) $source->getFilters()->getFilter('other'), (string) $filters->getFilter('other'));
        self::assertSame("'alice'", $filters->getFilter('creator_name')->getParameter('name'));

        // A later request changes the parameter, then switches the filter off: followed on the next call.
        $source->getFilters()->getFilter('creator_name')->setParameter('name', 'bob');
        self::assertSame("'bob'", $own->get()->getFilters()->getFilter('creator_name')->getParameter('name'));
        // The source is rebuilt and re-enables the filter without the parameter: the old value must not survive.
        $source->getFilters()->disable('creator_name');
        $source->getFilters()->enable('creator_name');
        $private = $own->get()->getFilters()->getFilter('creator_name');
        self::assertSame((string) $source->getFilters()->getFilter('creator_name'), (string) $private);
        try {
            $private->getParameter('name');
            self::fail('The previous parameter value survived');
        } catch (InvalidArgumentException) {
        }
        $unchanged = $own->get()->getFilters()->getFilter('creator_name');
        self::assertSame($private, $unchanged, 'An identical filter is kept as it is');

        $source->getFilters()->disable('creator_name');
        self::assertFalse($own->get()->getFilters()->isEnabled('creator_name'));
        self::assertTrue($own->get()->getFilters()->isEnabled('other'));
    }

    public function testASourceThatIsNotAnOrmManagerFails(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_yopass: entity manager "other" is not a Doctrine ORM entity manager.');
        (new YopassEntityManager($registry, 'other'))->get();
    }

    private function registry(EntityManagerInterface $source): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($source);

        return $registry;
    }

    private function source(): EntityManager
    {
        $paths  = [dirname(__DIR__, 3) . '/src/Entity', dirname(__DIR__, 2) . '/Support/Orm'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-own-em-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }
}
