<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ShareRetrieverTest extends TestCase
{
    public function testConsumeReturnsOkWhenRepositoryConsumes(): void
    {
        $share      = $this->share(expiresAt: new DateTimeImmutable('+1 hour'));
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('consumeReadIfAvailable')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->consume($share->getId());

        self::assertSame('ok', $result['status']);
        self::assertSame('cipher', $result['ciphertext']);
    }

    public function testConsumeReportsExpiredUsingClock(): void
    {
        $share      = $this->share(expiresAt: new DateTimeImmutable('2026-07-27 11:00:00'));
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('consumeReadIfAvailable')->willReturn(null);
        $repository->method('find')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->consume($share->getId());

        self::assertSame('expired', $result['status']);
    }

    public function testAvailabilityReportsExpiredUsingClock(): void
    {
        $share     = $this->share(expiresAt: new DateTimeImmutable('2026-07-27 11:00:00'));
        $retriever = new ShareRetriever(
            $this->createMock(ShareRepositoryInterface::class),
            new MockClock('2026-07-27 12:00:00'),
        );

        self::assertSame('expired', $retriever->availability($share));
    }

    public function testPreviewIncludesAvailability(): void
    {
        $share      = $this->share(expiresAt: new DateTimeImmutable('2026-07-27 15:00:00'));
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('find')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->preview($share->getId());

        self::assertSame('ok', $result['status']);
        self::assertSame('active', $result['availability']);
    }

    private function share(DateTimeImmutable $expiresAt): SecureShare
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000040', new TestUser());
        $share
            ->setCiphertext('cipher')
            ->setExpiresAt($expiresAt)
            ->setMaxReads(1);

        return $share;
    }
}
