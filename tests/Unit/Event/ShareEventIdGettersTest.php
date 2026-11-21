<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareConsumedEvent;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\ShareDeleteAllEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\ShareExtendedEvent;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Security\UserRefSecurityUser;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * 2.1.0 (DDD-host checklist B4/B8): actor ids without a security user, `occurredAt`, the id-based delete-all subject
 * and the id getters of the access check.
 */
final class ShareEventIdGettersTest extends TestCase
{
    public function testLifecycleEventsCarryActorIdsAndOccurredAt(): void
    {
        $share = $this->share();
        $at    = new DateTimeImmutable('2026-11-21 12:00:00');

        $created = ShareCreatedEvent::fromShare($share, null, $at);
        self::assertSame($at, $created->getOccurredAt());
        self::assertSame($share->getCreatedAt(), ShareCreatedEvent::fromShare($share)->getOccurredAt());

        $deleted = ShareDeletedEvent::fromShare($share, null, null, 'admin', $at);
        self::assertSame('admin', $deleted->getDeletedById());
        self::assertNull($deleted->getDeletedBy());
        self::assertSame($at, $deleted->getOccurredAt());
        self::assertSame('7', ShareDeletedEvent::fromShare($share, new TestUser('7'))->getDeletedById());

        $share->revoke();
        $revoked = ShareRevokedEvent::fromShare($share, null, null, 'admin');
        self::assertSame('admin', $revoked->getRevokedById());
        self::assertSame($share->getRevokedAt(), $revoked->getOccurredAt());
        self::assertSame('7', ShareRevokedEvent::fromShare($share, new TestUser('7'))->getRevokedById());

        $extended = ShareExtendedEvent::fromShare($share, $at, 1, null, null, 'owner', $at);
        self::assertSame('owner', $extended->getExtendedById());
        self::assertSame($at, $extended->getOccurredAt());
        self::assertSame('7', ShareExtendedEvent::fromShare($share, $at, 1, new TestUser('7'))->getExtendedById());

        $consumed = ShareConsumedEvent::fromShare($share, $at);
        self::assertSame($at, $consumed->getOccurredAt());
    }

    public function testTheBulkEventCarriesTheViewerIdAndTheIdsOfAnIdDelete(): void
    {
        $at   = new DateTimeImmutable('2026-11-21 12:00:00');
        $bulk = new ShareBulkDeletedEvent(ShareBulkDeletedEvent::REASON_DELETE_BY_IDS, new CreatorId('alice'), 2, null, 'admin', $at, ['a', 'b']);

        self::assertSame('admin', $bulk->getViewerId());
        self::assertSame('alice', $bulk->getSubjectId());
        self::assertSame(['a', 'b'], $bulk->getShareIds());
        self::assertSame($at, $bulk->getOccurredAt());

        $legacy = new ShareBulkDeletedEvent(ShareBulkDeletedEvent::REASON_RETENTION, null, 1, new TestUser('9'));
        self::assertSame('9', $legacy->getViewerId());
        self::assertSame([], $legacy->getShareIds());
        self::assertNull($legacy->getSubjectId());
    }

    public function testTheDeleteAllSubjectCanBeSetById(): void
    {
        $viewer = new TestUser('7');
        $event  = new ShareDeleteAllEvent($viewer, $viewer);

        self::assertSame('7', $event->getViewerId());
        self::assertSame('7', $event->getSubjectId());
        $event->setSubjectId('8');
        self::assertSame('8', $event->getSubjectId());
        self::assertInstanceOf(CreatorId::class, $event->getSubject());

        $this->expectException(InvalidArgumentException::class);
        $event->setSubjectId('8 ');
    }

    public function testTheAccessCheckExposesIds(): void
    {
        $share = $this->share();
        $event = new ShareAccessCheckEvent(new TestUser('7'), $share, ShareAccessAction::View, true);
        self::assertSame('7', $event->getUserId());
        self::assertNull($event->getUserRef());
        self::assertSame($share->getId(), $event->getShareId());
        self::assertSame('alice', $event->getCreatorId());

        $ref   = new UserRef('42', 'ana@example.com', ['ROLE_USER']);
        $byRef = new ShareAccessCheckEvent(new UserRefSecurityUser($ref), $share, ShareAccessAction::View, false, $ref);
        self::assertSame('42', $byRef->getUserId());
        self::assertSame($ref, $byRef->getUserRef());

        $identifierOnly = new UserRef('ana@example.com', 'ana@example.com', [], true);
        self::assertNull((new ShareAccessCheckEvent(new UserRefSecurityUser($identifierOnly), $share, ShareAccessAction::View, false, $identifierOnly))->getUserId());
    }

    public function testTheStandInSecurityUserCarriesOnlyTheReference(): void
    {
        $user = new UserRefSecurityUser(new UserRef('42', 'ana@example.com', ['ROLE_USER']));
        self::assertSame('42', $user->getId());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame('ana@example.com', $user->getUserIdentifier());
        $user->eraseCredentials();

        $bare = new UserRefSecurityUser(new UserRef('42'));
        self::assertSame('42', $bare->getUserIdentifier());
        self::assertNull((new UserRefSecurityUser(new UserRef('ana', 'ana', [], true)))->getId());
    }

    private function share(): SecureShare
    {
        return (new SecureShare('00000000-0000-4000-8000-000000000001', new CreatorId('alice')))
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setCiphertext('cipher');
    }
}
