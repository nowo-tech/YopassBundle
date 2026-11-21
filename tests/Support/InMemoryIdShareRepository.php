<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\CreatorIdShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function method_exists;

/**
 * In-memory share repository with the creator-id queries (2.1.0 tests of the id ports' fallback path): creators are
 * compared by their exact id, like the Doctrine repositories after their PHP re-check.
 */
final class InMemoryIdShareRepository implements ShareRepositoryInterface, CreatorIdShareRepositoryInterface
{
    /** @var array<string, SecureShare> */
    public array $shares = [];

    public int $flushes = 0;

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
        $id = method_exists($creator, 'getId') ? (string) $creator->getId() : null;

        return $id === null ? [] : $this->findByCreatorId($id);
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
        foreach ($this->findByCreator($creator) as $share) {
            if ($share->getCreatedAt() < $before) {
                unset($this->shares[$share->getId()]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function removeAllByCreator(object $creator): int
    {
        $removed = 0;
        foreach ($this->findByCreator($creator) as $share) {
            unset($this->shares[$share->getId()]);
            ++$removed;
        }

        return $removed;
    }

    public function removeOlderThan(DateTimeImmutable $before): int
    {
        return 0;
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
        ++$this->flushes;
    }

    public function findByCreatorId(string $creatorId): array
    {
        return array_values(array_filter($this->shares, static fn (SecureShare $share): bool => $share->getCreatorId() === $creatorId));
    }

    public function countByCreatorId(string $creatorId): int
    {
        return count($this->findByCreatorId($creatorId));
    }

    public function removeAllByCreatorId(string $creatorId): int
    {
        return $this->removeAllByCreator(new CreatorId($creatorId));
    }
}
