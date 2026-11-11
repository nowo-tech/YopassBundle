<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Tests\Support\Orm\CreatorNameFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

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
        self::assertSame($source->getConfiguration(), $em->getConfiguration());
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

        $events = YopassEntityManager::getSubscribedEvents();
        self::assertSame(['onKernelRequest', 2048], $events[KernelEvents::REQUEST]);
        foreach ([KernelEvents::TERMINATE, 'console.terminate', 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'] as $event) {
            self::assertSame(['clear', -2048], $events[$event], $event);
        }
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
