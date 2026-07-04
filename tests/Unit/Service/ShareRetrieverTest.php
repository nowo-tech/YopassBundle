<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class ShareRetrieverTest extends TestCase
{
    public function testPreviewReturnsCiphertextWithoutConsumingRead(): void
    {
        $share = $this->availableShare('{"v":1,"mode":"key","box":"abc"}');

        $repository = $this->repositoryWithShare($share);
        $repository->expects(self::never())->method('persist');
        $repository->expects(self::never())->method('flush');

        $result = (new ShareRetriever($repository))->preview($share->getId());

        self::assertSame('ok', $result['status']);
        self::assertSame('active', $result['availability']);
        self::assertSame('{"v":1,"mode":"key","box":"abc"}', $result['ciphertext']);
        self::assertSame('key', $result['mode']);
        self::assertSame(1, $share->getReadsLeft());
    }

    public function testPreviewReportsRevokedAvailability(): void
    {
        $share = $this->availableShare('cipher');
        $share->revoke();

        $result = (new ShareRetriever($this->repositoryWithShare($share)))->preview($share->getId());

        self::assertSame('ok', $result['status']);
        self::assertSame('revoked', $result['availability']);
        self::assertSame('cipher', $result['ciphertext']);
    }

    public function testConsumeReturnsCiphertextWithoutDecrypting(): void
    {
        $share = $this->availableShare('{"v":1,"mode":"key","box":"abc"}');

        $repository = $this->repositoryWithShare($share);
        $repository->expects(self::once())->method('persist')->with($share);
        $repository->expects(self::once())->method('flush');

        $result = (new ShareRetriever($repository))->consume($share->getId());

        self::assertSame('ok', $result['status']);
        self::assertSame('{"v":1,"mode":"key","box":"abc"}', $result['ciphertext']);
        self::assertSame('key', $result['mode']);
        self::assertSame(0, $share->getReadsLeft());
    }

    public function testConsumeReturnsNotFound(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('find')->willReturn(null);

        $result = (new ShareRetriever($repository))->consume('missing');

        self::assertSame(['status' => 'not_found'], $result);
    }

    public function testConsumeReturnsRevoked(): void
    {
        $share = $this->availableShare('cipher');
        $share->revoke();

        $result = (new ShareRetriever($this->repositoryWithShare($share)))->consume($share->getId());

        self::assertSame(['status' => 'revoked'], $result);
    }

    public function testConsumeReturnsExpired(): void
    {
        $share = $this->availableShare('cipher');
        $share->setExpiresAt(new DateTimeImmutable('-1 minute'));

        $result = (new ShareRetriever($this->repositoryWithShare($share)))->consume($share->getId());

        self::assertSame(['status' => 'expired'], $result);
    }

    public function testConsumeReturnsConsumed(): void
    {
        $share = $this->availableShare('cipher');
        $share->consumeRead();

        $result = (new ShareRetriever($this->repositoryWithShare($share)))->consume($share->getId());

        self::assertSame(['status' => 'consumed'], $result);
    }

    public function testResolveModeUsesPasswordWhenPresent(): void
    {
        $share = $this->availableShare('{"v":1,"mode":"password","box":"abc"}');

        $result = (new ShareRetriever($this->repositoryWithShare($share)))->consume($share->getId());

        self::assertSame('password', $result['mode']);
    }

    public function testResolveModeFallsBackForLegacyPayload(): void
    {
        $share = $this->availableShare('legacy-box-not-json');

        $result = (new ShareRetriever($this->repositoryWithShare($share)))->consume($share->getId());

        self::assertSame('key', $result['mode']);
    }

    public function testResolveModeFallsBackWhenVersionMismatch(): void
    {
        $share = $this->availableShare('{"v":2,"mode":"password","box":"abc"}');

        $result = (new ShareRetriever($this->repositoryWithShare($share)))->consume($share->getId());

        self::assertSame('key', $result['mode']);
    }

    private function availableShare(string $ciphertext): SecureShare
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', new TestUser());
        $share
            ->setCiphertext($ciphertext)
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        return $share;
    }

    private function repositoryWithShare(SecureShare $share): ShareRepositoryInterface
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('find')->willReturn($share);

        return $repository;
    }
}
