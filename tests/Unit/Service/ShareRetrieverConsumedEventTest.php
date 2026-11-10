<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareConsumedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Service\ShareConsumedNotifier;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\InMemoryShareRepository;
use Nowo\YopassBundle\Tests\Support\SecretCapturingLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use const JSON_THROW_ON_ERROR;

/**
 * YopassEvents::SHARE_CONSUMED (1.17): ids and counters after a stored read, nothing for a refused read.
 */
final class ShareRetrieverConsumedEventTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-0000000000c1';

    public function testDispatchesAfterEachStoredRead(): void
    {
        $repository = $this->repository(maxReads: 2);
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_CONSUMED, static function (ShareConsumedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $retriever = new ShareRetriever($repository, new MockClock('2026-11-10 12:00:00'), new ShareConsumedNotifier($dispatcher));

        self::assertSame('ok', $retriever->consume(self::ID)['status']);
        self::assertSame('ok', $retriever->consume(self::ID)['status']);
        self::assertSame('consumed', $retriever->consume(self::ID)['status']);
        self::assertSame('not_found', $retriever->consume('missing')['status']);

        self::assertCount(2, $events);
        self::assertSame(self::ID, $events[0]->getShareId());
        self::assertSame('creator-1', $events[0]->getCreatorId());
        self::assertSame(1, $events[0]->getReadNumber());
        self::assertSame(1, $events[0]->getReadsLeft());
        self::assertSame(2, $events[0]->getMaxReads());
        self::assertSame('text', $events[0]->getPayloadKind());
        self::assertSame('2026-11-10 12:00:00', $events[0]->getConsumedAt()->format('Y-m-d H:i:s'));
        self::assertSame(2, $events[1]->getReadNumber());
        self::assertSame(0, $events[1]->getReadsLeft());
    }

    public function testWithoutDispatcherTheResultIsUnchanged(): void
    {
        $result = (new ShareRetriever($this->repository(maxReads: 1)))->consume(self::ID);

        self::assertSame('ok', $result['status']);
        self::assertSame('{"v":1,"mode":"key","box":"x"}', $result['ciphertext']);
    }

    public function testAThrowingListenerIsLoggedAndTheReaderStillGetsTheSecret(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_CONSUMED, static function (): never {
            throw new RuntimeException('audit store down');
        });
        $logger    = new SecretCapturingLogger();
        $retriever = new ShareRetriever($this->repository(maxReads: 1), null, new ShareConsumedNotifier($dispatcher, $logger));

        $result = $retriever->consume(self::ID);

        self::assertSame('ok', $result['status']);
        self::assertSame('{"v":1,"mode":"key","box":"x"}', $result['ciphertext']);
        self::assertNotSame([], $logger->records);
        $encoded = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringContainsString(self::ID, $encoded);
        self::assertStringContainsString(RuntimeException::class, str_replace('\\\\', '\\', $encoded));
        self::assertStringNotContainsString('box', $encoded);
        self::assertStringNotContainsString('audit store down', $encoded);
    }

    public function testNotifierWithoutDispatcherDoesNothing(): void
    {
        $result = (new ShareRetriever($this->repository(maxReads: 1), null, new ShareConsumedNotifier()))->consume(self::ID);

        self::assertSame('ok', $result['status']);
    }

    public function testThrowingListenerWithoutLoggerIsIgnored(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_CONSUMED, static function (): never {
            throw new RuntimeException('boom');
        });

        self::assertSame('ok', (new ShareRetriever($this->repository(maxReads: 1), null, new ShareConsumedNotifier($dispatcher)))->consume(self::ID)['status']);
    }

    public function testEventCarriesNoSecretMaterialAndNoEntity(): void
    {
        $reflection = new ReflectionClass(ShareConsumedEvent::class);
        foreach ($reflection->getProperties() as $property) {
            $type = (string) $property->getType();
            self::assertNotSame(SecureShare::class, $type);
            self::assertNotSame('object', $type);
            self::assertDoesNotMatchRegularExpression('/cipher|key|password|ip|agent/i', $property->getName());
        }
    }

    private function repository(int $maxReads): InMemoryShareRepository
    {
        $share = new SecureShare(self::ID, new TestUser('creator-1'));
        $share
            ->setCiphertext('{"v":1,"mode":"key","box":"x"}')
            ->setExpiresAt(new DateTimeImmutable('2099-01-01 00:00:00'))
            ->setMaxReads($maxReads);

        $repository = new InMemoryShareRepository();
        $repository->add($share);

        return $repository;
    }
}
