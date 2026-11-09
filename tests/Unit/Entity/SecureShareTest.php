<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class SecureShareTest extends TestCase
{
    public function testLifecycleAndAvailability(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', $user);

        self::assertSame('00000000-0000-4000-8000-000000000001', $share->getId());
        self::assertSame($user, $share->getCreator());
        self::assertSame('text', $share->getPayloadKind());

        $expiresAt = new DateTimeImmutable('+2 hours');
        $share
            ->setCiphertext('cipher')
            ->setExpiresAt($expiresAt)
            ->setMaxReads(3)
            ->setPayloadKind('file');

        self::assertSame('cipher', $share->getCiphertext());
        self::assertSame($expiresAt, $share->getExpiresAt());
        self::assertSame(3, $share->getMaxReads());
        self::assertSame(3, $share->getReadsLeft());
        self::assertSame('file', $share->getPayloadKind());
        self::assertInstanceOf(DateTimeImmutable::class, $share->getCreatedAt());
        self::assertTrue($share->isAvailable());
        self::assertNull($share->getRevokedAt());

        $share->consumeRead();
        self::assertSame(2, $share->getReadsLeft());
        self::assertTrue($share->isAvailable());

        $share->revoke();
        self::assertNotNull($share->getRevokedAt());
        self::assertSame(0, $share->getReadsLeft());
        self::assertFalse($share->isAvailable());
    }

    public function testIsUnavailableWhenExpiredOrConsumed(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000002', new TestUser());
        $share
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('-1 minute'))
            ->setMaxReads(1);

        self::assertFalse($share->isAvailable());

        $share2 = new SecureShare('00000000-0000-4000-8000-000000000003', new TestUser());
        $share2
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);
        $share2->consumeRead();

        self::assertFalse($share2->isAvailable());
    }

    public function testExtendMaxReadsAddsRemainingReads(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000005', new TestUser());
        $share
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(3);
        $share->consumeRead();
        $share->consumeRead();

        $share->extendMaxReads(10);

        self::assertSame(10, $share->getMaxReads());
        self::assertSame(8, $share->getReadsLeft());
    }

    public function testConsumeReadDoesNotGoBelowZero(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000004', new TestUser());
        $share
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);
        $share->consumeRead();
        $share->consumeRead();

        self::assertSame(0, $share->getReadsLeft());
    }

    public function testReconstituteKeepsEveryStoredValue(): void
    {
        $creator   = new TestUser();
        $createdAt = new DateTimeImmutable('2026-01-02 03:04:05.123');
        $expiresAt = new DateTimeImmutable('2026-01-03 03:04:05.456');
        $revokedAt = new DateTimeImmutable('2026-01-02 10:00:00.789');

        $share = SecureShare::reconstitute(
            '00000000-0000-4000-8000-0000000000a1',
            $creator,
            'cipher',
            $createdAt,
            $expiresAt,
            5,
            2,
            $revokedAt,
            'file',
        );

        self::assertSame('00000000-0000-4000-8000-0000000000a1', $share->getId());
        self::assertSame($creator, $share->getCreator());
        self::assertSame('cipher', $share->getCiphertext());
        self::assertSame($createdAt, $share->getCreatedAt());
        self::assertSame($expiresAt, $share->getExpiresAt());
        self::assertSame(5, $share->getMaxReads());
        self::assertSame(2, $share->getReadsLeft());
        self::assertSame($revokedAt, $share->getRevokedAt());
        self::assertSame('file', $share->getPayloadKind());
        self::assertFalse($share->isAvailable());
    }

    public function testReconstitutedExpiredShareIsNotAvailable(): void
    {
        $share = SecureShare::reconstitute(
            '00000000-0000-4000-8000-0000000000a2',
            new TestUser(),
            'cipher',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day'),
            1,
            1,
            null,
            'text',
        );

        self::assertFalse($share->isAvailable());
    }
}
