<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\YopassBundle\EventListener\YopassConflictListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(YopassConflictListener::class)]
final class YopassConflictListenerTest extends TestCase
{
    private const CONSUME = 'Nowo\\YopassBundle\\Controller\\PublicShareController::consume';

    private const REVOKE = 'Nowo\\YopassBundle\\Controller\\ShareManageController::revoke';

    private const EXTEND = 'Nowo\\YopassBundle\\Controller\\ShareManageController::extend';

    private const CREATE = 'Nowo\\YopassBundle\\Controller\\ShareManageController::create';

    /**
     * @return iterable<string, array{0: Throwable}>
     */
    public static function retrySafeConflicts(): iterable
    {
        yield 'deadlock (MySQL 1213)' => [new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null)];
        yield 'lock wait timeout (MySQL 1205)' => [new LockWaitTimeoutException(self::driverError('Lock wait timeout', 'HY000', 1205), null)];
        yield 'serialization failure (PostgreSQL 40001)' => [new DriverException(self::driverError('could not serialize', '40001'), null)];
        yield 'deadlock (PostgreSQL 40P01)' => [new DriverException(self::driverError('deadlock detected', '40P01'), null)];
        yield 'lock timeout (PostgreSQL 55P03)' => [new DriverException(self::driverError('lock timeout', '55P03'), null)];
        yield 'MariaDB 1020' => [new DriverException(self::driverError('Record has changed', 'HY000', 1020), null)];
        yield 'the write unit\'s TransientStorageFailure' => [TransientStorageFailure::fromPrevious(new RuntimeException('x'))];
        yield 'wrapped by the kernel' => [new RuntimeException('wrapped', 0, new DeadlockException(self::driverError('Deadlock', '40001', 1213), null))];
    }

    #[DataProvider('retrySafeConflicts')]
    public function testAConflictOnThePublicConsumeAnswers409JsonConflict001WithRetryAfter(Throwable $failure): void
    {
        $response = $this->response(new YopassConflictListener(), $failure, $this->request(self::CONSUME));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(ConflictCode::RETRY_SAFE, $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame(['status' => YopassConflictListener::STATUS_RETRY_SAFE, 'code' => ConflictCode::RETRY_SAFE], $this->json($response));
    }

    #[DataProvider('retrySafeConflicts')]
    public function testAConflictOnAManagePageAnswersTheHostErrorPageWith409AndTheCode(Throwable $failure): void
    {
        $answer = $this->answer(new YopassConflictListener(), $failure);

        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(YopassConflictListener::RETRY_SAFE_MESSAGE, $answer->getMessage());
        self::assertSame($failure, $answer->getPrevious(), 'the storage error stays for the logs');
    }

    public function testManageJsonEndpointsAnswerTheErrorShape(): void
    {
        $failure = new DeadlockException(self::driverError('Deadlock', '40001', 1213), null);

        $extend = $this->response(new YopassConflictListener(), $failure, $this->request(self::EXTEND));
        self::assertSame(409, $extend->getStatusCode());
        self::assertSame(['error' => YopassConflictListener::STATUS_RETRY_SAFE, 'code' => ConflictCode::RETRY_SAFE], $this->json($extend));
        self::assertSame(ConflictCode::RETRY_SAFE, $extend->headers->get('X-Conflict-Code'));

        $create = $this->request(self::CREATE);
        $create->headers->set('Accept', 'application/json');
        $dialog = $this->response(new YopassConflictListener(), $failure, $create);
        self::assertSame(['error' => YopassConflictListener::STATUS_RETRY_SAFE, 'code' => ConflictCode::RETRY_SAFE], $this->json($dialog));
    }

    public function testTheRunnerHintAndTheHostPolicyAreUsed(): void
    {
        $listener = new YopassConflictListener(new ConflictResponsePolicy(503, 7));

        $hinted = $this->answer($listener, new RuntimeException('w', 0, TransientStorageFailure::fromPrevious(new RuntimeException('x'), 4)));
        self::assertSame(503, $hinted->getStatusCode());
        self::assertSame('4', $hinted->getHeaders()['Retry-After']);

        self::assertSame('7', $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x'), 0))->getHeaders()['Retry-After'], 'a zero hint is no hint');
        self::assertSame('7', $this->answer($listener, new DeadlockException(self::driverError('Deadlock', '40001', 1213), null))->getHeaders()['Retry-After']);

        $consume = $this->response($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $this->request(self::CONSUME));
        self::assertSame(503, $consume->getStatusCode());
        self::assertSame('7', $consume->headers->get('Retry-After'));
    }

    public function testACommitOfTheBundleConnectionEarlierInTheRequestAnswersConflict002WithoutRetryAfter(): void
    {
        $ledger   = new CommitLedger();
        $listener = new YopassConflictListener(null, $ledger);
        $request  = $this->request();
        $listener->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));
        self::assertInstanceOf(CommitMark::class, $request->attributes->get(YopassConflictListener::MARK_ATTRIBUTE));

        $failure = TransientStorageFailure::fromPrevious(new RuntimeException('x'), 1);
        self::assertSame(ConflictCode::RETRY_SAFE, $this->answer($listener, $failure, $request)->getHeaders()['X-Conflict-Code'], 'nothing committed yet');

        $ledger->record();
        $answer = $this->answer($listener, $failure, $request);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame(YopassConflictListener::COMMITTED_MESSAGE, $answer->getMessage());

        $consume = $this->request(self::CONSUME);
        $listener->onRequest($this->requestEvent($consume, HttpKernelInterface::MAIN_REQUEST));
        $ledger->record();
        $response = $this->response($listener, $failure, $consume);
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame(ConflictCode::COMMITTED, $response->headers->get('X-Conflict-Code'));
        self::assertSame(['status' => YopassConflictListener::STATUS_COMMITTED, 'code' => ConflictCode::COMMITTED], $this->json($response));
    }

    public function testASubRequestReadsTheMarkOfTheMainRequest(): void
    {
        $ledger   = new CommitLedger();
        $stack    = new RequestStack();
        $listener = new YopassConflictListener(null, $ledger, $stack);
        $main     = $this->request();
        $stack->push($main);
        $listener->onRequest($this->requestEvent($main, HttpKernelInterface::MAIN_REQUEST));
        $sub = $this->request();
        $listener->onRequest($this->requestEvent($sub, HttpKernelInterface::SUB_REQUEST));
        self::assertNull($sub->attributes->get(YopassConflictListener::MARK_ATTRIBUTE));

        $ledger->record();

        self::assertSame(ConflictCode::COMMITTED, $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $sub)->getHeaders()['X-Conflict-Code']);
    }

    public function testWithoutALedgerNoMarkIsTaken(): void
    {
        $request = $this->request();
        (new YopassConflictListener())->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($request->attributes->has(YopassConflictListener::MARK_ATTRIBUTE));
    }

    public function testTransactionDoctrineRequestMarkAndCommittedConflictsAnswerConflict002(): void
    {
        $listener = new YopassConflictListener();
        $ledger   = new CommitLedger();
        $request  = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $request)->getHeaders(), 'host commit earlier in the request');

        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, new RuntimeException('x'), true);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, $committed)->getHeaders());

        $lostSavepoint = new DriverException(self::driverError('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, $lostSavepoint)->getHeaders(), 'commit outcome unknown');
    }

    /**
     * @return iterable<string, array{0: Throwable}>
     */
    public static function notConflicts(): iterable
    {
        yield 'unique violation' => [new UniqueConstraintViolationException(self::driverError('Duplicate entry', '23000', 1062), null)];
        yield 'lost connection' => [new ConnectionLost(self::driverError('MySQL server has gone away', '08S01', 2006), null)];
        yield 'other storage error' => [new DriverException(self::driverError('syntax error', '42000', 1064), null)];
        yield 'domain error' => [new RuntimeException('nope', 1213)];
    }

    #[DataProvider('notConflicts')]
    public function testOtherFailuresAreNotMapped(Throwable $failure): void
    {
        foreach ([self::REVOKE, self::CONSUME] as $controller) {
            $event = $this->event($failure, $this->request($controller));
            (new YopassConflictListener())->onException($event);

            self::assertSame($failure, $event->getThrowable());
            self::assertNull($event->getResponse());
        }
    }

    public function testHostRoutesKeepTheHostErrorHandling(): void
    {
        $failure = new DeadlockException(self::driverError('Deadlock', '40001', 1213), null);
        foreach (['App\\Controller\\HomeController::index', null, ['not', 'a string']] as $controller) {
            $request = Request::create('/');
            $request->attributes->set('_controller', $controller);
            $event = $this->event($failure, $request);
            (new YopassConflictListener())->onException($event);

            self::assertSame($failure, $event->getThrowable());
            self::assertNull($event->getResponse());
        }
    }

    private static function driverError(string $message, ?string $sqlState, int $code = 0): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }

    private function request(string $controller = self::REVOKE): Request
    {
        $request = Request::create('/tools/yopass/1/revoke', 'POST');
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function requestEvent(Request $request, int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function event(Throwable $failure, ?Request $request = null): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request ?? $this->request(), HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    private function answer(YopassConflictListener $listener, Throwable $failure, ?Request $request = null): HttpException
    {
        $event = $this->event($failure, $request);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);

        return $answer;
    }

    private function response(YopassConflictListener $listener, Throwable $failure, Request $request): JsonResponse
    {
        $event = $this->event($failure, $request);
        $listener->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
