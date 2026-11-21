<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Creator\UnusableCreatorIdException;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\CreatorIdShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareSummaryRepositoryInterface;
use Nowo\YopassBundle\Share\ShareCommandException;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Nowo\YopassBundle\Share\ShareCriteria;
use Nowo\YopassBundle\Share\ShareQueryInterface;
use Nowo\YopassBundle\Share\ShareView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;
use function strlen;
use function trim;

/**
 * Default {@see ShareCommandsInterface} (2.1.0): the id-based entry point over the bundle's own write services
 * ({@see ShareCreator}, {@see ShareRevoker}, {@see ShareExtender}, {@see ShareDeleter}), so the same events, write
 * units and concurrency controls apply as in the manage UI.
 *
 * Zero-knowledge as everywhere else: the ciphertext is stored as given (the client encrypted it), and no exception,
 * log line or event carries it.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off).
 */
final readonly class ShareCommands implements ShareCommandsInterface
{
    private ClockInterface $clock;

    /**
     * @param array{
     *     expiration_options: list<array{id: string, interval: string}>,
     *     max_reads_options: list<int>
     * } $shareOptions `nowo_yopass.shares`
     * @param int $maxCiphertextBytes `nowo_yopass.max_ciphertext_bytes`
     * @param bool $fileSharesEnabled whether `nowo_yopass.file_handler` is configured (file payloads accepted)
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private ShareCreator $creator,
        private ShareRevoker $revoker,
        private ShareExtender $extender,
        private ShareDeleter $deleter,
        private ShareQueryInterface $query,
        private array $shareOptions,
        private int $maxCiphertextBytes,
        private bool $fileSharesEnabled = false,
        private ?ShareRetentionPurger $retentionPurger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?CreatorReferenceResolverInterface $creators = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    public function create(CreatorId $creator, ShareCreateData $data): ShareView
    {
        if (trim($data->ciphertext) === '') {
            throw new ShareCommandException(ShareCommandException::INVALID_CIPHERTEXT);
        }
        if (strlen($data->ciphertext) > $this->maxCiphertextBytes) {
            throw new ShareCommandException(ShareCommandException::CIPHERTEXT_TOO_LARGE);
        }
        if (!$this->isExpirationOption($data->expiresIn)) {
            throw new ShareCommandException(ShareCommandException::INVALID_EXPIRATION);
        }
        if (!in_array($data->maxReads, $this->shareOptions['max_reads_options'], true)) {
            throw new ShareCommandException(ShareCommandException::INVALID_MAX_READS);
        }
        if (!in_array($data->payloadKind, ['text', 'file'], true)) {
            throw new ShareCommandException(ShareCommandException::INVALID_PAYLOAD_KIND);
        }
        if ($data->payloadKind === 'file' && !$this->fileSharesEnabled) {
            throw new ShareCommandException(ShareCommandException::FILE_NOT_ENABLED);
        }

        $this->assertCreator($creator);

        // As in the manage UI: the retention purge runs before the insert, so a failing purge never answers an error for
        // a share that was already stored.
        $this->retentionPurger?->purgeForCreator($creator);

        return $this->view($this->creator->create($creator, $data));
    }

    public function revoke(string $shareId, ?string $actorId = null): ?ShareView
    {
        $share = $this->load($shareId);
        if (!$share instanceof SecureShare) {
            return null;
        }

        if (!$share->getRevokedAt() instanceof DateTimeImmutable && !$this->revoker->revoke($share, null, $actorId)) {
            // 2.1.1 (YD1-L1): this call did not write. Another call revoked the share or deleted it in between: read it
            // again, so a share deleted meanwhile answers null (no view, no event) and a revoked one its stored state.
            $share = $this->load($shareId);
            if (!$share instanceof SecureShare) {
                return null;
            }
        }

        return $this->view($share);
    }

    public function extend(string $shareId, ?string $expiresIn, ?int $maxReads, ?string $actorId = null): ShareView
    {
        $share = $this->load($shareId);
        if (!$share instanceof SecureShare) {
            throw new ShareExtendException('not_found');
        }

        $this->extender->extend($share, $expiresIn, $maxReads, null, $actorId);

        return $this->view($share);
    }

    public function delete(string $shareId, ?string $actorId = null): bool
    {
        return ShareCriteria::isUsableShareId($shareId) && $this->deleter->deleteById($shareId, $actorId);
    }

    public function deleteByIds(array $shareIds, ?CreatorId $creator = null, ?string $actorId = null): int
    {
        if ($shareIds === []) {
            return 0;
        }

        $ids = $this->query->ids(new ShareCriteria($creator instanceof CreatorId ? [$creator->getId()] : null, $shareIds));
        if ($ids === []) {
            return 0;
        }

        if ($this->shareRepository instanceof ShareSummaryRepositoryInterface) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $removed = $this->shareRepository->removeByIds($ids);
        } else {
            $removed = 0;
            foreach ($ids as $id) {
                $share = $this->shareRepository->find($id);
                if ($share instanceof SecureShare) {
                    // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
                    $this->shareRepository->remove($share);
                    ++$removed;
                }
            }
            if ($removed > 0) {
                $this->shareRepository->flush();
            }
        }

        if ($removed > 0) {
            $this->eventDispatcher?->dispatch(
                new ShareBulkDeletedEvent(ShareBulkDeletedEvent::REASON_DELETE_BY_IDS, $creator, $removed, null, $actorId, $this->clock->now(), $ids),
                YopassEvents::SHARES_BULK_DELETED,
            );
        }

        return $removed;
    }

    public function deleteAllOf(CreatorId $creator, ?string $actorId = null): int
    {
        if (!$this->namesCreator($creator)) {
            // 2.1.1 (YD1-L3): an id that cannot name a creator has no shares, as an unknown id.
            return 0;
        }

        if ($this->shareRepository instanceof CreatorIdShareRepositoryInterface) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $removed = $this->shareRepository->removeAllByCreatorId($creator->getId());
        } else {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $removed = $this->shareRepository->removeAllByCreator($this->creators?->reference($creator) ?? $creator);
        }
        if ($removed > 0) {
            $this->shareRepository->flush();
            $this->eventDispatcher?->dispatch(
                new ShareBulkDeletedEvent(ShareBulkDeletedEvent::REASON_DELETE_ALL, $creator, $removed, null, $actorId, $this->clock->now()),
                YopassEvents::SHARES_BULK_DELETED,
            );
        }

        return $removed;
    }

    /**
     * Refuses a creator id that cannot name a `user_class` user (2.1.1, YD1-L3) with {@see ShareCommandException}
     * instead of the resolver's {@see UnusableCreatorIdException}.
     *
     * @throws ShareCommandException with code `invalid_creator`
     */
    private function assertCreator(CreatorId $creator): void
    {
        if (!$this->namesCreator($creator)) {
            throw new ShareCommandException(ShareCommandException::INVALID_CREATOR);
        }
    }

    /**
     * Whether the creator resolver accepts this id: under the relation mapping an integer key only takes a plain
     * decimal id (no query is made). Always true without a resolver and with the id mapping. Only that refusal
     * ({@see UnusableCreatorIdException}) answers false; any other resolver failure (a misconfiguration such as a
     * composite identifier) propagates (audit YD3-L1).
     */
    private function namesCreator(CreatorId $creator): bool
    {
        try {
            $this->creators?->reference($creator);
        } catch (UnusableCreatorIdException) {
            return false;
        }

        return true;
    }

    /**
     * The share with exactly this id, or null.
     */
    private function load(string $shareId): ?SecureShare
    {
        if (!ShareCriteria::isUsableShareId($shareId)) {
            return null;
        }

        $share = $this->shareRepository->find($shareId);

        return $share instanceof SecureShare && $share->getId() === $shareId ? $share : null;
    }

    private function view(SecureShare $share): ShareView
    {
        return ShareView::fromShare($share, $this->clock->now());
    }

    private function isExpirationOption(string $expiresIn): bool
    {
        foreach ($this->shareOptions['expiration_options'] as $option) {
            if ($option['id'] === $expiresIn) {
                return true;
            }
        }

        return false;
    }
}
