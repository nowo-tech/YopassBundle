<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\EventListener;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\YopassBundle\Controller\PublicShareController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Throwable;

use function in_array;
use function is_string;
use function str_starts_with;

/**
 * 1.21.0 (REQ-RUNTIME-003 rule 6): a lock conflict on a route of the bundle answers a conflict instead of a 500.
 *
 * A lock conflict is a deadlock, lock wait timeout or serialization failure anywhere down the chain (MySQL 1213 / 1205,
 * MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03), a {@see TransientStorageFailure} of the bundle's write unit, or a
 * lost savepoint of unknown outcome. It answers the status of the {@see ConflictResponsePolicy} (**409** by default,
 * 503 when the host configures it) with a {@see ConflictCode} in the `X-Conflict-Code` header:
 *
 * - `CONFLICT_001` with `Retry-After` when nothing of the request was committed (for the public consume: the read was
 *   rolled back, nobody received the ciphertext, the reader may simply try again);
 * - `CONFLICT_002` without `Retry-After` when part of it may be: anything committed since the request started
 *   (transaction-doctrine's request mark, {@see ConflictCode::forRequest()}, when its bundle is registered with
 *   `commit_detection`; and the bundle's own mark on the {@see CommitLedger} the commit-tracking middleware feeds), a
 *   committed `ConcurrencyConflict`, or a lost savepoint with no deadlock behind it.
 *
 * Shapes (existing keys kept, the code added):
 *
 * - the public consume: JSON `{status: "conflict" | "conflict_committed", code}`, not cacheable;
 * - the manage JSON endpoints (extend, preview, and create when the dialog asks for JSON): JSON
 *   `{error: "conflict" | "conflict_committed", code}`;
 * - the pages: an HttpException with a fixed, client-safe message and the storage error as previous (for the logs; no
 *   SQL or value reaches the client), rendered by the application's error page with the headers above.
 *
 * Unique violations and lost connections are not conflicts and are never mapped: the bundle's ids are random UUIDs and
 * it has no "only one" unique key, so a unique violation is a real error. Only the bundle's controllers: the host's
 * routes keep the host's error handling. Stateless apart from the shared ledger, a monotonic counter read by difference
 * only (FrankenPHP worker mode, kernel reset off).
 */
final readonly class YopassConflictListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** kernel.request priority: before the router (32) and the firewall (8), so no controller wrote yet. */
    public const REQUEST_PRIORITY = 2048;

    /** Request attribute holding the bundle's own commit mark. */
    public const MARK_ATTRIBUTE = '_nowo_yopass_commit_mark';

    /** JSON `status` / `error` value of a CONFLICT_001 answer. */
    public const STATUS_RETRY_SAFE = 'conflict';

    /** JSON `status` / `error` value of a CONFLICT_002 answer. */
    public const STATUS_COMMITTED = 'conflict_committed';

    /** Message of a CONFLICT_001 page (nothing committed). */
    public const RETRY_SAFE_MESSAGE = 'Another change to this share is being saved at the same time. Retry in a moment.';

    /** Message of a CONFLICT_002 page (part of the request may be committed). */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload the page before trying again.';

    /** Controller namespace of the bundle. */
    private const CONTROLLER_PREFIX = 'Nowo\\YopassBundle\\Controller\\';

    /** The public consume action (JSON `status` shape). */
    private const CONSUME_ACTION = PublicShareController::class . '::consume';

    /** Manage actions that always answer JSON (`error` shape). */
    private const JSON_ACTIONS = [
        self::CONTROLLER_PREFIX . 'ShareManageController::extend',
        self::CONTROLLER_PREFIX . 'ShareManageController::preview',
    ];

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy the conflict status and default Retry-After (null = 409, 1 s)
     * @param CommitLedger|null $ledger real commits counted by the commit-tracking middleware (null = none)
     * @param RequestStack|null $requestStack the main request's mark for a sub-request
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        private ?CommitLedger $ledger = null,
        private ?RequestStack $requestStack = null,
    ) {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Takes the bundle's commit mark on the main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger && $event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::MARK_ATTRIBUTE, new CommitMark($this->ledger));
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return;
        }

        $failure = $event->getThrowable();
        if (!$this->isLockConflict($failure)) {
            return;
        }

        $code    = $this->code($request, $failure);
        $hint    = $this->hint($failure);
        $value   = $code->retrySafe() ? self::STATUS_RETRY_SAFE : self::STATUS_COMMITTED;
        $headers = $this->policy->headersFor($code, $hint);

        if ($controller === self::CONSUME_ACTION) {
            $event->setResponse(PublicShareController::hardenSecretResponse(
                new JsonResponse(['status' => $value, 'code' => $code->value], $this->policy->status, $headers),
            ));

            return;
        }

        if (in_array($controller, self::JSON_ACTIONS, true) || ($request->getAcceptableContentTypes()[0] ?? null) === 'application/json') {
            $event->setResponse(new JsonResponse(['error' => $value, 'code' => $code->value], $this->policy->status, $headers));

            return;
        }

        $event->setThrowable($this->policy->httpExceptionFor(
            $code,
            $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE,
            $failure,
            $hint,
        ));
    }

    private function isLockConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return $this->classifier->isTransient($failure) || $this->classifier->isCommitOutcomeUnknown($failure);
    }

    /**
     * CONFLICT_002 when transaction-doctrine's request mark, or the bundle's own one, saw a commit; else the failure decides.
     */
    private function code(Request $request, Throwable $failure): ConflictCode
    {
        $code = ConflictCode::forRequest($request, $failure);
        if ($code->retrySafe() && $this->ownMark($request)?->committedSince() === true) {
            return ConflictCode::Committed;
        }

        return $code;
    }

    private function ownMark(Request $request): ?CommitMark
    {
        $mark = $request->attributes->get(self::MARK_ATTRIBUTE) ?? $this->requestStack?->getMainRequest()?->attributes->get(self::MARK_ATTRIBUTE);

        return $mark instanceof CommitMark ? $mark : null;
    }

    /** The first Retry-After hint of the chain (a transient failure of a runner); null for none or zero. */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }
}
