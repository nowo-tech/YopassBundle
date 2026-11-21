<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support;

use Closure;
use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Repository\ExpirationExtendingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\MaxReadsRaisingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\RevokingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

/**
 * 2.1.1 (audit YD1-L1): the bundle's ORM repository with a hook that runs right before each conditional write, where
 * another worker's revoke or delete lands between the load of a share and the write of this request.
 */
final class RacingShareRepository implements ShareRepositoryInterface, MaxReadsRaisingShareRepositoryInterface, ExpirationExtendingShareRepositoryInterface, RevokingShareRepositoryInterface
{
    /** @var (Closure(string): void)|null */
    public ?Closure $beforeWrite = null;

    public function __construct(private readonly DoctrineOrmShareRepository $inner)
    {
    }

    public function find(string $id): ?SecureShare
    {
        return $this->inner->find($id);
    }

    public function consumeReadIfAvailable(string $id): ?SecureShare
    {
        return $this->inner->consumeReadIfAvailable($id);
    }

    public function findByCreator(object $creator): array
    {
        return $this->inner->findByCreator($creator);
    }

    public function countByCreator(object $creator): int
    {
        return $this->inner->countByCreator($creator);
    }

    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        return $this->inner->findByCreatorPaginated($creator, $limit, $offset);
    }

    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int
    {
        return $this->inner->removeByCreatorOlderThan($creator, $before);
    }

    public function removeAllByCreator(object $creator): int
    {
        return $this->inner->removeAllByCreator($creator);
    }

    public function removeOlderThan(DateTimeImmutable $before): int
    {
        return $this->inner->removeOlderThan($before);
    }

    public function persist(SecureShare $share): void
    {
        $this->inner->persist($share);
    }

    public function remove(SecureShare $share): void
    {
        $this->inner->remove($share);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function raiseMaxReads(SecureShare $share, int $maxReads): bool
    {
        $this->race($share);

        return $this->inner->raiseMaxReads($share, $maxReads);
    }

    public function extendExpiration(SecureShare $share, DateTimeImmutable $expiresAt): bool
    {
        $this->race($share);

        return $this->inner->extendExpiration($share, $expiresAt);
    }

    public function revokeIfActive(SecureShare $share): bool
    {
        $this->race($share);

        return $this->inner->revokeIfActive($share);
    }

    private function race(SecureShare $share): void
    {
        $hook              = $this->beforeWrite;
        $this->beforeWrite = null;
        if ($hook !== null) {
            $hook($share->getId());
        }
    }
}
