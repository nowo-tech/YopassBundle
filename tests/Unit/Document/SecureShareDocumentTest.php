<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Document;

use DateTimeImmutable;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class SecureShareDocumentTest extends TestCase
{
    public function testSupportsReadAndRevoke(): void
    {
        $creator  = new TestUser();
        $document = new SecureShareDocument('00000000-0000-4000-8000-000000000001', $creator);
        $document
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(2);

        self::assertSame('00000000-0000-4000-8000-000000000001', $document->getId());
        self::assertSame($creator, $document->getCreator());
        self::assertSame('cipher', $document->getCiphertext());
        self::assertSame('text', $document->getPayloadKind());
        self::assertSame(2, $document->getMaxReads());
        self::assertInstanceOf(DateTimeImmutable::class, $document->getCreatedAt());
        self::assertNull($document->getRevokedAt());
        $document->consumeRead();
        self::assertSame(1, $document->getReadsLeft());
        $document->revoke();
        self::assertSame(0, $document->getReadsLeft());
        self::assertNotNull($document->getRevokedAt());
    }

    public function testSupportsPayloadKindAndExtensions(): void
    {
        $expiresAt = new DateTimeImmutable('+1 hour');
        $document  = new SecureShareDocument('00000000-0000-4000-8000-000000000002', new TestUser());
        $document
            ->setCiphertext('cipher')
            ->setExpiresAt($expiresAt)
            ->setMaxReads(3)
            ->setPayloadKind('file');

        $document->consumeRead();
        $document->consumeRead();
        $document->extendExpiration($expiresAt->modify('+1 day'));
        $document->extendMaxReads(5);
        $document->consumeRead();
        $document->consumeRead();
        $document->consumeRead();
        $document->consumeRead();

        self::assertSame('file', $document->getPayloadKind());
        self::assertSame(5, $document->getMaxReads());
        self::assertSame(0, $document->getReadsLeft());
        self::assertSame(
            $expiresAt->modify('+1 day')->format(DateTimeImmutable::ATOM),
            $document->getExpiresAt()->format(DateTimeImmutable::ATOM),
        );
    }
}
