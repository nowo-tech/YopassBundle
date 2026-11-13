<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Drops the bundle's private entity manager ({@see YopassEntityManager}) at every OUTERMOST unit-of-work boundary
 * (1.20.0, REQ-RUNTIME-002 rule 4), so no share or access log row loaded by a request, a command or a message stays
 * managed in a kept-alive kernel (FrankenPHP worker without kernel reset, no services resetter), and an unflushed
 * change of an abandoned unit is never written by a later, unrelated bundle flush. Never touches an application
 * manager (rule 7: the application clears its own managers); registered only with the private manager (the default
 * of the `doctrine_orm` driver).
 *
 * Boundaries (every unit-of-work state change at PHP_INT_MAX, through {@see UnitOfWorkFrames}):
 * - HTTP: the main `kernel.request` (PHP_INT_MAX) and `kernel.terminate` (priority -2048), only for the outermost
 *   unit: not inside a console command, a Messenger message or another main request (an in-process
 *   `HttpKernel::handle()` must not drop the manager of the unit that runs it). Sub-requests share their main
 *   request's unit of work;
 * - Messenger: when a message is received (PHP_INT_MAX) and after it is handled or failed (priority -2048), only
 *   outside another message (a worker run in-process by a handler keeps the outer message's manager, audit NX CR1).
 *   A skipped message (`WorkerMessageSkipEvent`, dispatched by `messenger:failed:retry` only, or `shouldHandle(false)`
 *   in a received listener, audit NX CR3), the worker's next `WorkerRunningEvent` or its stop ends the in-flight
 *   message without dropping;
 * - console: when the OUTERMOST run starts (PHP_INT_MAX) and terminates (priority -2048), only outside a message and a
 *   main request. A command run in-process from another command, a message handler or a controller does not drop the
 *   outer unit's manager. A run whose `console.error` listener threw (no terminate follows) is dropped from the
 *   bookkeeping at the next request, message, command or worker stop, so a throwing or propagation-stopping listener
 *   never leaves the process "inside a command" for good.
 *
 * The Messenger events are subscribed by name, so symfony/messenger stays optional.
 *
 * @internal registered by the bundle; the constructor is not a public API
 */
final readonly class YopassEntityManagerBoundarySubscriber implements EventSubscriberInterface
{
    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = WorkerMessageReceivedEvent::class;
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = WorkerMessageHandledEvent::class;
    public const MESSENGER_WORKER_MESSAGE_FAILED   = WorkerMessageFailedEvent::class;
    public const MESSENGER_WORKER_STOPPED          = WorkerStoppedEvent::class;
    public const MESSENGER_WORKER_STARTED          = WorkerStartedEvent::class;
    public const MESSENGER_WORKER_RUNNING          = WorkerRunningEvent::class;
    /** Dispatched by `messenger:failed:retry` only, when a message is skipped. */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = WorkerMessageSkipEvent::class;

    /** Console event names (ConsoleEvents::COMMAND / ERROR / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_ERROR     = 'console.error';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /** The units of work running in this process (process lifetime, bounded by the nesting depth). */
    private UnitOfWorkFrames $units;

    /**
     * @param ResetInterface $entityManager the private manager ({@see YopassEntityManager::reset()} drops it)
     * @param RequestStack|null $requestStack the HTTP kernel's request stack (read only, see {@see UnitOfWorkFrames})
     */
    public function __construct(
        private ResetInterface $entityManager,
        ?RequestStack $requestStack = null,
    ) {
        $this->units = new UnitOfWorkFrames($requestStack);
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', PHP_INT_MAX],
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', PHP_INT_MAX],
            KernelEvents::TERMINATE                 => [['onRequestBoundaryEnded', PHP_INT_MAX], ['onTerminate', -2048]],
            self::MESSENGER_WORKER_STARTED          => ['onWorkerStarted', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => [['onMessageReceived', PHP_INT_MAX], ['onMessageReceivedDecided', PHP_INT_MIN]],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => [['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -2048]],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => [['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -2048]],
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onMessageEnded', PHP_INT_MAX],
            self::MESSENGER_WORKER_RUNNING          => ['onWorkerRunning', PHP_INT_MAX],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', PHP_INT_MAX],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', PHP_INT_MAX],
            self::CONSOLE_ERROR                     => ['onConsoleError', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => [['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -2048]],
        ];
    }

    /**
     * Start of a main request: drops the manager only for the outermost unit (not an in-process HttpKernel::handle()).
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->units->requestStarted($event->getRequest())) {
            $this->entityManager->reset();
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->units->requestFinished($event->getRequest());
        }
    }

    /**
     * `kernel.terminate` (PHP_INT_MAX): a top-level request boundary resets the main-request counter, even when the
     * request's `kernel.finish_request` never came (audit NR-b M1).
     */
    public function onRequestBoundaryEnded(TerminateEvent $event): void
    {
        $this->units->requestBoundaryEnded($event);
    }

    /**
     * End of a request (late): drops the manager only when nothing else runs.
     */
    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->entityManager->reset();
        }
    }

    /**
     * A Messenger worker starts (its own message state, audit NX CR1).
     */
    public function onWorkerStarted(object $event): void
    {
        $this->units->workerStarted($event);
    }

    /**
     * Start of a Messenger message: nothing of the previous message or of the worker's idle time is kept. A message
     * handled by a worker run in-process inside another message's handler keeps the outer unit (audit NX CR1).
     */
    public function onMessageReceived(object $event): void
    {
        if ($this->units->messageReceived($event)) {
            $this->entityManager->reset();
        }
    }

    /**
     * End of the received event (PHP_INT_MIN): a message skipped with `shouldHandle(false)` ends (audit NX CR3).
     */
    public function onMessageReceivedDecided(object $event): void
    {
        $this->units->messageReceivedDecided($event);
    }

    /**
     * A message was handled, failed or skipped (PHP_INT_MAX): it is not in flight any more.
     */
    public function onMessageEnded(object $event): void
    {
        $this->units->messageFinished($event);
    }

    /**
     * End of a Messenger message (handled or failed, late): drops the manager only for the outermost message.
     */
    public function onMessageFinished(object $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->entityManager->reset();
        }
    }

    /**
     * The worker finished a message or is idle: its message is not in flight any more.
     */
    public function onWorkerRunning(object $event): void
    {
        $this->units->workerRunning($event);
    }

    /**
     * The worker stopped: its message is not in flight any more.
     */
    public function onWorkerStopped(object $event): void
    {
        $this->units->workerStopped($event);
    }

    /**
     * Start of a command run: drops the manager only for the outermost one (outside a message and a main request).
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($this->units->commandStarted($event)) {
            $this->entityManager->reset();
        }
    }

    /**
     * A command run failed: its frame is marked (dropped at the next unit when no terminate follows).
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->units->commandFailed($event);
    }

    /**
     * End of a command run (PHP_INT_MAX, before any listener that could throw or stop the propagation).
     */
    public function onConsoleCommandEnded(ConsoleTerminateEvent $event): void
    {
        $this->units->commandEnded($event);
    }

    /**
     * End of a command run (late): drops the manager only when that run started as the outermost unit.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->entityManager->reset();
        }
    }
}
