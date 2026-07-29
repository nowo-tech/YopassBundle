<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ShareExtenderTest extends TestCase
{
    public function testExtendExpirationFromCurrentExpiry(): void
    {
        $share = $this->share();
        $share->setExpiresAt(new DateTimeImmutable('2026-07-27 13:00:00', new DateTimeZone('UTC')));

        $repository = $this->repositoryWithShare($share);
        $repository->expects(self::once())->method('persist')->with($share);
        $repository->expects(self::once())->method('flush');

        $clock = new MockClock('2026-07-27 12:00:00');
        (new ShareExtender($repository, DefaultShareOptions::get(), $clock))->extend($share, '24h', null);

        self::assertSame('2026-07-28T13:00:00+00:00', $share->getExpiresAt()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM));
    }

    public function testExtendExpirationFromClockWhenShareAlreadyExpired(): void
    {
        $share = $this->share();
        $share->setExpiresAt(new DateTimeImmutable('2026-07-27 10:00:00', new DateTimeZone('UTC')));

        $repository = $this->repositoryWithShare($share);
        $repository->expects(self::once())->method('persist');
        $repository->expects(self::once())->method('flush');

        $clock = new MockClock('2026-07-27 12:00:00');
        (new ShareExtender($repository, DefaultShareOptions::get(), $clock))->extend($share, '1h', null);

        self::assertSame('2026-07-27T13:00:00+00:00', $share->getExpiresAt()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM));
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

    public function testExtendRejectsUnknownExpirationOption(): void
    {
        $share = $this->share();

        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('invalid_expiration');

        (new ShareExtender($this->repositoryWithShare($share), DefaultShareOptions::get()))->extend($share, '30d', null);
    }

    public function testExtendRejectsExpirationThatDoesNotMoveForward(): void
    {
        $share = $this->share();

        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('expiration_not_extended');

        (new ShareExtender($this->repositoryWithShare($share), [
            'expiration_options' => [['id' => 'noop', 'interval' => '0 seconds']],
            'max_reads_options'  => [1, 3, 10],
        ]))->extend($share, 'noop', null);
    }

    public function testExtendRejectsInvalidMaxReadsOption(): void
    {
        $share = $this->share();

        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('invalid_max_reads');

        (new ShareExtender($this->repositoryWithShare($share), DefaultShareOptions::get()))->extend($share, null, 99);
    }

    public function testExtendRejectsNonIncreasingMaxReads(): void
    {
        $share = $this->share();
        $share->setMaxReads(3);

        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('max_reads_not_increased');

        (new ShareExtender($this->repositoryWithShare($share), DefaultShareOptions::get()))->extend($share, null, 3);
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
