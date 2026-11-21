<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\ShareExtendedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareCommands;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareQuery;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Share\ShareCommandException;
use Nowo\YopassBundle\Share\ShareCriteria;
use Nowo\YopassBundle\Share\ShareView;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\InMemoryIdShareRepository;
use Nowo\YopassBundle\Tests\Support\InMemoryShareRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 2.1.0: ShareQuery and ShareCommands over a repository without the native summary reads (the MongoDB / custom
 * fallback path). The Doctrine ORM path runs in {@see \Nowo\YopassBundle\Tests\Integration\DddHost\IdPortsKernelTest}.
 */
final class ShareIdPortsTest extends TestCase
{
    private InMemoryIdShareRepository $repository;

    private EventDispatcher $dispatcher;

    /** @var list<array{name: string, event: object}> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->repository = new InMemoryIdShareRepository();
        $this->dispatcher = new EventDispatcher();
        foreach ([YopassEvents::SHARE_CREATED, YopassEvents::SHARE_DELETED, YopassEvents::SHARE_REVOKED, YopassEvents::SHARE_EXTENDED, YopassEvents::SHARES_BULK_DELETED] as $name) {
            $this->dispatcher->addListener($name, function (object $event) use ($name): void {
                $this->events[] = ['name' => $name, 'event' => $event];
            });
        }
    }

    public function testTheQueryReadsThroughTheFallbackPathExactlyAndInOrder(): void
    {
        $old   = $this->share('00000000-0000-4000-8000-000000000001', 'abc', '-3 hours');
        $new   = $this->share('00000000-0000-4000-8000-000000000002', 'abc', '-1 hour');
        $alike = $this->share('00000000-0000-4000-8000-000000000003', 'AbC', '-2 hours');
        $new->revoke();
        $query = new ShareQuery($this->repository);

        self::assertSame([$new->getId(), $old->getId()], $query->ids(ShareCriteria::ofCreator('abc')));
        self::assertSame([$old->getId(), $new->getId()], $query->ids(new ShareCriteria(['abc'], null, false, ShareCriteria::STATE_ANY, false)));
        self::assertSame([$old->getId()], $query->ids(ShareCriteria::ofCreator('abc', ShareCriteria::STATE_ACTIVE)));
        self::assertSame(2, $query->count(new ShareCriteria(null, [$alike->getId(), $old->getId(), '00000000-0000-4000-8000-000000000009'])));
        self::assertSame([$alike->getId()], $query->ids(new ShareCriteria(['AbC'], [$alike->getId(), $old->getId()])));
        self::assertSame([$new->getId(), $alike->getId(), $old->getId()], $query->ids(new ShareCriteria(['abc'], [$alike->getId()], true)));

        $page = $query->page(ShareCriteria::ofCreator('abc'), 1, 1);
        self::assertSame([$old->getId()], $page->ids());
        self::assertSame(2, $page->total);
        self::assertSame(ShareView::STATE_REVOKED, $query->find($new->getId())?->state);
        self::assertNull($query->find('not an id'));
        self::assertNull($query->find('00000000-0000-4000-8000-000000000009'));
    }

    public function testTheQueryReadsAPlainRepositoryThroughItsCreatorQueries(): void
    {
        $repository = new InMemoryShareRepository();
        $creator    = new TestUser('abc');
        $share      = new SecureShare('00000000-0000-4000-8000-000000000001', $creator);
        $share->setExpiresAt(new DateTimeImmutable('+1 hour'));
        $repository->add($share);
        $resolver = $this->createMock(CreatorReferenceResolverInterface::class);
        $resolver->method('reference')->willReturn($creator);

        self::assertSame([$share->getId()], (new ShareQuery($repository, null, $resolver))->ids(ShareCriteria::ofCreator('abc')));
        self::assertSame([], (new ShareQuery($repository))->ids(ShareCriteria::ofCreator('abc')), 'Without a resolver the plain repository compares objects');
    }

    public function testThePageRefusesOutOfRangeArguments(): void
    {
        $query = new ShareQuery($this->repository);
        foreach ([[0, 0], [1001, 0], [10, -1]] as [$limit, $offset]) {
            try {
                $query->page(ShareCriteria::ofCreator('abc'), $limit, $offset);
                self::fail('Refused');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheCommandsCreateRevokeExtendAndDeleteByIds(): void
    {
        $commands = $this->commands();

        $view = $commands->create(new CreatorId('alice'), $this->data());
        self::assertSame('alice', $view->creatorId);
        self::assertSame(ShareView::STATE_ACTIVE, $view->state);

        $extended = $commands->extend($view->id, '24h', 3, 'alice');
        self::assertSame(3, $extended->maxReads);
        $event = $this->last(YopassEvents::SHARE_EXTENDED);
        self::assertInstanceOf(ShareExtendedEvent::class, $event);
        self::assertSame('alice', $event->getExtendedById());
        self::assertNull($event->getExtendedBy());

        self::assertSame(ShareView::STATE_REVOKED, $commands->revoke($view->id, 'admin')?->state);
        self::assertNull($commands->revoke('not an id'));

        self::assertTrue($commands->delete($view->id, 'admin'));
        self::assertFalse($commands->delete($view->id, 'admin'));
        self::assertFalse($commands->delete('not an id'));
        $deleted = $this->last(YopassEvents::SHARE_DELETED);
        self::assertInstanceOf(ShareDeletedEvent::class, $deleted);
        self::assertSame('admin', $deleted->getDeletedById());

        $a = $commands->create(new CreatorId('alice'), $this->data());
        $b = $commands->create(new CreatorId('bob'), $this->data());
        self::assertSame(0, $commands->deleteByIds([]));
        self::assertSame(0, $commands->deleteByIds([$b->id], new CreatorId('alice')));
        self::assertSame(2, $commands->deleteByIds([$a->id, $b->id], null, 'admin'));
        $bulk = $this->last(YopassEvents::SHARES_BULK_DELETED);
        self::assertInstanceOf(ShareBulkDeletedEvent::class, $bulk);
        self::assertSame(ShareBulkDeletedEvent::REASON_DELETE_BY_IDS, $bulk->getReason());
        self::assertEqualsCanonicalizing([$a->id, $b->id], $bulk->getShareIds());
        self::assertNull($bulk->getSubjectId());
        self::assertSame('admin', $bulk->getViewerId());

        $commands->create(new CreatorId('carol'), $this->data());
        self::assertSame(1, $commands->deleteAllOf(new CreatorId('carol'), 'carol'));
        self::assertSame(0, $commands->deleteAllOf(new CreatorId('carol')));
        $all = $this->last(YopassEvents::SHARES_BULK_DELETED);
        self::assertInstanceOf(ShareBulkDeletedEvent::class, $all);
        self::assertSame(ShareBulkDeletedEvent::REASON_DELETE_ALL, $all->getReason());
        self::assertSame('carol', $all->getSubjectId());
        self::assertSame([], $all->getShareIds());
    }

    public function testDeleteByIdsAndDeleteAllWithAPlainRepository(): void
    {
        $repository = new InMemoryShareRepository();
        $creator    = new TestUser('alice');
        $share      = new SecureShare('00000000-0000-4000-8000-000000000001', $creator);
        $share->setExpiresAt(new DateTimeImmutable('+1 hour'));
        $repository->add($share);
        $second = new SecureShare('00000000-0000-4000-8000-000000000002', $creator);
        $second->setExpiresAt(new DateTimeImmutable('+1 hour'));
        $repository->add($second);

        $commands = $this->commands($repository);
        self::assertSame(1, $commands->deleteByIds([$share->getId()]));
        self::assertNull($repository->find($share->getId()));

        $resolver = $this->createMock(CreatorReferenceResolverInterface::class);
        $resolver->method('reference')->willReturn($creator);
        self::assertSame(1, $this->commands($repository, $resolver)->deleteAllOf(new CreatorId('alice')));
    }

    public function testExtendingAnUnknownShareIsRefused(): void
    {
        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('not_found');
        $this->commands()->extend('00000000-0000-4000-8000-000000000009', '24h', null);
    }

    public function testACreateIsRefusedBeforeAnythingIsStored(): void
    {
        $data              = $this->data();
        $data->payloadKind = 'file';
        try {
            $this->commands()->create(new CreatorId('alice'), $data);
            self::fail('Refused');
        } catch (ShareCommandException $exception) {
            self::assertSame(ShareCommandException::FILE_NOT_ENABLED, $exception->errorCode);
        }
        self::assertSame([], $this->repository->shares);

        $view = $this->commands(null, null, true)->create(new CreatorId('alice'), $data);
        self::assertSame('file', $view->payloadKind);
    }

    private function commands(?ShareRepositoryInterface $repository = null, ?CreatorReferenceResolverInterface $resolver = null, bool $files = false): ShareCommands
    {
        $repository ??= $this->repository;
        $resolver   ??= new DoctrineCreatorReferenceResolver(TestUser::class, null, null, new CreatorClassPolicy(TestUser::class), CreatorMapping::ID);
        $options      = DefaultShareOptions::get();

        return new ShareCommands(
            $repository,
            new ShareCreator($repository, $options['expiration_options'], null, $this->dispatcher, $resolver),
            new ShareRevoker($repository, $this->dispatcher),
            new ShareExtender($repository, $options, null, $this->dispatcher),
            new ShareDeleter($repository, $this->dispatcher, TestUser::class, null, $resolver),
            new ShareQuery($repository, null, $resolver),
            $options,
            700_000,
            $files,
            new ShareRetentionPurger($repository, $options, null, $this->dispatcher, null, $resolver),
            $this->dispatcher,
            $resolver,
        );
    }

    private function share(string $id, string $creator, string $createdOffset): SecureShare
    {
        $share = new SecureShare($id, new CreatorId($creator));
        $share->setExpiresAt(new DateTimeImmutable('+1 day'));
        (function (DateTimeImmutable $createdAt): void {
            $this->createdAt = $createdAt;
        })->call($share, new DateTimeImmutable($createdOffset));
        $this->repository->persist($share);

        return $share;
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';

        return $data;
    }

    private function last(string $name): ?object
    {
        $found = null;
        foreach ($this->events as $item) {
            if ($item['name'] === $name) {
                $found = $item['event'];
            }
        }

        return $found;
    }
}
