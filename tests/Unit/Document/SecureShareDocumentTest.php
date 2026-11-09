<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Document;

use DateTimeImmutable;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Entity\SecureShare;
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

    public function testFromShareAndToShareRoundTripEveryField(): void
    {
        $creator = new TestUser();
        $share   = SecureShare::reconstitute(
            '00000000-0000-4000-8000-0000000000b1',
            $creator,
            'cipher',
            new DateTimeImmutable('2026-01-02 03:04:05.123'),
            new DateTimeImmutable('2026-01-03 03:04:05.456'),
            4,
            1,
            new DateTimeImmutable('2026-01-02 09:00:00.789'),
            'file',
        );

        $document = SecureShareDocument::fromShare($share);

        self::assertSame($share->getCreatedAt(), $document->getCreatedAt());
        self::assertSame($share->getRevokedAt(), $document->getRevokedAt());
        self::assertSame(1, $document->getReadsLeft());

        $loaded = $document->toShare();

        self::assertSame($share->getId(), $loaded->getId());
        self::assertSame($creator, $loaded->getCreator());
        self::assertSame('cipher', $loaded->getCiphertext());
        self::assertSame($share->getCreatedAt(), $loaded->getCreatedAt());
        self::assertSame($share->getExpiresAt(), $loaded->getExpiresAt());
        self::assertSame(4, $loaded->getMaxReads());
        self::assertSame(1, $loaded->getReadsLeft());
        self::assertSame($share->getRevokedAt(), $loaded->getRevokedAt());
        self::assertSame('file', $loaded->getPayloadKind());
    }

    public function testSyncFromKeepsConsumedReadsInsteadOfResettingThem(): void
    {
        $document = new SecureShareDocument('00000000-0000-4000-8000-0000000000b2', new TestUser());
        $document->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('-1 minute'))->setMaxReads(1);
        $document->consumeRead();

        $share = $document->toShare();
        $share->extendExpiration(new DateTimeImmutable('+1 hour'));
        $document->syncFrom($share);

        self::assertSame(0, $document->getReadsLeft());
        self::assertFalse($document->toShare()->isAvailable());
    }

    public function testSyncFromNeverClearsOrRestampsRevocation(): void
    {
        $document = new SecureShareDocument('00000000-0000-4000-8000-0000000000b3', new TestUser());
        $document->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);
        $stale = $document->toShare();

        $document->revoke();
        $revokedAt = $document->getRevokedAt();

        $document->syncFrom($stale);

        self::assertSame($revokedAt, $document->getRevokedAt());
        self::assertSame(0, $document->getReadsLeft());

        $revokedLater = $document->toShare();
        $revokedLater->revoke();
        $document->syncFrom($revokedLater);

        self::assertSame($revokedAt, $document->getRevokedAt());
    }

    public function testSyncFromCopiesRevocationDateOfTheShare(): void
    {
        $document = new SecureShareDocument('00000000-0000-4000-8000-0000000000b4', new TestUser());
        $document->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);
        $share = $document->toShare();
        $share->revoke();

        $document->syncFrom($share);

        self::assertSame($share->getRevokedAt(), $document->getRevokedAt());
        self::assertSame(0, $document->getReadsLeft());
    }
}
