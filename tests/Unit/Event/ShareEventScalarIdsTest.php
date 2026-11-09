<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\ShareExtendedEvent;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Scalar actor ids on the lifecycle events (1.16), next to the unchanged object payloads.
 */
final class ShareEventScalarIdsTest extends TestCase
{
    public function testLifecycleEventsExposeScalarIds(): void
    {
        $creator = new SecurityUserDto(42);
        $actor   = new TestUser('admin-1');
        $share   = new SecureShare('00000000-0000-4000-8000-0000000000a9', $creator);
        $share->setExpiresAt(new DateTimeImmutable('+1 hour'));
        $share->revoke();

        self::assertSame('42', $share->getCreatorId());
        self::assertSame('42', ShareCreatedEvent::fromShare($share)->getCreatorId());

        $revoked = ShareRevokedEvent::fromShare($share, $actor);
        self::assertSame('42', $revoked->getCreatorId());
        self::assertSame('admin-1', $revoked->getRevokedById());
        self::assertNull(ShareRevokedEvent::fromShare($share)->getRevokedById());

        $deleted = ShareDeletedEvent::fromShare($share, $actor);
        self::assertSame('42', $deleted->getCreatorId());
        self::assertSame('admin-1', $deleted->getDeletedById());
        self::assertNull(ShareDeletedEvent::fromShare($share)->getDeletedById());

        $extended = ShareExtendedEvent::fromShare($share, new DateTimeImmutable(), 1, $actor);
        self::assertSame('42', $extended->getCreatorId());
        self::assertSame('admin-1', $extended->getExtendedById());
        self::assertNull(ShareExtendedEvent::fromShare($share, new DateTimeImmutable(), 1)->getExtendedById());

        $bulk = new ShareBulkDeletedEvent('delete_all', $creator, 3, $actor);
        self::assertSame('42', $bulk->getSubjectId());
        self::assertSame('admin-1', $bulk->getViewerId());
        $purge = new ShareBulkDeletedEvent('retention', null, 3);
        self::assertNull($purge->getSubjectId());
        self::assertNull($purge->getViewerId());
        self::assertNull((new ShareBulkDeletedEvent('retention', new stdClass(), 1))->getSubjectId());
    }
}
