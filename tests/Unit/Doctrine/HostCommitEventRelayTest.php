<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Doctrine\HostCommitEventRelay;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerConnection;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerDriver;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Tests\Support\SecretCapturingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use function json_encode;

/**
 * 2.1.0 (REQ-ARCH-001 rule 7): lifecycle events wait for the application's outermost commit.
 */
final class HostCommitEventRelayTest extends TestCase
{
    private int $level = 0;

    private bool $failLevel = false;

    /** @var list<string> */
    private array $seen = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        foreach ([YopassEvents::SHARE_CREATED, YopassEvents::SHARE_DELETED, YopassEvents::SHARE_DELETE_ALL] as $name) {
            $this->dispatcher->addListener($name, function () use ($name): void {
                $this->seen[] = $name;
            });
        }
    }

    public function testWithoutATransactionEveryEventIsDispatchedAtOnce(): void
    {
        $relay = $this->relay();
        $event = new stdClass();

        self::assertSame($event, $relay->dispatch($event, YopassEvents::SHARE_CREATED));
        self::assertSame([YopassEvents::SHARE_CREATED], $this->seen);
        self::assertSame([KernelEvents::RESPONSE => ['onResponse', -1024]], HostCommitEventRelay::getSubscribedEvents());
    }

    public function testPreActionEventsAndADisabledRelayPassStraightThrough(): void
    {
        $this->level = 1;
        $this->relay()->dispatch(new stdClass(), YopassEvents::SHARE_DELETE_ALL);
        $this->relay(false)->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);

        self::assertSame([YopassEvents::SHARE_DELETE_ALL, YopassEvents::SHARE_CREATED], $this->seen);
    }

    public function testHeldEventsAreReleasedByTheCommitAndDispatchedWhenIdle(): void
    {
        $relay       = $this->relay();
        $this->level = 1;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        self::assertSame([], $this->seen);
        self::assertSame(['held' => 1, 'released' => 0], $relay->pending());

        $relay->connectionCommitted();
        self::assertSame(['held' => 0, 'released' => 1], $relay->pending());
        // Still inside a (new) transaction: not yet.
        $relay->onResponse($this->response(HttpKernelInterface::MAIN_REQUEST));
        self::assertSame([], $this->seen);

        $this->level = 0;
        $relay->onResponse($this->response(HttpKernelInterface::SUB_REQUEST));
        self::assertSame([], $this->seen, 'Only the main response releases');
        $relay->onResponse($this->response(HttpKernelInterface::MAIN_REQUEST));
        self::assertSame([YopassEvents::SHARE_CREATED], $this->seen);
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());

        $relay->connectionCommitted();
        $relay->connectionRolledBack(1);
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());
    }

    public function testRollbacksDropTheEventsOfTheirLevel(): void
    {
        $relay       = $this->relay();
        $this->level = 1;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $this->level = 2;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_DELETED);
        $this->level = 3;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_DELETED);

        $relay->connectionRolledBack(2);
        self::assertSame(['held' => 1, 'released' => 0], $relay->pending());
        $relay->connectionRolledBack(null);
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());

        $this->level = 2;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_DELETED);
        $relay->connectionRolledBack(1);
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());
        self::assertSame([], $this->seen);
    }

    public function testTheBoundaryDispatchesReleasedEventsAndDropsUncommittedOnes(): void
    {
        $logger      = new SecretCapturingLogger();
        $relay       = $this->relay(true, $logger);
        $this->level = 1;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $relay->connectionCommitted();
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_DELETED);

        // auto_commit false: a transaction is always open, the boundary still runs the committed events.
        $relay->reset();
        self::assertSame([YopassEvents::SHARE_CREATED], $this->seen);
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());
        self::assertStringContainsString('dropped {count} share event', $this->logged($logger));
        self::assertStringContainsString('"count":1', $this->logged($logger));

        $relay->reset();
        self::assertSame([YopassEvents::SHARE_CREATED], $this->seen);
    }

    public function testAFailingListenerOfAReleasedEventIsLoggedAndTheOthersStillRun(): void
    {
        $logger = new SecretCapturingLogger();
        $this->dispatcher->addListener(YopassEvents::SHARE_CREATED, static function (): void {
            throw new RuntimeException('ciphertext-must-not-be-logged');
        }, -10);
        $relay       = $this->relay(true, $logger);
        $this->level = 1;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_DELETED);
        $relay->connectionCommitted();
        $this->level = 0;

        $relay->dispatch(new stdClass(), YopassEvents::SHARE_DELETED);
        self::assertSame([YopassEvents::SHARE_CREATED, YopassEvents::SHARE_DELETED, YopassEvents::SHARE_DELETED], $this->seen);
        self::assertStringContainsString(RuntimeException::class, $this->logged($logger));
        self::assertStringNotContainsString('ciphertext-must-not-be-logged', $this->logged($logger));
    }

    public function testAListenerDispatchingAnotherEventDoesNotReenterTheRelease(): void
    {
        $relay = $this->relay();
        $this->dispatcher->addListener(YopassEvents::SHARE_CREATED, static function () use ($relay): void {
            $relay->dispatch(new stdClass(), YopassEvents::SHARE_DELETED);
        });
        $this->level = 1;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $relay->connectionCommitted();
        $this->level = 0;
        $relay->reset();

        self::assertSame([YopassEvents::SHARE_CREATED, YopassEvents::SHARE_DELETED], $this->seen);
    }

    public function testAnUnreadableConnectionCountsAsIdle(): void
    {
        $this->failLevel = true;
        $this->relay()->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);

        self::assertSame([YopassEvents::SHARE_CREATED], $this->seen);
    }

    public function testTheDriverConnectionReportsCommitsAndRollbacksWithTheirLevel(): void
    {
        $relay  = $this->relay();
        $inner  = $this->createMock(DriverConnection::class);
        $inner->method('exec')->willReturn(0);
        $own    = new YopassEntityManager($this->registry());
        $driver = new PrivateEntityManagerConnection($inner, $own, $relay);

        $this->level = 2;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $driver->exec('ROLLBACK TO SAVEPOINT nowo_yopass_abcdef');
        self::assertSame(['held' => 1, 'released' => 0], $relay->pending(), 'The bundle\'s own savepoint keeps held events');
        $driver->exec('ROLLBACK TO SAVEPOINT DOCTRINE_3');
        self::assertSame(['held' => 1, 'released' => 0], $relay->pending());
        $driver->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());

        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $driver->exec('ROLLBACK TO SAVEPOINT host_named');
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending(), 'An unknown savepoint drops everything held');

        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $driver->commit();
        self::assertSame(['held' => 0, 'released' => 1], $relay->pending());

        $relay->reset();
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $driver->rollBack();
        self::assertSame(['held' => 0, 'released' => 0], $relay->pending());

        $failing = $this->createMock(DriverConnection::class);
        $failing->method('commit')->willThrowException(new RuntimeException('lost'));
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        try {
            (new PrivateEntityManagerConnection($failing, $own, $relay))->commit();
            self::fail('The commit failure is rethrown');
        } catch (RuntimeException) {
            self::assertSame(['held' => 0, 'released' => 0], $relay->pending());
        }
    }

    public function testTheMiddlewareHandsTheRelayToItsConnections(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($this->createMock(DriverConnection::class));
        $relay   = $this->relay();
        $wrapped = (new PrivateEntityManagerMiddleware(new YopassEntityManager($this->registry()), $relay))->wrap($driver);

        self::assertInstanceOf(PrivateEntityManagerDriver::class, $wrapped);
        $connection = $wrapped->connect([]);
        self::assertInstanceOf(PrivateEntityManagerConnection::class, $connection);

        $this->level = 1;
        $relay->dispatch(new stdClass(), YopassEvents::SHARE_CREATED);
        $connection->commit();
        self::assertSame(['held' => 0, 'released' => 1], $relay->pending());
    }

    private function logged(SecretCapturingLogger $logger): string
    {
        return (string) json_encode($logger->records);
    }

    private function relay(bool $enabled = true, ?SecretCapturingLogger $logger = null): HostCommitEventRelay
    {
        return new HostCommitEventRelay($this->dispatcher, new YopassEntityManager($this->registry()), $enabled, $logger);
    }

    private function registry(): ManagerRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(function (): int {
            if ($this->failLevel) {
                throw new RuntimeException('connection lost');
            }

            return $this->level;
        });
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return $registry;
    }

    private function response(int $type): ResponseEvent
    {
        return new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), $type, new Response());
    }
}
