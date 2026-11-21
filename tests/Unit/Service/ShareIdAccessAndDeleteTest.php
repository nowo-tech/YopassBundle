<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareSummaryRepositoryInterface;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Support\CreatorSubjectGuard;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 2.1.0: the id access check without a repository / with an unusable id, the one-winner id delete and the CreatorId
 * subject accepted by the creator queries.
 */
final class ShareIdAccessAndDeleteTest extends TestCase
{
    public function testTheIdCheckDeniesWithoutARepositoryOrAUsableId(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('find');

        self::assertFalse((new ShareAccessGuard(new EventDispatcher()))->canManageId(new UserRef('alice'), 's-1', ShareAccessAction::View));
        self::assertFalse((new ShareAccessGuard(new EventDispatcher(), null, $repository))->canManageId(new UserRef('alice'), 'not an id', ShareAccessAction::View));
    }

    public function testOnlyTheDeleteThatRemovedTheRowReportsIt(): void
    {
        $share      = (new SecureShare('00000000-0000-4000-8000-000000000001', new CreatorId('alice')))->setExpiresAt(new DateTimeImmutable('+1 hour'));
        $repository = $this->createMockForIntersectionOfInterfaces([ShareRepositoryInterface::class, ShareSummaryRepositoryInterface::class]);
        $repository->method('find')->willReturn($share);
        $repository->method('removeByIds')->willReturnOnConsecutiveCalls(1, 0);
        $dispatched = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETED, static function () use (&$dispatched): void {
            ++$dispatched;
        });
        $deleter = new ShareDeleter($repository, $dispatcher);

        self::assertTrue($deleter->deleteById($share->getId(), 'alice'));
        self::assertFalse($deleter->deleteById($share->getId(), 'alice'));
        self::assertFalse($deleter->deleteById('00000000-0000-4000-8000-000000000002'), 'Another id than the one found');
        self::assertSame(1, $dispatched);
    }

    public function testACreatorIdIsAnAcceptedSubject(): void
    {
        $viewer = new TestUser('7');

        self::assertTrue(CreatorSubjectGuard::isSupported(new CreatorId('8'), $viewer, TestUser::class));
        self::assertFalse(CreatorSubjectGuard::isSupported(new stdClass(), $viewer, TestUser::class));
    }
}
