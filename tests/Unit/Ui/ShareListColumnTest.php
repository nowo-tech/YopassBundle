<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Ui;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareListColumnsEvent;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Ui\ShareListColumn;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

final class ShareListColumnTest extends TestCase
{
    public function testValuesArePlainText(): void
    {
        $share = $this->share();

        self::assertSame('7', (new ShareListColumn('reads', 'Reads', static fn (): int => 7))->valueFor($share));
        self::assertNull((new ShareListColumn('none', 'None', static fn (): ?string => null))->valueFor($share));
        self::assertNull((new ShareListColumn('object', 'Object', static fn (): object => new stdClass()))->valueFor($share));
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };
        self::assertSame('stringable', (new ShareListColumn('text', 'Text', static fn (): Stringable => $stringable))->valueFor($share));
    }

    public function testRejectsInvalidAndReservedKeys(): void
    {
        foreach (['', '1abc', 'with space', 'a"b', 'status', 'createdAt'] as $key) {
            try {
                new ShareListColumn($key, 'x', static fn (): string => '');
                self::fail('Key accepted: ' . $key);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventCollectsColumnsOnce(): void
    {
        $viewer = new TestUser();
        $event  = new ShareListColumnsEvent($viewer, [$this->share()]);
        $event->addColumn(new ShareListColumn('createdBy', 'Created by', static fn (): string => ''));

        self::assertSame($viewer, $event->getViewer());
        self::assertCount(1, $event->getShares());
        self::assertSame('createdBy', $event->getColumns()[0]->key);

        $this->expectException(InvalidArgumentException::class);
        $event->addColumn(new ShareListColumn('createdBy', 'Again', static fn (): string => ''));
    }

    public function testRevokedEventFallsBackToNowForAnUnrevokedShare(): void
    {
        $event = ShareRevokedEvent::fromShare($this->share());

        self::assertNull($event->getRevokedBy());
        self::assertInstanceOf(DateTimeImmutable::class, $event->getRevokedAt());
        self::assertInstanceOf(TestUser::class, $event->getCreator());
    }

    private function share(): SecureShare
    {
        return (new SecureShare('00000000-0000-4000-8000-0000000000d1', new TestUser()))
            ->setCiphertext('PLACEHOLDER-CT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);
    }
}
