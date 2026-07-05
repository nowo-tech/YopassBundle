<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

use function array_slice;
use function count;

/**
 * In-memory repository for integration tests.
 */
final class InMemoryShareRepository implements ShareRepositoryInterface
{
    /** @var array<string, SecureShare> */
    private array $shares = [];

    public function add(SecureShare $share): void
    {
        $this->shares[$share->getId()] = $share;
    }

    public function find(string $id): ?SecureShare
    {
        return $this->shares[$id] ?? null;
    }

    public function consumeReadIfAvailable(string $id): ?SecureShare
    {
        $share = $this->shares[$id] ?? null;

        if (!$share instanceof SecureShare || !$share->isAvailable()) {
            return null;
        }

        $share->consumeRead();

        return $share;
    }

    public function findByCreator(object $creator): array
    {
        return array_values(array_filter(
            $this->shares,
            static fn (SecureShare $share): bool => $share->getCreator() === $creator,
        ));
    }

    public function countByCreator(object $creator): int
    {
        return count($this->findByCreator($creator));
    }

    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        return array_slice($this->findByCreator($creator), $offset, $limit);
    }

    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int
    {
        $removed = 0;

        foreach ($this->shares as $id => $share) {
            if ($share->getCreator() === $creator && $share->getCreatedAt() < $before) {
                unset($this->shares[$id]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function removeAllByCreator(object $creator): int
    {
        $removed = 0;

        foreach ($this->shares as $id => $share) {
            if ($share->getCreator() === $creator) {
                unset($this->shares[$id]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function removeOlderThan(DateTimeImmutable $before): int
    {
        $removed = 0;

        foreach ($this->shares as $id => $share) {
            if ($share->getCreatedAt() < $before) {
                unset($this->shares[$id]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function persist(SecureShare $share): void
    {
        $this->shares[$share->getId()] = $share;
    }

    public function remove(SecureShare $share): void
    {
        unset($this->shares[$share->getId()]);
    }

    public function flush(): void
    {
    }
}
