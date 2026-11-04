<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\ShareDeleteAllEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

use function is_object;
use function is_scalar;
use function print_r;
use function str_contains;
use function strtolower;

/**
 * Zero-knowledge guarantee of the share lifecycle events: their payload is metadata only. They must never carry the
 * ciphertext, a key, a password or decrypted content, nor expose the SecureShare entity (which holds the ciphertext).
 *
 * Fixtures use placeholders only (no real secret material).
 */
final class ShareLifecycleEventsZeroKnowledgeTest extends TestCase
{
    private const CIPHERTEXT = '{"v":1,"mode":"key","nonce":"PLACEHOLDER-NONCE","ciphertext":"PLACEHOLDER-CIPHERTEXT-NOT-A-SECRET"}';

    /** Property / getter name fragments that would indicate secret material. */
    private const FORBIDDEN_NAMES = ['cipher', 'key', 'password', 'secret', 'plain', 'decrypt', 'payload_data', 'content', 'nonce', 'salt'];

    /**
     * @return iterable<string, array{class-string<Event>}>
     */
    public static function eventClasses(): iterable
    {
        yield 'created' => [ShareCreatedEvent::class];
        yield 'deleted' => [ShareDeletedEvent::class];
        yield 'bulk deleted' => [ShareBulkDeletedEvent::class];
        yield 'delete all' => [ShareDeleteAllEvent::class];
    }

    /**
     * @param class-string<Event> $class
     */
    #[DataProvider('eventClasses')]
    public function testEventShapeHasNoSecretMaterial(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertNotSecretName($property->getName(), $class . '::$' . $property->getName());
            $type  = $property->getType();
            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
            foreach ($types as $named) {
                if ($named instanceof ReflectionNamedType) {
                    self::assertNotSame(SecureShare::class, $named->getName(), $class . '::$' . $property->getName() . ' must not hold the entity');
                }
            }
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || $method->isStatic() || $method->isConstructor()) {
                continue;
            }
            $this->assertNotSecretName($method->getName(), $class . '::' . $method->getName() . '()');
            $return = $method->getReturnType();
            if ($return instanceof ReflectionNamedType) {
                self::assertNotSame(SecureShare::class, $return->getName());
            }
        }
    }

    public function testCreatedAndDeletedPayloadsNeverContainTheCiphertext(): void
    {
        $share = $this->share();

        foreach ([ShareCreatedEvent::fromShare($share), ShareDeletedEvent::fromShare($share, new TestUser('viewer'))] as $event) {
            $this->assertPayloadIsMetadataOnly($event);
            self::assertSame($share->getId(), $event->getShareId());
            self::assertSame($share->getExpiresAt(), $event->getExpiresAt());
            self::assertSame($share->getMaxReads(), $event->getMaxReads());
            self::assertSame('text', $event->getPayloadKind());
            self::assertSame($share->getCreator(), $event->getCreator());
            self::assertSame($share->getCreatedAt(), $event->getCreatedAt());
        }

        $deleted = ShareDeletedEvent::fromShare($share);
        self::assertNull($deleted->getDeletedBy());
        self::assertSame(3, $deleted->getReadsLeft());
        self::assertNull($deleted->getRevokedAt());
    }

    public function testShareCreatorDispatchesMetadataOnlyEvent(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_CREATED, static function (ShareCreatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $data             = new ShareCreateData();
        $data->ciphertext = self::CIPHERTEXT;
        $data->expiresIn  = '1h';
        $data->maxReads   = 1;

        $creator = new TestUser('creator');
        $share   = (new ShareCreator($repository, DefaultShareOptions::get()['expiration_options'], null, $dispatcher))->create($creator, $data);

        self::assertCount(1, $events);
        self::assertSame($share->getId(), $events[0]->getShareId());
        self::assertSame($creator, $events[0]->getCreator());
        $this->assertPayloadIsMetadataOnly($events[0]);
    }

    public function testDeleterEventsNeverContainTheCiphertext(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('removeAllByCreator')->willReturn(2);

        $events     = [];
        $dispatcher = new EventDispatcher();
        foreach ([YopassEvents::SHARE_DELETED, YopassEvents::SHARE_DELETE_ALL, YopassEvents::SHARES_BULK_DELETED] as $name) {
            $dispatcher->addListener($name, static function (Event $event) use (&$events): void {
                $events[] = $event;
            });
        }

        $deleter = new ShareDeleter($repository, $dispatcher);
        $deleter->delete($this->share(), new TestUser('viewer'));
        $deleter->deleteAll(new TestUser('viewer'));

        self::assertCount(3, $events);
        foreach ($events as $event) {
            $this->assertPayloadIsMetadataOnly($event);
        }
    }

    public function testRetentionEventNeverContainsTheCiphertext(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('removeOlderThan')->willReturn(1);

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function (ShareBulkDeletedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $options                         = DefaultShareOptions::get();
        $options['retention']['enabled'] = true;
        $options['retention']['max_age'] = '1 day';
        (new ShareRetentionPurger($repository, $options, null, $dispatcher))->purgeAll();

        self::assertCount(1, $events);
        $this->assertPayloadIsMetadataOnly($events[0]);
    }

    private function share(): SecureShare
    {
        $share = new SecureShare('00000000-0000-4000-8000-0000000000aa', new TestUser('creator'));
        $share->setCiphertext(self::CIPHERTEXT)->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);

        return $share;
    }

    private function assertNotSecretName(string $name, string $where): void
    {
        $lower = strtolower($name);
        foreach (self::FORBIDDEN_NAMES as $fragment) {
            self::assertFalse(str_contains($lower, $fragment), $where . ' looks like secret material ("' . $fragment . '")');
        }
    }

    /**
     * Walks every property value of the event (one level into objects other than the creator user stub) and checks
     * that no value is the SecureShare entity or contains the ciphertext placeholder.
     */
    private function assertPayloadIsMetadataOnly(object $event): void
    {
        self::assertFalse(str_contains(print_r($event, true), 'PLACEHOLDER'), $event::class . ' dump contains secret material');

        foreach ((new ReflectionClass($event))->getProperties() as $property) {
            $value = $property->getValue($event);
            self::assertNotInstanceOf(SecureShare::class, $value);
            if (is_scalar($value)) {
                self::assertFalse(str_contains((string) $value, 'PLACEHOLDER'));
            } elseif (is_object($value)) {
                self::assertFalse(str_contains(print_r($value, true), 'PLACEHOLDER'));
            }
        }
    }
}
