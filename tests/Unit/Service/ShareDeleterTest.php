<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareDeleteAllEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\SecretCapturingLogger;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ShareDeleterTest extends TestCase
{
    public function testDeleteWithoutDispatcherRemovesAndFlushes(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-0000000000b1', new TestUser());
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('remove')->with($share);
        $repository->expects(self::once())->method('flush');

        (new ShareDeleter($repository))->delete($share);
    }

    public function testDeleteAllDefaultsToTheViewerAndDispatchesBulkEvent(): void
    {
        $viewer     = new TestUser('viewer');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('removeAllByCreator')->with($viewer)->willReturn(3);
        $repository->expects(self::once())->method('flush');

        $bulk       = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function (ShareBulkDeletedEvent $event) use (&$bulk): void {
            $bulk[] = $event;
        });

        self::assertSame(3, (new ShareDeleter($repository, $dispatcher))->deleteAll($viewer));
        self::assertCount(1, $bulk);
        self::assertSame(ShareBulkDeletedEvent::REASON_DELETE_ALL, $bulk[0]->getReason());
        self::assertSame($viewer, $bulk[0]->getSubject());
        self::assertSame($viewer, $bulk[0]->getViewer());
        self::assertSame(3, $bulk[0]->getRemoved());
    }

    public function testDeleteAllCanBeNarrowedToAnotherUser(): void
    {
        $viewer     = new TestUser('viewer');
        $other      = new TestUser('other');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('removeAllByCreator')->with($other)->willReturn(0);
        $repository->expects(self::never())->method('flush');

        $bulk       = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event) use ($other, $viewer): void {
            self::assertSame($viewer, $event->getViewer());
            self::assertSame($viewer, $event->getSubject());
            $event->setSubject($other);
        });
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function () use (&$bulk): void {
            ++$bulk;
        });

        self::assertSame(0, (new ShareDeleter($repository, $dispatcher))->deleteAll($viewer));
        self::assertSame(0, $bulk, 'no bulk event when nothing was removed');
    }

    public function testDeleteAllRefusesANonUserSubject(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeAllByCreator');
        $repository->expects(self::never())->method('flush');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event): void {
            $event->setSubject(new stdClass());
        });

        $this->expectException(LogicException::class);
        (new ShareDeleter($repository, $dispatcher, TestUser::class))->deleteAll(new TestUser());
    }

    public function testDeleteAllRefusesAUserOfAnotherClassWhenTheCreatorClassIsKnown(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeAllByCreator');

        $otherUser  = new InMemoryUser('other', null);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event) use ($otherUser): void {
            $event->setSubject($otherUser);
        });

        $this->expectException(LogicException::class);
        (new ShareDeleter($repository, $dispatcher, TestUser::class))->deleteAll(new TestUser());
    }

    public function testVetoAfterMarkHandledIsIgnoredLoggedAndAudited(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeAllByCreator');

        $bulk       = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event): void {
            $event->markHandled(2);
        }, 10);
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event): void {
            $event->veto();
            self::assertFalse($event->isVetoed());
            self::assertTrue($event->isVetoIgnored());
            self::assertFalse($event->isPropagationStopped());
        });
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function (ShareBulkDeletedEvent $event) use (&$bulk): void {
            $bulk[] = $event;
        });

        $logger = new SecretCapturingLogger();

        self::assertSame(2, (new ShareDeleter($repository, $dispatcher, null, $logger))->deleteAll(new TestUser()));
        self::assertCount(1, $bulk);
        self::assertSame(2, $bulk[0]->getRemoved());
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['removed' => 2], $logger->records[0]['context']);
    }

    public function testDeleteAllVetoRemovesNothing(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeAllByCreator');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event): void {
            self::assertFalse($event->isVetoed());
            $event->veto();
        });
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (): never {
            self::fail('veto stops propagation');
        }, -10);

        self::assertNull((new ShareDeleter($repository, $dispatcher))->deleteAll(new TestUser()));
    }

    public function testDeleteAllHandledByListenerSkipsTheBuiltInQuery(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeAllByCreator');
        $repository->expects(self::never())->method('flush');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event): void {
            self::assertFalse($event->isHandled());
            self::assertSame(0, $event->getHandledCount());
            $event->markHandled(4);
        });

        self::assertSame(4, (new ShareDeleter($repository, $dispatcher))->deleteAll(new TestUser()));
    }

    public function testMarkHandledClampsNegativeCounts(): void
    {
        $event = new ShareDeleteAllEvent(new TestUser(), new stdClass());
        $event->markHandled(-1);

        self::assertTrue($event->isHandled());
        self::assertSame(0, $event->getHandledCount());
    }
}
