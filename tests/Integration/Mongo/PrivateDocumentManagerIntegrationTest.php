<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Mongo;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Events;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use MongoDB\Client;
use MongoDB\Collection;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Doctrine\YopassDocumentManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManagerBoundarySubscriber;
use Nowo\YopassBundle\Doctrine\YopassPrivateDocumentManager;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Odm\MongoUser;
use Nowo\YopassBundle\Tests\Support\Odm\PropertyTenantBsonFilter;
use Nowo\YopassBundle\Tests\Support\Odm\TenantBsonFilter;
use Nowo\YopassBundle\Tests\Support\Odm\ThrowingClearListener;
use Nowo\YopassBundle\Tests\Support\SecretCapturingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function bin2hex;
use function dirname;
use function extension_loaded;
use function getenv;
use function is_array;
use function is_dir;
use function is_string;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * The private document manager of the MongoDB ODM driver (1.22.0 opt-in, the only mode since 2.0, REQ-ARCH-001 rule 6)
 * on a real MongoDB server (set YOPASS_TEST_MONGODB_URL): a share write never writes the application's pending changes, the application's
 * document manager is never flushed, cleared, closed or reset, and the private manager is dropped at every outermost
 * boundary of a kept-alive worker (kernel reset off) and replaced when closed.
 */
final class PrivateDocumentManagerIntegrationTest extends TestCase
{
    private Client $client;

    private string $database;

    private DocumentManager $host;

    private YopassDocumentManager $own;

    private DoctrineMongoShareRepository $repository;

    private MongoUser $creator;

    protected function setUp(): void
    {
        $url = getenv('YOPASS_TEST_MONGODB_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set YOPASS_TEST_MONGODB_URL to run the MongoDB private document manager test.');
        }

        if (!extension_loaded('mongodb')) {
            self::markTestSkipped('ext-mongodb is required.');
        }

        $this->client   = new Client($url);
        $this->database = 'yopass_test_' . bin2hex(random_bytes(4));
        $this->host     = $this->createDocumentManager();

