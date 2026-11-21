<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Doctrine\HostCommitEventRelay;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Share\ShareAccessGuardInterface;
use Nowo\YopassBundle\Share\ShareCommandException;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Nowo\YopassBundle\Share\ShareCriteria;
use Nowo\YopassBundle\Share\ShareQueryInterface;
use Nowo\YopassBundle\Share\ShareView;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function extension_loaded;
use function str_repeat;

/**
 * 2.1.0 (DDD-host checklist B1, B4, B7, B8) on a real kernel and SQLite: the id-based commands, the read port, the
 * id access check and the lifecycle events after the application's commit, for a DDD host (creator stored by id) and
 * a standard host (creator relation to an integer-keyed user entity).
 */
final class IdPortsKernelTest extends TestCase
{
    /** @var list<DddHostTestKernel> */
    private array $kernels = [];

    /** @var list<array{name: string, event: object}> */
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        $this->events  = [];
        (new Filesystem())->remove(DddHostTestKernel::baseDir());
    }

    public function testTheDddHostCreatesListsAndDeletesByIds(): void
    {
        $container = $this->container(true);
        $commands  = $this->service($container, ShareCommandsInterface::class, ShareCommandsInterface::class);
        $query     = $this->service($container, ShareQueryInterface::class, ShareQueryInterface::class);

        $first  = $commands->create(new CreatorId('alice'), $this->data());
        $second = $commands->create(new CreatorId('alice'), $this->data(3));
        $bob    = $commands->create(new CreatorId('bob'), $this->data());
        // A look-alike creator id (SQLite compares exactly; the PHP re-check holds on any collation).
        $alike = $commands->create(new CreatorId('Alice'), $this->data());

        self::assertSame('alice', $first->creatorId);
        self::assertSame(ShareView::STATE_ACTIVE, $first->state);
        self::assertSame(3, $second->maxReads);
        self::assertSame(2, $query->count(ShareCriteria::ofCreator('alice')));
        self::assertSame(3, $query->count(new ShareCriteria(['alice', 'bob'])));

        $page = $query->page(ShareCriteria::ofCreator('alice'), 1);
        self::assertSame(2, $page->total);
        self::assertCount(1, $page->items);
        self::assertEqualsCanonicalizing([$first->id, $bob->id], $query->ids(ShareCriteria::withIds([$bob->id, $first->id, 'missing-id'])));
        // Intersection and union of creators and ids.
        self::assertSame([], $query->ids(new ShareCriteria(['alice'], [$bob->id])));
        self::assertCount(3, $query->ids(new ShareCriteria(['alice'], [$bob->id], true)));
        self::assertNull($query->find('missing-id'));
        self::assertNull($query->find('bad id'));
        self::assertSame($alike->id, $query->find($alike->id)?->id);

        // Consumed and revoked shares leave the active filter.
        self::assertSame('ok', $this->service($container, ShareRetriever::class, ShareRetriever::class)->consume($first->id)['status']);
        self::assertSame(ShareView::STATE_CONSUMED, $query->find($first->id)?->state);
        $revoked = $commands->revoke($second->id, 'admin-1');
        self::assertSame(ShareView::STATE_REVOKED, $revoked?->state);
        self::assertSame(0, $query->count(ShareCriteria::ofCreator('alice', ShareCriteria::STATE_ACTIVE)));
        self::assertSame(2, $query->count(ShareCriteria::ofCreator('alice', ShareCriteria::STATE_INACTIVE)));
        $revokedEvents = $this->eventsNamed(YopassEvents::SHARE_REVOKED);
        self::assertCount(1, $revokedEvents);
        self::assertInstanceOf(ShareRevokedEvent::class, $revokedEvents[0]);
        self::assertSame('admin-1', $revokedEvents[0]->getRevokedById());
        // Revoking again changes nothing and dispatches nothing.
        $commands->revoke($second->id);
        self::assertCount(1, $this->eventsNamed(YopassEvents::SHARE_REVOKED));
        self::assertNull($commands->revoke('missing-id'));

        // A scoped delete never removes another creator's share, even when its id is listed.
        self::assertSame(1, $commands->deleteByIds([$first->id, $bob->id], new CreatorId('alice'), 'alice'));
        self::assertNotNull($query->find($bob->id));
        $bulk = $this->eventsNamed(YopassEvents::SHARES_BULK_DELETED);
        self::assertCount(1, $bulk);
        self::assertInstanceOf(ShareBulkDeletedEvent::class, $bulk[0]);
        self::assertSame(ShareBulkDeletedEvent::REASON_DELETE_BY_IDS, $bulk[0]->getReason());
        self::assertSame([$first->id], $bulk[0]->getShareIds());
        self::assertSame('alice', $bulk[0]->getSubjectId());
        self::assertSame('alice', $bulk[0]->getViewerId());

        // Of two deletes of the same share, only the first one reports it.
        self::assertTrue($commands->delete($bob->id, 'bob'));
        self::assertFalse($commands->delete($bob->id, 'bob'));
        $deleted = $this->eventsNamed(YopassEvents::SHARE_DELETED);
        self::assertCount(1, $deleted);
        self::assertInstanceOf(ShareDeletedEvent::class, $deleted[0]);
        self::assertSame('bob', $deleted[0]->getDeletedById());
        self::assertSame('bob', $deleted[0]->getCreatorId());

        self::assertSame(1, $commands->deleteAllOf(new CreatorId('alice')));
        self::assertSame(0, $query->count(ShareCriteria::ofCreator('alice')));
        self::assertSame(1, $query->count(ShareCriteria::ofCreator('Alice')));

        $created = $this->eventsNamed(YopassEvents::SHARE_CREATED);
        self::assertCount(4, $created);
        self::assertInstanceOf(ShareCreatedEvent::class, $created[0]);
        self::assertSame('alice', $created[0]->getCreatorId());
        self::assertInstanceOf(DateTimeImmutable::class, $created[0]->getOccurredAt());
    }

    public function testTheCommandsRefuseInputTheCreateFormRefuses(): void
    {
        $commands = $this->service($this->container(true), ShareCommandsInterface::class, ShareCommandsInterface::class);

        foreach ([
            ShareCommandException::INVALID_CIPHERTEXT   => static function (ShareCreateData $data): void { $data->ciphertext = ' '; },
            ShareCommandException::CIPHERTEXT_TOO_LARGE => static function (ShareCreateData $data): void { $data->ciphertext = str_repeat('x', 700_001); },
            ShareCommandException::INVALID_EXPIRATION   => static function (ShareCreateData $data): void { $data->expiresIn = 'forever'; },
            ShareCommandException::INVALID_MAX_READS    => static function (ShareCreateData $data): void { $data->maxReads = 2; },
            ShareCommandException::INVALID_PAYLOAD_KIND => static function (ShareCreateData $data): void { $data->payloadKind = 'html'; },
            ShareCommandException::FILE_NOT_ENABLED     => static function (ShareCreateData $data): void { $data->payloadKind = 'file'; },
        ] as $code => $change) {
            $data = $this->data();
            $change($data);
            try {
                $commands->create(new CreatorId('alice'), $data);
                self::fail('Refused: ' . $code);
            } catch (ShareCommandException $exception) {
                self::assertSame($code, $exception->errorCode);
                self::assertSame($code, $exception->getMessage());
            }
        }

        $this->expectException(ShareExtendException::class);
        $commands->extend('missing-id', '1h', null);
    }

    public function testTheIdAccessCheckComparesTheCreatorIdExactly(): void
    {
        $container = $this->container(true);
        $share     = $this->service($container, ShareCommandsInterface::class, ShareCommandsInterface::class)->create(new CreatorId('alice'), $this->data());
        $guard     = $this->service($container, ShareAccessGuardInterface::class, ShareAccessGuardInterface::class);

        self::assertTrue($guard->canManageId(new UserRef('alice'), $share->id, ShareAccessAction::Revoke));
        self::assertFalse($guard->canManageId(new UserRef('Alice'), $share->id, ShareAccessAction::Revoke));
        self::assertFalse($guard->canManageId(new UserRef('alice', 'alice', [], true), $share->id, ShareAccessAction::Revoke), 'An identifier is not an id');
        self::assertFalse($guard->canManageId(new UserRef('alice'), 'missing-id', ShareAccessAction::Revoke));

        $checks = $this->eventsNamed(YopassEvents::SHARE_ACCESS_CHECK);
        self::assertCount(3, $checks);
        self::assertInstanceOf(ShareAccessCheckEvent::class, $checks[0]);
        self::assertSame('alice', $checks[0]->getUserId());
        self::assertSame($share->id, $checks[0]->getShareId());
        self::assertSame('alice', $checks[0]->getCreatorId());
        self::assertNull($checks[2]->getUserId());

        // A listener may still grant (a team rule keyed by ids).
        $this->dispatcher($container)->addListener(YopassEvents::SHARE_ACCESS_CHECK, static function (ShareAccessCheckEvent $event): void {
            if ($event->getUserId() === 'manager') {
                $event->grant();
            }
        });
        self::assertTrue($guard->canManageId(new UserRef('manager'), $share->id, ShareAccessAction::Delete));
    }

    public function testTheStandardHostRelatesACreatorIdToItsIntegerKeyedUser(): void
    {
        $container = $this->container(false);
        $em        = $this->service($container, 'doctrine.orm.entity_manager', EntityManagerInterface::class);
        $em->persist(new GuardUser(7, 'alice'));
        $em->flush();

        $commands = $this->service($container, ShareCommandsInterface::class, ShareCommandsInterface::class);
        $query    = $this->service($container, ShareQueryInterface::class, ShareQueryInterface::class);
        $share    = $commands->create(new CreatorId('7'), $this->data());

        self::assertSame('7', $share->creatorId);
        self::assertSame('7', $em->getConnection()->fetchOne('SELECT creator_id FROM yopass_secure_shares WHERE id = ?', [$share->id]));
        self::assertSame([$share->id], $query->ids(ShareCriteria::ofCreator('7')));
        self::assertSame([], $query->ids(ShareCriteria::ofCreator('07')));
        self::assertSame([], $query->ids(ShareCriteria::ofCreator('abc')));
        self::assertTrue($this->service($container, ShareAccessGuardInterface::class, ShareAccessGuardInterface::class)->canManageId(new UserRef('7'), $share->id, ShareAccessAction::View));

        $this->expectException(AccessDeniedException::class);
        $commands->create(new CreatorId('abc'), $this->data());
    }

    public function testEventsWaitForTheApplicationCommitAndAreDroppedOnRollback(): void
    {
        $container  = $this->container(true);
        $commands   = $this->service($container, ShareCommandsInterface::class, ShareCommandsInterface::class);
        $relay      = $this->service($container, HostCommitEventRelay::class, HostCommitEventRelay::class);
        $connection = $this->service($container, 'doctrine.orm.entity_manager', EntityManagerInterface::class)->getConnection();

        // No transaction: at once, as in 2.0.
        $commands->create(new CreatorId('alice'), $this->data());
        self::assertCount(1, $this->eventsNamed(YopassEvents::SHARE_CREATED));

        // Committed application transaction: held, released by the commit, dispatched once the connection is idle.
        $connection->beginTransaction();
        $kept = $commands->create(new CreatorId('alice'), $this->data());
        self::assertCount(1, $this->eventsNamed(YopassEvents::SHARE_CREATED));
        self::assertSame(['held' => 1, 'released' => 0], $relay->pending());
        $connection->commit();
        self::assertSame(['held' => 0, 'released' => 1], $relay->pending());
        $relay->onResponse(new ResponseEvent($this->kernels[0], new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()));
        $created = $this->eventsNamed(YopassEvents::SHARE_CREATED);
        self::assertCount(2, $created);
        self::assertInstanceOf(ShareCreatedEvent::class, $created[1]);
        self::assertSame($kept->id, $created[1]->getShareId());

        // Rolled-back application transaction: the event is dropped with the row.
        $connection->beginTransaction();
        $lost = $commands->create(new CreatorId('alice'), $this->data());
        $connection->rollBack();
        $relay->reset();
        self::assertCount(2, $this->eventsNamed(YopassEvents::SHARE_CREATED));
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares WHERE id = ?', [$lost->id]));

        // A rolled-back savepoint drops only the events held inside it.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $commands->create(new CreatorId('alice'), $this->data());
        $connection->rollBack();
        $outer = $commands->create(new CreatorId('alice'), $this->data());
        $connection->commit();
        $relay->reset();
        $created = $this->eventsNamed(YopassEvents::SHARE_CREATED);
        self::assertCount(3, $created);
        self::assertInstanceOf(ShareCreatedEvent::class, $created[2]);
        self::assertSame($outer->id, $created[2]->getShareId());

        // The next bundle event outside a transaction also dispatches what a commit released.
        $connection->beginTransaction();
        $commands->revoke($outer->id);
        $connection->commit();
        self::assertCount(0, $this->eventsNamed(YopassEvents::SHARE_REVOKED));
        $commands->create(new CreatorId('alice'), $this->data());
        self::assertCount(1, $this->eventsNamed(YopassEvents::SHARE_REVOKED));
    }

    public function testEventsAreImmediateWhenTheOptionIsOff(): void
    {
        $container = $this->container(true, ['events' => ['after_host_commit' => false]]);
        self::assertFalse($container->has(HostCommitEventRelay::class));
        $connection = $this->service($container, 'doctrine.orm.entity_manager', EntityManagerInterface::class)->getConnection();

        $connection->beginTransaction();
        $this->service($container, ShareCommandsInterface::class, ShareCommandsInterface::class)->create(new CreatorId('alice'), $this->data());
        self::assertCount(1, $this->eventsNamed(YopassEvents::SHARE_CREATED));
        $connection->rollBack();
    }

    /**
     * @param array<string, mixed> $yopass
     */
    private function container(bool $ddd, array $yopass = []): ContainerInterface
    {
        $kernel = new DddHostTestKernel($ddd, $yopass);
        $kernel->boot();
        $this->kernels[] = $kernel;
        $container       = $this->service($kernel->getContainer(), 'test.service_container', ContainerInterface::class);

        $em = $this->service($container, 'doctrine.orm.entity_manager', EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $dispatcher = $this->dispatcher($container);
        foreach ([YopassEvents::SHARE_CREATED, YopassEvents::SHARE_DELETED, YopassEvents::SHARE_REVOKED, YopassEvents::SHARES_BULK_DELETED, YopassEvents::SHARE_ACCESS_CHECK] as $name) {
            $dispatcher->addListener($name, function (object $event) use ($name): void {
                $this->events[] = ['name' => $name, 'event' => $event];
            });
        }

        return $container;
    }

    private function dispatcher(ContainerInterface $container): EventDispatcherInterface
    {
        return $this->service($container, 'event_dispatcher', EventDispatcherInterface::class);
    }

    /**
     * @return list<object>
     */
    private function eventsNamed(string $name): array
    {
        $events = [];
        foreach ($this->events as $item) {
            if ($item['name'] === $name) {
                $events[] = $item['event'];
            }
        }

        return $events;
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

    private function data(int $maxReads = 1): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = $maxReads;

        return $data;
    }
}
