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
        $document = new SecureShareDocument('00000000-0000-4000-8000-000000000001', new TestUser());
        $document
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(2);

        self::assertInstanceOf(DateTimeImmutable::class, $document->getCreatedAt());
        $document->consumeRead();
        self::assertSame(1, $document->getReadsLeft());
        $document->revoke();
        self::assertSame(0, $document->getReadsLeft());
    }
}
