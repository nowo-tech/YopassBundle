<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost\CustomDriver;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Repository\ExpirationExtendingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\MaxReadsRaisingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

/**
 * A `database.driver: custom` repository that decorates the bundle's ORM repository (the demo's file-offloading
 * pattern, docs/examples): every call is handed to the bundle's repository, which works on the bundle's private entity
 * manager, while the creator resolver of the custom driver passes the users as given (2.0.1 regression test).
 */
final readonly class DecoratingShareRepository implements ShareRepositoryInterface, MaxReadsRaisingShareRepositoryInterface, ExpirationExtendingShareRepositoryInterface
{
    public function __construct(private DoctrineOrmShareRepository $inner)
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
        return $this->inner->raiseMaxReads($share, $maxReads);
    }

    public function extendExpiration(SecureShare $share, DateTimeImmutable $expiresAt): bool
    {
        return $this->inner->extendExpiration($share, $expiresAt);
    }
}
