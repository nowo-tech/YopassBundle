<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\InMemoryShareRepository;
use PHPUnit\Framework\TestCase;

final class ShareRetrieverTest extends TestCase
{
    public function testConsumeReturnsCiphertextAndDecrementsReads(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000010', $user);
        $share
            ->setCiphertext('{"v":1,"mode":"key","box":"abc"}')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(2);

        $repository = new InMemoryShareRepository();
        $repository->add($share);

        $retriever = new ShareRetriever($repository);
        $result    = $retriever->consume($share->getId());

        self::assertSame('ok', $result['status']);
        self::assertSame('{"v":1,"mode":"key","box":"abc"}', $result['ciphertext']);
        self::assertSame(1, $repository->find($share->getId())?->getReadsLeft());
    }

    public function testConsumeReturnsConsumedWhenReadsExhausted(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000011', $user);
        $share
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);
        $share->consumeRead();

        $repository = new InMemoryShareRepository();
        $repository->add($share);

        $result = (new ShareRetriever($repository))->consume($share->getId());

        self::assertSame('consumed', $result['status']);
    }

    public function testConsumeReturnsRevokedStatus(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000012', $user);
        $share
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);
        $share->revoke();

        $repository = new InMemoryShareRepository();
        $repository->add($share);

        $result = (new ShareRetriever($repository))->consume($share->getId());

        self::assertSame('revoked', $result['status']);
    }
}
