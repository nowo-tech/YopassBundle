<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\InMemoryShareRepository;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end consume flow without HTTP kernel (repository → retriever).
 */
final class ShareConsumeFlowIntegrationTest extends TestCase
{
    public function testSingleReadShareIsConsumedOnce(): void
    {
        $user  = new TestUser('creator');
        $share = new SecureShare('00000000-0000-4000-8000-000000000020', $user);
        $share
            ->setCiphertext('{"v":1,"mode":"key","box":"secret"}')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $repository = new InMemoryShareRepository();
        $repository->add($share);
        $retriever = new ShareRetriever($repository);

        $first  = $retriever->consume($share->getId());
        $second = $retriever->consume($share->getId());

        self::assertSame('ok', $first['status']);
        self::assertSame('consumed', $second['status']);
    }
}
