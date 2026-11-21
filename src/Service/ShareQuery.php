<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\CreatorIdShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareSummaryRepositoryInterface;
use Nowo\YopassBundle\Share\ShareCriteria;
use Nowo\YopassBundle\Share\SharePage;
use Nowo\YopassBundle\Share\ShareQueryInterface;
use Nowo\YopassBundle\Share\ShareView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

use function array_slice;
use function count;
use function strcmp;
use function usort;

/**
 * Default {@see ShareQueryInterface} (2.1.0).
 *
 * With the Doctrine ORM driver the reads select scalar columns only ({@see ShareSummaryRepositoryInterface}; the
 * ciphertext is never loaded). With the MongoDB driver or a custom repository the shares are read through
 * {@see ShareRepositoryInterface::find()} and the creator queries, and turned into views at once. Either way the creator
 * and share ids are compared exactly, then the state filter, the order and the page are applied in PHP, so a look-alike
 * row a folding collation returned never takes a place on the page.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): nothing is kept between calls.
 */
final readonly class ShareQuery implements ShareQueryInterface
{
    private ClockInterface $clock;

    /**
     * @param ShareRepositoryInterface $shareRepository the configured share repository
     * @param ClockInterface|null $clock computes each view's state
     * @param CreatorReferenceResolverInterface|null $creators turns a creator id into the queried creator (fallback path)
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        ?ClockInterface $clock = null,
        private ?CreatorReferenceResolverInterface $creators = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    public function find(string $shareId): ?ShareView
    {
        if (!ShareCriteria::isUsableShareId($shareId)) {
            return null;
        }

        return $this->matching(ShareCriteria::withIds([$shareId]))[0] ?? null;
    }

    public function count(ShareCriteria $criteria): int
    {
        return count($this->matching($criteria));
    }

    public function page(ShareCriteria $criteria, int $limit, int $offset = 0): SharePage
    {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('The page size must be between 1 and ' . self::MAX_PAGE_SIZE . '.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('The page offset cannot be negative.');
        }

        $views = $this->matching($criteria);

        return new SharePage(array_slice($views, $offset, $limit), count($views), $limit, $offset);
    }

    public function ids(ShareCriteria $criteria): array
    {
        $ids = [];
        foreach ($this->matching($criteria) as $view) {
            $ids[] = $view->id;
        }

        return $ids;
    }

    /**
     * Every matching share, exact, filtered and sorted.
     *
     * @return list<ShareView>
     */
    private function matching(ShareCriteria $criteria): array
    {
        $now        = $this->clock->now();
        $candidates = [];
        // With both lists and no union, the id list alone bounds the candidates (the creator check runs below).
        if ($criteria->shareIds !== null) {
            foreach ($this->byIds($criteria->shareIds, $now) as $view) {
                $candidates['k' . $view->id] = $view;
            }
        }
        if ($criteria->creatorIds !== null && ($criteria->shareIds === null || $criteria->matchAny)) {
            foreach ($this->byCreators($criteria->creatorIds, $now) as $view) {
                $candidates['k' . $view->id] = $view;
            }
        }

        $kept = [];
        foreach ($candidates as $view) {
            if ($criteria->matchesIdentity($view->id, $view->creatorId) && $criteria->matchesState($view->state)) {
                $kept[] = $view;
            }
        }

        $newestFirst = $criteria->newestFirst;
        usort($kept, static function (ShareView $left, ShareView $right) use ($newestFirst): int {
            $byDate = $newestFirst ? $right->createdAt <=> $left->createdAt : $left->createdAt <=> $right->createdAt;

            return $byDate !== 0 ? $byDate : strcmp($left->id, $right->id);
        });

        return $kept;
    }

    /**
     * @param list<string> $shareIds
     *
     * @return list<ShareView>
     */
    private function byIds(array $shareIds, DateTimeImmutable $now): array
    {
        if ($this->shareRepository instanceof ShareSummaryRepositoryInterface) {
            return $this->shareRepository->viewsByIds($shareIds, $now);
        }

        $views = [];
        foreach ($shareIds as $shareId) {
            $share = $this->shareRepository->find($shareId);
            if ($share instanceof SecureShare && $share->getId() === $shareId) {
                $views[] = ShareView::fromShare($share, $now);
            }
        }

        return $views;
    }

    /**
     * @param list<string> $creatorIds
     *
     * @return list<ShareView>
     */
    private function byCreators(array $creatorIds, DateTimeImmutable $now): array
    {
        if ($this->shareRepository instanceof ShareSummaryRepositoryInterface) {
            return $this->shareRepository->viewsByCreatorIds($creatorIds, $now);
        }

        $views = [];
        foreach ($creatorIds as $creatorId) {
            $creator = new CreatorId($creatorId);
            $shares  = $this->shareRepository instanceof CreatorIdShareRepositoryInterface
                ? $this->shareRepository->findByCreatorId($creatorId)
                : $this->shareRepository->findByCreator($this->creators?->reference($creator) ?? $creator);
            foreach ($shares as $share) {
                $views[] = ShareView::fromShare($share, $now);
            }
        }

        return $views;
    }
}
