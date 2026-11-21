<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Nowo\YopassBundle\Event\YopassEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;

/**
 * Lifecycle events after the application's commit (2.1.0, REQ-ARCH-001 rule 7, DDD-host checklist B4).
 *
 * The bundle's write services dispatch their "after" events ({@see self::DEFERRED}) through this dispatcher. When no
 * transaction is open on the connection of the bundle's entity manager the event is dispatched at once, exactly as
 * before (the bundle's own write already committed). When the application wrapped the bundle call in its own
 * transaction, the bundle's rows are only in a savepoint of it, so the event is **held**:
 *
 * - the outermost commit of that connection releases it ({@see PrivateEntityManagerConnection});
 * - a rollback of the transaction drops it; a rollback to a savepoint drops the events held at that nesting level or
 *   deeper (a savepoint the level of which cannot be told drops every held event);
 * - released events are dispatched at the first point where the connection is idle again: the next bundle event, the
 *   main request's `kernel.response`, or the end of the request / message / command (the unit-of-work boundaries of
 *   {@see YopassEntityManagerBoundarySubscriber}). They cannot run inside the driver's commit: DBAL updates its
 *   nesting level only after it returns, so a listener that writes would open a savepoint on a finished transaction.
 *
 * A listener that throws for a released event is logged (event name and exception class only) and the others still
 * run: the data is committed and the application's commit already returned. An event dispatched at once keeps the
 * previous behaviour (a listener's exception reaches the caller).
 *
 * The pre-action events (`share_delete_all`, `share_access_check`, the list events) always pass straight through. Only
 * the Doctrine ORM driver with doctrine/dbal 4 holds events (the commit is seen through the bundle's DBAL middleware);
 * with DBAL 3, MongoDB or a custom repository, and with `nowo_yopass.events.after_host_commit: false`, every event is
 * dispatched at once.
 *
 * FrankenPHP worker mode without kernel reset: held events are request state. Every outermost boundary dispatches what
 * was released and drops what is still held (logged as a warning: that transaction never committed through the
 * bundle's middleware); {@see reset()} does the same at `kernel.reset`. Nothing survives into the next request.
 *
 * @internal registered by the bundle for the Doctrine ORM driver
 */
final class HostCommitEventRelay implements EventDispatcherInterface, EventSubscriberInterface, ResetInterface
{
    /** The "after" events that wait for the application's commit. */
    public const DEFERRED = [
        YopassEvents::SHARE_CREATED,
        YopassEvents::SHARE_DELETED,
        YopassEvents::SHARE_REVOKED,
        YopassEvents::SHARE_EXTENDED,
        YopassEvents::SHARES_BULK_DELETED,
        YopassEvents::SHARE_CONSUMED,
    ];

    /** @var list<array{event: object, name: string, level: int}> held until the outermost commit */
    private array $held = [];

    /** @var list<array{event: object, name: string}> committed, waiting for an idle connection */
    private array $released = [];

    /** Guards against re-entrance while released events run. */
    private bool $releasing = false;

    /**
     * @param EventDispatcherInterface $dispatcher the application's event dispatcher
     * @param YopassEntityManager $ownManager the bundle's entity manager (its connection's transaction state is read)
     * @param bool $enabled `nowo_yopass.events.after_host_commit` and the DBAL 4 middleware present
     * @param LoggerInterface|null $logger listener failures of released events, dropped held events (no payload)
     */
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly YopassEntityManager $ownManager,
        private readonly bool $enabled = true,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Late, so the application's own response listeners ran (and committed) first.
        return [KernelEvents::RESPONSE => ['onResponse', -1024]];
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $name = $eventName ?? $event::class;
        if (!$this->enabled || !in_array($name, self::DEFERRED, true)) {
            return $this->dispatcher->dispatch($event, $eventName);
        }

        $level = $this->openLevel();
        if ($level === 0) {
            $this->dispatchReleased();

            return $this->dispatcher->dispatch($event, $eventName);
        }

        // @igor-ignore - Held for the running unit of work only: released or dropped at its boundary and by reset().
        $this->held[] = ['event' => $event, 'name' => $name, 'level' => $level];

        return $event;
    }

    /**
     * The main request's response is ready: a committed application transaction's events are dispatched.
     */
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->dispatchReleased();
        }
    }

    /**
     * The outermost transaction of the connection committed ({@see PrivateEntityManagerConnection}): every held event
     * is released (dispatched once the connection is idle).
     */
    public function connectionCommitted(): void
    {
        if ($this->held === []) {
            return;
        }

        foreach ($this->held as $item) {
            // @igor-ignore - Held for the running unit of work only (see dispatch()).
            $this->released[] = ['event' => $item['event'], 'name' => $item['name']];
        }
        // @igor-ignore - Held for the running unit of work only (see dispatch()).
        $this->held = [];
    }

    /**
     * A transaction (`$level` 1 or null) or a savepoint of nesting level `$level` was rolled back: the events held at
     * that level or deeper are dropped (all of them when the level is unknown).
     */
    public function connectionRolledBack(?int $level): void
    {
        if ($this->held === []) {
            return;
        }

        // @igor-ignore - Held for the running unit of work only (see dispatch()).
        $this->held = $level === null || $level <= 1
            ? []
            : array_values(array_filter($this->held, static fn (array $item): bool => $item['level'] < $level));
    }

    /**
     * Number of held (not yet committed) and released (not yet dispatched) events.
     *
     * @return array{held: int, released: int}
     */
    public function pending(): array
    {
        return ['held' => count($this->held), 'released' => count($this->released)];
    }

    /**
     * An outermost unit-of-work boundary or `kernel.reset`: released events are dispatched, held ones (their transaction
     * never committed) are dropped with a warning. Nothing is kept afterwards.
     */
    public function reset(): void
    {
        try {
            // At a boundary the request / message / command is over: committed events run even when a transaction is
            // open (`auto_commit: false` opens the next one right after every commit).
            $this->dispatchReleased(true);
        } finally {
            $dropped = count($this->held) + count($this->released);
            // @igor-ignore - Boundary drop of request state.
            $this->held = [];
            // @igor-ignore - Boundary drop of request state.
            $this->released = [];
            if ($dropped > 0) {
                $this->logger?->warning('nowo_yopass: dropped {count} share event(s) of a transaction that did not commit before the end of the unit of work.', ['count' => $dropped]);
            }
        }
    }

    /**
     * Dispatches the released events when the connection is idle; a listener's failure is logged, never thrown.
     */
    private function dispatchReleased(bool $evenInTransaction = false): void
    {
        if ($this->released === [] || $this->releasing || (!$evenInTransaction && $this->openLevel() !== 0)) {
            return;
        }

        // @igor-ignore - Re-entrance guard of the running dispatch only.
        $this->releasing = true;
        try {
            while ($this->released !== []) {
                $item = $this->released[0];
                // @igor-ignore - Held for the running unit of work only (see dispatch()).
                $this->released = array_slice($this->released, 1);
                try {
                    $this->dispatcher->dispatch($item['event'], $item['name']);
                } catch (Throwable $exception) {
                    $this->logger?->error('nowo_yopass: a {event} listener failed after the share change was committed.', ['event' => $item['name'], 'exception' => $exception::class]);
                }
            }
        } finally {
            // @igor-ignore - Re-entrance guard of the running dispatch only.
            $this->releasing = false;
        }
    }

    /**
     * The transaction nesting level of the bundle's connection (0 when none is open or it cannot be read).
     */
    private function openLevel(): int
    {
        try {
            return $this->ownManager->source()->getConnection()->getTransactionNestingLevel();
        } catch (Throwable) {
            return 0;
        }
    }
}