        $this->creator = new MongoUser('user-private-1');
        $this->host->persist($this->creator);
        $this->host->flush();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(fn (): DocumentManager => $this->host);
        $this->own        = new YopassDocumentManager($registry, 'default');
        $this->repository = new DoctrineMongoShareRepository($this->host, null, $registry, 'default', $this->resolver($registry), $this->own);
    }

    protected function tearDown(): void
    {
        if (isset($this->client, $this->database)) {
            $this->client->dropDatabase($this->database);
        }
    }

    public function testAShareWriteNeverWritesTheApplicationsPendingChanges(): void
    {
        $pending = new MongoUser('user-pending-host');
        $this->host->persist($pending);

        $share = $this->newShare('00000000-0000-4000-8000-0000000000e1');
        $this->repository->persist($share);
        $this->repository->flush();

        self::assertSame(1, $this->collection('yopass_secure_shares')->countDocuments(['_id' => $share->getId()]));
        self::assertSame(0, $this->collection('yopass_test_users')->countDocuments(['_id' => 'user-pending-host']), 'the application\'s pending insert must not be written by the bundle');
        self::assertTrue($this->host->getUnitOfWork()->isDocumentScheduled($pending), 'the application\'s unit of work is untouched');
        self::assertTrue($this->host->isOpen());
        self::assertTrue($this->host->contains($this->creator), 'the application\'s manager is never cleared');
        self::assertArrayNotHasKey(SecureShareDocument::class, $this->host->getUnitOfWork()->getIdentityMap(), 'the share lives in the private manager only');
        self::assertTrue($this->own->get()->contains($this->own->get()->find(SecureShareDocument::class, $share->getId())));

        // The application flushes its own change itself.
        $this->host->flush();
        self::assertSame(1, $this->collection('yopass_test_users')->countDocuments(['_id' => 'user-pending-host']));
    }

    public function testRelationMappedCreatorIsStoredAndFoundThroughThePrivateManager(): void
    {
        $share = $this->newShare('00000000-0000-4000-8000-0000000000e2');
        $this->repository->persist($share);
        $this->repository->flush();

        $stored = $this->collection('yopass_secure_shares')->findOne(['_id' => $share->getId()]);
        self::assertNotNull($stored);
        self::assertSame('user-private-1', $stored['creator']);

        $this->own->reset();
        self::assertSame(1, $this->repository->countByCreator($this->creator));
        $found = $this->repository->findByCreator($this->creator);
        self::assertCount(1, $found);
        self::assertSame($share->getId(), $found[0]->getId());
    }

    public function testShareCreatedEventExposesTheCreatorOfTheApplicationsManager(): void
    {
        $events   = new EventDispatcher();
        $captured = null;
        $events->addListener(YopassEvents::SHARE_CREATED, static function (ShareCreatedEvent $event) use (&$captured): void {
            $captured = $event->getCreator();
        });
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(fn (): DocumentManager => $this->host);
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';

        $share = (new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, $events, $this->resolver($registry), new HostCreatorReference($this->own)))->create($this->creator, $data);

        self::assertSame('user-private-1', $share->getCreatorId());
        self::assertSame($this->creator, $captured, 'the event hands the application its own managed user');
        self::assertFalse($this->host->getUnitOfWork()->isDocumentScheduled($this->creator));
    }

    public function testEveryOutermostBoundaryDropsThePrivateManagerInAKeptAliveWorker(): void
    {
        $logger   = new SecretCapturingLogger();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(fn (): DocumentManager => $this->host);
        $own        = new YopassDocumentManager($registry, 'default', $logger);
        $repository = new DoctrineMongoShareRepository($this->host, null, $registry, 'default', $this->resolver($registry, $own), $own);
        $stack      = new RequestStack();
        $subscriber = new YopassEntityManagerBoundarySubscriber($own, $stack);
        $kernel     = $this->createStub(HttpKernelInterface::class);

        // Request 1 loads a share and leaves a change unflushed.
        $request = $this->startRequest($subscriber, $stack, $kernel);
        $share   = $this->newShare('00000000-0000-4000-8000-0000000000e3', $own);
        $repository->persist($share);
        $repository->flush();
        $first = $own->get();
        $repository->persist($share->setCiphertext('unflushed'));
        $this->endRequest($subscriber, $stack, $kernel, $request);

        self::assertFalse($own->isCreated(), 'kernel.terminate dropped the private manager');
        self::assertSame([['level' => 'warning', 'message' => 'Discarding {count} unflushed Yopass share change(s) at a unit-of-work boundary.', 'context' => ['count' => 1]]], $logger->records);
        self::assertSame(0, $first->getUnitOfWork()->size(), 'the dropped manager holds no share');

        // Request 2 (same process, no services resetter): a fresh manager, the unflushed change is never written.
        $request = $this->startRequest($subscriber, $stack, $kernel);
        self::assertNotSame($first, $own->get());
        $repository->flush();
        self::assertSame('cipher', $repository->find($share->getId())?->getCiphertext());
        $this->endRequest($subscriber, $stack, $kernel, $request);
        self::assertFalse($own->isCreated());
        self::assertTrue($this->host->isOpen());
        self::assertTrue($this->host->contains($this->creator), 'the application\'s manager is never cleared by a boundary');
    }

    public function testAClosedPrivateManagerIsReplacedAndTheApplicationsIsNot(): void
    {
        $first = $this->own->get();
        $first->close();

        $second = $this->own->get();
        self::assertNotSame($first, $second);
        self::assertTrue($second->isOpen());
        self::assertSame($this->host->getClient(), $second->getClient(), 'same server and database');
        self::assertTrue($this->host->isOpen());
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function scopedModes(): iterable
    {
        foreach (['parameter' => 'parameter', 'property' => 'property'] as $label => $filter) {
            yield 'repository built on the given manager, ' . $label . ' filter' => [false, $filter];
            yield 'private document manager (the bundle wiring), ' . $label . ' filter' => [true, $filter];
        }
    }

    /**
     * Audit XC-M1: a tenant filter the application enabled scopes every share query of the private manager exactly as
     * in the default mode, for a filter that keeps its tenant in a parameter AND for one that keeps it in a property
     * (set through a setter: ODM filters cannot use DI). Another tenant's share is never found, never consumed (its
     * reads are untouched) and never removed; the application switching the tenant applies at once.
     */
    #[DataProvider('scopedModes')]
    public function testTheApplicationsTenantFilterScopesFindConsumeAndRemove(bool $private, string $filter): void
    {
        foreach (['tenant-a', 'tenant-b'] as $tenant) {
            $this->host->persist(new MongoUser($tenant));
        }
        $this->host->flush();
        $unfiltered = new DoctrineMongoShareRepository($this->host);
        $ids        = ['tenant-a' => '00000000-0000-4000-8000-0000000000a1', 'tenant-b' => '00000000-0000-4000-8000-0000000000b1'];
        foreach ($ids as $tenant => $id) {
            $share = new SecureShare($id, $this->host->find(MongoUser::class, $tenant) ?? throw new LogicException($tenant));
            $unfiltered->persist($share->setCiphertext('cipher-' . $tenant)->setExpiresAt(new DateTimeImmutable('+1 day'))->setMaxReads(3));
        }
        $unfiltered->flush();
        $this->host->clear();

        $class = $filter === 'parameter' ? TenantBsonFilter::class : PropertyTenantBsonFilter::class;
        $this->host->getConfiguration()->addFilter('yopass_test_tenant', $class);
        $this->tenant('tenant-a');

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(fn (): DocumentManager => $this->host);
        $own        = $private ? new YopassDocumentManager($registry, 'default') : null;
        $repository = new DoctrineMongoShareRepository($this->host, null, $registry, 'default', null, $own);

        self::assertSame('cipher-tenant-a', $repository->find($ids['tenant-a'])?->getCiphertext());
        self::assertNull($repository->find($ids['tenant-b']), 'another tenant\'s share is never found');
        self::assertNull($repository->consumeReadIfAvailable($ids['tenant-b']), 'another tenant\'s share is never burned');
        self::assertSame(3, $this->stored($ids['tenant-b'])['readsLeft']);
        self::assertSame(2, $repository->consumeReadIfAvailable($ids['tenant-a'])?->getReadsLeft());

        // The application switches the tenant (same filter instance): applied at once, no stale copy.
        // (A share already in the identity map is served by find() in both modes, as ODM does; queries are filtered.)
        $this->tenant('tenant-b');
        self::assertNull($repository->consumeReadIfAvailable($ids['tenant-a']));
        self::assertSame(2, $this->stored($ids['tenant-a'])['readsLeft'] ?? null);
        self::assertSame('cipher-tenant-b', $repository->find($ids['tenant-b'])?->getCiphertext());

        // A purge only removes the current tenant's shares.
        $this->tenant('tenant-a');
        self::assertSame(1, $repository->removeOlderThan(new DateTimeImmutable('+1 hour')));
        $repository->flush();
        self::assertNull($this->stored($ids['tenant-a']));
        self::assertNotNull($this->stored($ids['tenant-b']), 'another tenant\'s share is never removed');
    }

    public function testReferencesPendingCountAndClearNeverTouchTheApplicationsManager(): void
    {
        self::assertSame(0, $this->own->pendingCount());
        $reference = $this->own->reference($this->creator, MongoUser::class);
        self::assertNotSame($this->creator, $reference);
        self::assertSame($reference, $this->own->reference($reference, MongoUser::class), 'already managed by the private manager');
        $other = new MongoUser('user-unknown');
        self::assertSame($other, $this->own->forHost($other), 'not a private-manager creator: unchanged');
        self::assertSame($this->creator, $this->own->forHost($reference));

        $this->repository->persist($this->newShare('00000000-0000-4000-8000-0000000000e7'));
        self::assertSame(1, $this->own->pendingCount());
        $this->own->clear();
        self::assertSame(0, $this->own->pendingCount());
        self::assertSame(0, $this->collection('yopass_secure_shares')->countDocuments());
        self::assertTrue($this->host->contains($this->creator));
    }

    public function testAFailingClearOfAReplacedOrDroppedPrivateManagerIsLoggedAndNeverEscapes(): void
    {
        $logger   = new SecretCapturingLogger();
        $source   = $this->host;
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static function () use (&$source): DocumentManager {
            return $source;
        });
        $own   = new YopassDocumentManager($registry, 'default', $logger);
        $first = $own->get();

        // A listener of the shared event manager throws on clear (the application's code, not the bundle's).
        $this->host->getEventManager()->addEventListener(Events::onClear, new ThrowingClearListener());
        $source = $this->createDocumentManager();
        $source->getEventManager()->addEventListener(Events::onClear, new ThrowingClearListener());
        $second = $own->get();
        self::assertNotSame($first, $second, 'a replaced source manager gets a new private manager');
        $own->reset();
        self::assertFalse($own->isCreated());

        self::assertSame([
            ['level' => 'warning', 'message' => 'Could not clear the replaced Yopass document manager.', 'context' => ['exception' => RuntimeException::class]],
            ['level' => 'warning', 'message' => 'Could not clear the dropped Yopass document manager at a unit-of-work boundary.', 'context' => ['exception' => RuntimeException::class]],
        ], $logger->records);
    }

    public function testThePrivateManagerHasNoFiltersOfItsOwn(): void
    {
        $private = YopassPrivateDocumentManager::create($this->client, $this->host->getConfiguration());

        $this->expectExceptionObject(new LogicException('nowo_yopass: the private document manager has no source manager for its filters.'));
        $private->getFilterCollection();
    }

    private function tenant(string $tenant): void
    {
        $filters = $this->host->getFilterCollection();
        $filter  = $filters->isEnabled('yopass_test_tenant') ? $filters->getFilter('yopass_test_tenant') : $filters->enable('yopass_test_tenant');
        if ($filter instanceof PropertyTenantBsonFilter) {
            $filter->setTenant($tenant);
        } else {
            $filter->setParameter('tenant', $tenant);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(string $id): ?array
    {
        $document = $this->collection('yopass_secure_shares')->findOne(['_id' => $id], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        return is_array($document) ? $document : null;
    }

    private function resolver(ManagerRegistry $registry, ?YopassDocumentManager $own = null): DoctrineCreatorReferenceResolver
    {
        return new DoctrineCreatorReferenceResolver(MongoUser::class, $registry, 'default', new CreatorClassPolicy(MongoUser::class), ownManager: $own ?? $this->own);
    }

    private function newShare(string $id, ?YopassDocumentManager $own = null): SecureShare
    {
        $share = new SecureShare($id, $this->resolver($this->createStub(ManagerRegistry::class), $own)->reference($this->creator));
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 day'))->setMaxReads(3);

        return $share;
    }

    private function startRequest(YopassEntityManagerBoundarySubscriber $subscriber, RequestStack $stack, HttpKernelInterface $kernel): Request
    {
        $request = Request::create('/');
        $stack->push($request);
        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        return $request;
    }

    private function endRequest(YopassEntityManagerBoundarySubscriber $subscriber, RequestStack $stack, HttpKernelInterface $kernel, Request $request): void
    {
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $stack->pop();
        $terminate = new TerminateEvent($kernel, $request, new Response());
        $subscriber->onRequestBoundaryEnded($terminate);
        $subscriber->onTerminate($terminate);
    }

    private function collection(string $name): Collection
    {
        return $this->client->selectCollection($this->database, $name);
    }

    private function createDocumentManager(): DocumentManager
    {
        $proxyDir    = sys_get_temp_dir() . '/yopass-odm-proxies';
        $hydratorDir = sys_get_temp_dir() . '/yopass-odm-hydrators';
        foreach ([$proxyDir, $hydratorDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }
        }

        $config = new Configuration();
        $config->setProxyDir($proxyDir);
        $config->setProxyNamespace('YopassOdmProxies');
        $config->setHydratorDir($hydratorDir);
        $config->setHydratorNamespace('YopassOdmHydrators');
        $config->setAutoGenerateProxyClasses(Configuration::AUTOGENERATE_EVAL);
        $config->setAutoGenerateHydratorClasses(Configuration::AUTOGENERATE_EVAL);
        $config->setDefaultDB($this->database);
        $config->setMetadataDriverImpl(AttributeDriver::create([
            dirname(__DIR__, 3) . '/src/Document',
            dirname(__DIR__, 2) . '/Support/Odm',
        ]));

        $events = new EventManager();
        $events->addEventListener(
            Events::loadClassMetadata,
            new SecureShareDocumentMetadataListener('yopass_secure_shares', MongoUser::class),
        );

        return DocumentManager::create($this->client, $config, $events);
    }
}
