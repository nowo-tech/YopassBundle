<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManagerBoundarySubscriber;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Tests\Support\Orm\GuardTenant;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function restore_error_handler;
use function set_error_handler;
use function sys_get_temp_dir;

use const E_USER_DEPRECATED;
use const PHP_VERSION_ID;

/**
 * The host-flush probe of the ORM host mode (1.22.0, `database.private_entity_manager: false`, REQ-ARCH-001 rule 6
 * deprecation layer): right before the bundle flushes the application's entity manager, every application entity class
 * with pending work triggers one deprecation per unit of work. The bundle's own entities never do, the private manager
 * (the default) never runs the probe, and a kept-alive worker reports again in the next request.
 */
final class HostFlushProbeIntegrationTest extends TestCase
{
    private EntityManager $host;

    private ManagerRegistry $registry;

    /** @var list<string> */
    private array $deprecations = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-probe-proxies');
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

        set_error_handler(function (int $level, string $message): bool {
            $this->deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testHostModeReportsEachForeignClassWithPendingWorkOncePerUnitOfWork(): void
    {
        $probe  = new HostFlushProbe();
        $shares = new DoctrineOrmShareRepository($this->host, null, $this->registry, 'default', null, null, $probe);
        $logs   = new DoctrineOrmShareAccessLogRepository($this->host, $this->registry, 'default', null, $probe);

        // The application leaves an update (GuardUser) and an insert (GuardTenant) pending on its own manager.
        $alice = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $alice->name = 'alice-renamed';
        $this->host->persist(new GuardTenant(1));

        $share = $this->share('00000000-0000-4000-8000-0000000000f1', $alice);
        $shares->persist($share);
        $shares->flush();
        $logs->persist(new ShareAccessLog('00000000-0000-4000-8000-00000000a0f1', $share, 1, '203.0.113.5'));
        $logs->flush();
        // Nothing pending any more: a third flush reports nothing new.
        $shares->persist($this->share('00000000-0000-4000-8000-0000000000f2', $alice));
        $shares->flush();

        self::assertSame([
            'Your pending changes to ' . GuardTenant::class . ' were flushed by NowoYopassBundle; 2.0 no longer does.',
            'Your pending changes to ' . GuardUser::class . ' were flushed by NowoYopassBundle; 2.0 no longer does.',
        ], $this->deprecations, 'one notice per foreign class; the bundle\'s SecureShare and ShareAccessLog are never reported');

        // The probe is read-only: the host mode still writes what it always wrote (1.x behaviour unchanged).
        $this->host->clear();
        self::assertSame('alice-renamed', $this->host->find(GuardUser::class, 7)?->name);
        self::assertNotNull($this->host->find(GuardTenant::class, 1));
    }

    public function testTheProbeReportsAgainInTheNextRequestOfAKeptAliveWorker(): void
    {
        $probe      = new HostFlushProbe();
        $shares     = new DoctrineOrmShareRepository($this->host, null, $this->registry, 'default', null, null, $probe);
        $stack      = new RequestStack();
        $subscriber = new YopassEntityManagerBoundarySubscriber($probe, $stack);
        $kernel     = $this->createStub(HttpKernelInterface::class);

        foreach (['f3', 'f4'] as $i => $suffix) {
            $request = Request::create('/');
            $stack->push($request);
            $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

            $alice = $this->host->find(GuardUser::class, 7);
            self::assertInstanceOf(GuardUser::class, $alice);
            $alice->name = 'alice-' . $i;
            $shares->persist($this->share('00000000-0000-4000-8000-0000000000' . $suffix, $alice));
            $shares->flush();

            $subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $stack->pop();
            $terminate = new TerminateEvent($kernel, $request, new Response());
            $subscriber->onRequestBoundaryEnded($terminate);
            $subscriber->onTerminate($terminate);
        }

        self::assertCount(2, $this->deprecations, 'the per-request state is emptied at every boundary');
    }

    public function testThePrivateManagerNeverRunsTheProbe(): void
    {
        $probe  = new HostFlushProbe();
        $own    = new YopassEntityManager($this->registry, 'default');
        $shares = new DoctrineOrmShareRepository($this->host, null, $this->registry, 'default', null, $own, $probe);

        $alice = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $alice->name = 'alice-renamed';
        $shares->persist($this->share('00000000-0000-4000-8000-0000000000f5', $own->get()->getReference(GuardUser::class, 7)));
        $shares->flush();

        self::assertSame([], $this->deprecations);
        $this->host->getUnitOfWork()->computeChangeSets();
        self::assertSame(['name' => ['alice', 'alice-renamed']], $this->host->getUnitOfWork()->getEntityChangeSet($alice), 'the application\'s change stays pending in its own manager');
    }

    private function share(string $id, object $creator): SecureShare
    {
        $share = new SecureShare($id, $creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 day'))->setMaxReads(3);

        return $share;
    }
}
