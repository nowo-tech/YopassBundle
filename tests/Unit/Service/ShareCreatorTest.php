<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ShareCreatorTest extends TestCase
{
    public function testCreatePersistsEncryptedShare(): void
    {
        $creator           = new TestUser();
        $data              = new ShareCreateData();
        $data->ciphertext  = '{"v":1,"mode":"key","box":"abc"}';
        $data->expiresIn   = '24h';
        $data->maxReads    = 2;
        $data->payloadKind = 'file';

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('persist')->with(self::isInstanceOf(SecureShare::class));
        $repository->expects(self::once())->method('flush');

        $share = (new ShareCreator($repository, DefaultShareOptions::get()['expiration_options']))->create($creator, $data);

        self::assertSame($creator, $share->getCreator());
        self::assertSame($data->ciphertext, $share->getCiphertext());
        self::assertSame(2, $share->getMaxReads());
        self::assertSame('file', $share->getPayloadKind());
    }

    public function testCreateComputesExpirationFromInjectedClock(): void
    {
        $data            = new ShareCreateData();
        $data->expiresIn = '1h';

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $clock      = new MockClock('2026-03-01 08:00:00');

        $share = (new ShareCreator($repository, DefaultShareOptions::get()['expiration_options'], $clock))->create(new TestUser(), $data);

        self::assertSame('2026-03-01 09:00:00', $share->getExpiresAt()->format('Y-m-d H:i:s'));
    }
}
