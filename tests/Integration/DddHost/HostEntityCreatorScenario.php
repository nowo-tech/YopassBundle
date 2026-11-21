<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\UnitOfWork;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\ShareExtendedEvent;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Tests\Integration\DddHost\CustomDriver\DecoratingShareRepository;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * 2.0.1 regression (2.0.0 made the bundle's private entity manager the only mode): a relation-mapped creator that the
 * APPLICATION's entity manager loaded is handed to every bundle service on a real kernel. Create, list, retention purge,
 * reveal (consume + access log), extend, revoke, delete and delete-all must succeed, the share must relate to a reference
 * of the bundle's manager, the events must hand back the application's own instance, and the application's manager,
 * its user and its pending changes must be left exactly as they were.
 *
 * Run with the ORM driver and with the custom driver whose repository decorates the bundle's ORM repository (the demo's
 * pattern: on 2.0.0 its create failed with "A new entity was found through the relationship SecureShare#creator").
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait HostEntityCreatorScenario
{
    /** @var list<DddHostTestKernel> */
    private array $kernels = [];

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function drivers(): iterable
    {
        yield 'orm driver' => [[]];
        yield 'custom driver decorating the bundle repository' => [[
            'driver'     => 'custom',
            'repository' => DecoratingShareRepository::class,
        ]];
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(DddHostTestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $database extra `nowo_yopass.database` options
     */
    private function assertHostLoadedCreatorWorksEverywhere(array $database, ?string $databaseUrl, string $platform): void
    {
        $kernel = new DddHostTestKernel(false, [
            'user_class' => GuardUser::class,
            'database'   => ['platform' => $platform] + $database,
            'shares'     => ['retention' => ['enabled' => true, 'max_age' => '30 days']],
        ], [__NAMESPACE__ . '\\CustomDriver\\', __DIR__ . '/CustomDriver'], $databaseUrl);
        $kernel->boot();
        $this->kernels[] = $kernel;
        $container       = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $tool     = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->persist(new GuardUser(7, 'alice'));
        $em->flush();
        $em->clear();

        // The application loaded its user (as a security user provider does) and left changes pending.
        $alice = $em->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $alice->name = 'pending';
        $bob         = new GuardUser(8, 'bob');
        $em->persist($bob);

        $creators   = [];
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([YopassEvents::SHARE_CREATED, YopassEvents::SHARE_EXTENDED, YopassEvents::SHARE_REVOKED, YopassEvents::SHARE_DELETED] as $name) {
            $dispatcher->addListener($name, static function (ShareCreatedEvent|ShareExtendedEvent|ShareRevokedEvent|ShareDeletedEvent $event) use (&$creators, $name): void {
                $creators[$name] = $event->getCreator();
            });
        }

        $shares  = $this->service($container, ShareRepositoryInterface::class, ShareRepositoryInterface::class);
        $creator = $this->service($container, ShareCreator::class, ShareCreator::class);
        self::assertSame(0, $this->service($container, ShareRetentionPurger::class, ShareRetentionPurger::class)->purgeForCreator($alice));
        $first  = $creator->create($alice, $this->hostData(3));
        $second = $creator->create($alice, $this->hostData(1));
        $third  = $creator->create($alice, $this->hostData(1));
        $fourth = $creator->create($alice, $this->hostData(1));
        self::assertSame('7', $first->getCreatorId());

        $own = $this->service($container, YopassEntityManager::class, YopassEntityManager::class)->get();
        self::assertNotSame($alice, $first->getCreator(), 'The share relates to a reference of the bundle\'s manager');
        self::assertTrue($own->contains($first->getCreator()));
        self::assertSame($alice, $creators[YopassEvents::SHARE_CREATED], 'The event hands back the application\'s own user');

        self::assertSame(4, $this->service($container, ShareLister::class, ShareLister::class)->list($alice, 10, 1)['total']);
        self::assertSame(4, $shares->countByCreator($alice));
        self::assertCount(2, $shares->findByCreatorPaginated($alice, 2, 0));

        self::assertSame('ok', $this->service($container, ShareRetriever::class, ShareRetriever::class)->consume($first->getId())['status']);
        $stored = $shares->find($first->getId());
        self::assertInstanceOf(SecureShare::class, $stored);
        $this->service($container, ShareAccessLogger::class, ShareAccessLogger::class)->logSuccessfulConsume($stored, Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.7']));
        $this->service($container, ShareExtender::class, ShareExtender::class)->extend($stored, null, 10, $alice);
        self::assertSame($alice, $creators[YopassEvents::SHARE_EXTENDED]);

        $revoke = $shares->find($second->getId());
        self::assertInstanceOf(SecureShare::class, $revoke);
        $this->service($container, ShareRevoker::class, ShareRevoker::class)->revoke($revoke, $alice);
        self::assertSame($alice, $creators[YopassEvents::SHARE_REVOKED]);

        $delete = $shares->find($third->getId());
        self::assertInstanceOf(SecureShare::class, $delete);
        $deleter = $this->service($container, ShareDeleter::class, ShareDeleter::class);
        $deleter->delete($delete, $alice);
        self::assertSame($alice, $creators[YopassEvents::SHARE_DELETED]);

        $db = $em->getConnection();
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_share_access_logs'));
        self::assertSame(9, (int) $db->fetchOne('SELECT reads_left FROM yopass_secure_shares WHERE id = ?', [$first->getId()]));
        self::assertNotNull($db->fetchOne('SELECT revoked_at FROM yopass_secure_shares WHERE id = ?', [$second->getId()]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares WHERE id = ?', [$fourth->getId()]));
        self::assertSame(3, $deleter->deleteAll($alice));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));

        // The application's manager, its user and its pending changes are untouched; nothing of it was written.
        self::assertTrue($em->isOpen());
        self::assertSame(UnitOfWork::STATE_MANAGED, $em->getUnitOfWork()->getEntityState($alice));
        self::assertSame($alice, $em->find(GuardUser::class, 7), 'The application still holds the same user instance');
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($bob));
        self::assertSame('alice', $db->fetchOne('SELECT name FROM guard_users WHERE id = 7'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM guard_users WHERE id = 8'));
        self::assertFalse($em->contains($first), 'Shares never enter the application\'s unit of work');

        // The application flushes its own changes; a user it loads afterwards is its own, unaffected instance.
        $em->flush();
        self::assertSame('pending', $db->fetchOne('SELECT name FROM guard_users WHERE id = 7'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM guard_users WHERE id = 8'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));
        $em->clear();
        $reloaded = $em->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $reloaded);
        self::assertSame('pending', $reloaded->name);
        self::assertFalse($own->contains($reloaded));
    }

    private function hostData(int $maxReads): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = '{"v":1,"c":"cipher"}';
        $data->maxReads   = $maxReads;

        return $data;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function service(ContainerInterface $container, string $id, string $type): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($type, $service);

        return $service;
    }
}
