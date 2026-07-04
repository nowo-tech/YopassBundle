<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\TestCase;

final class ShareExtenderTest extends TestCase
{
    public function testExtendExpirationFromCurrentExpiry(): void
    {
        $share = $this->share();
        $share->setExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->repositoryWithShare($share);
        $repository->expects(self::once())->method('persist')->with($share);
        $repository->expects(self::once())->method('flush');

        (new ShareExtender($repository, DefaultShareOptions::get()))->extend($share, '24h', null);

        self::assertGreaterThan(new DateTimeImmutable('+23 hours'), $share->getExpiresAt());
    }

    public function testExtendMaxReadsAddsRemainingReads(): void
    {
        $share = $this->share();
        $share->setMaxReads(3);
        $share->consumeRead();
        $share->consumeRead();

        $repository = $this->repositoryWithShare($share);
        $repository->expects(self::once())->method('persist');
        $repository->expects(self::once())->method('flush');

        (new ShareExtender($repository, DefaultShareOptions::get()))->extend($share, null, 10);

        self::assertSame(10, $share->getMaxReads());
        self::assertSame(8, $share->getReadsLeft());
    }

    public function testExtendRejectsRevokedShare(): void
    {
        $share = $this->share();
        $share->revoke();

        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('revoked');

        (new ShareExtender($this->repositoryWithShare($share), DefaultShareOptions::get()))->extend($share, '1h', null);
    }

    public function testExtendRequiresAtLeastOneChange(): void
    {
        $share = $this->share();

        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('nothing_to_extend');

        (new ShareExtender($this->repositoryWithShare($share), DefaultShareOptions::get()))->extend($share, null, null);
    }

    private function share(): SecureShare
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000030', new TestUser());
        $share
            ->setCiphertext('cipher')
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
