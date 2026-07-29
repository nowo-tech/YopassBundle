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

    public function testConsumeReturnsPasswordModeWhenCiphertextDeclaresIt(): void
    {
        $share      = $this->share(expiresAt: new DateTimeImmutable('+1 hour'), ciphertext: '{"v":1,"mode":"password"}');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('consumeReadIfAvailable')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->consume($share->getId());

        self::assertSame('password', $result['mode']);
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

    public function testConsumeReportsNotFoundWhenShareDoesNotExist(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('consumeReadIfAvailable')->willReturn(null);
        $repository->method('find')->willReturn(null);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->consume('missing');

        self::assertSame('not_found', $result['status']);
    }

    public function testConsumeReportsRevokedShare(): void
    {
        $share = $this->share(expiresAt: new DateTimeImmutable('+1 hour'));
        $share->revoke();

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('consumeReadIfAvailable')->willReturn(null);
        $repository->method('find')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->consume($share->getId());

        self::assertSame('revoked', $result['status']);
    }

    public function testConsumeReportsConsumedWhenReadsAreExhausted(): void
    {
        $share = $this->share(expiresAt: new DateTimeImmutable('+1 hour'));
        $share->consumeRead();

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('consumeReadIfAvailable')->willReturn(null);
        $repository->method('find')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->consume($share->getId());

        self::assertSame('consumed', $result['status']);
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

    public function testAvailabilityReportsRevokedAndConsumedStates(): void
    {
        $revoked = $this->share(expiresAt: new DateTimeImmutable('2026-07-27 15:00:00'));
        $revoked->revoke();

        $consumed = $this->share(expiresAt: new DateTimeImmutable('2026-07-27 15:00:00'));
        $consumed->consumeRead();

        $retriever = new ShareRetriever(
            $this->createMock(ShareRepositoryInterface::class),
            new MockClock('2026-07-27 12:00:00'),
        );

        self::assertSame('revoked', $retriever->availability($revoked));
        self::assertSame('consumed', $retriever->availability($consumed));
    }

    public function testPreviewIncludesAvailability(): void
    {
        $share      = $this->share(expiresAt: new DateTimeImmutable('2026-07-27 15:00:00'), ciphertext: '{"v":1,"mode":"password"}');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('find')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->preview($share->getId());

        self::assertSame('ok', $result['status']);
        self::assertSame('active', $result['availability']);
        self::assertSame('password', $result['mode']);
        self::assertSame('text', $result['payloadKind']);
        self::assertSame(1, $result['maxReads']);
        self::assertSame(1, $result['readsLeft']);
        self::assertSame('2026-07-27T15:00:00+00:00', $result['expiresAt']);
        self::assertTrue($result['extendable']);
    }

    public function testPreviewReturnsNotFoundWhenMissing(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('find')->willReturn(null);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->preview('missing');

        self::assertSame(['status' => 'not_found'], $result);
    }

    public function testPreviewMarksRevokedShareAsNotExtendable(): void
    {
        $share = $this->share(expiresAt: new DateTimeImmutable('2026-07-27 15:00:00'));
        $share->revoke();

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('find')->willReturn($share);

        $result = (new ShareRetriever($repository, new MockClock('2026-07-27 12:00:00')))->preview($share->getId());

        self::assertFalse($result['extendable']);
    }

    private function share(DateTimeImmutable $expiresAt, string $ciphertext = 'cipher'): SecureShare
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000040', new TestUser());
        $share
            ->setCiphertext($ciphertext)
            ->setExpiresAt($expiresAt)
            ->setMaxReads(1);

        return $share;
    }
}
