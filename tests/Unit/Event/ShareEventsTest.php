<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\ShareListResultEvent;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ShareEventsTest extends TestCase
{
    public function testShareAccessCheckEventTogglesGrantState(): void
    {
        $user  = new TestUser('viewer');
        $share = $this->createShare($user);
        $event = new ShareAccessCheckEvent($user, $share, ShareAccessAction::Preview, false);

        self::assertSame($user, $event->getUser());
        self::assertSame($share, $event->getShare());
        self::assertSame(ShareAccessAction::Preview, $event->getAction());
        self::assertFalse($event->isGranted());

        $event->grant();
        self::assertTrue($event->isGranted());

        $event->deny();
        self::assertFalse($event->isGranted());
    }

    public function testShareListQueryEventSupportsSubjectAndOverride(): void
    {
        $viewer      = new TestUser('viewer');
        $listSubject = new stdClass();
        $override    = $this->createShare(new TestUser('owner'));
        $event       = new ShareListQueryEvent($viewer, $listSubject);

        self::assertSame($viewer, $event->getViewer());
        self::assertSame($listSubject, $event->getListSubject());
        self::assertFalse($event->hasOverride());
        self::assertSame([], $event->getOverrideShares());
        self::assertSame(0, $event->getOverrideTotal());

        $newSubject = new stdClass();
        $event->setListSubject($newSubject);
        $event->overrideList([$override], 1);

        self::assertSame($newSubject, $event->getListSubject());
        self::assertTrue($event->hasOverride());
        self::assertSame([$override], $event->getOverrideShares());
        self::assertSame(1, $event->getOverrideTotal());
    }

    public function testShareListResultEventExposesMutableSharesAndTotal(): void
    {
        $viewer = new TestUser('viewer');
        $share  = $this->createShare($viewer);
        $event  = new ShareListResultEvent($viewer, [$share], 1);

        self::assertSame($viewer, $event->getViewer());
        self::assertSame([$share], $event->getShares());
        self::assertSame(1, $event->getTotal());

        $replacement = $this->createShare(new TestUser('other'));
        $event->setShares([$replacement]);
        $event->setTotal(7);

        self::assertSame([$replacement], $event->getShares());
        self::assertSame(7, $event->getTotal());
    }

    private function createShare(TestUser $user): SecureShare
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000091', $user);
        $share
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        return $share;
    }
}
