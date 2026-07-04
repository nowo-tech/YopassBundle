<?php

declare(strict_types=1);

namespace App\YopassDemo\Local;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

/**
 * Persists file-share ciphertext on disk; keeps a pointer in the relational store.
 */
final class LocalOffloadingShareRepository implements ShareRepositoryInterface
{
    public function __construct(
        private readonly ShareRepositoryInterface $inner,
        private readonly LocalCiphertextStore $store,
    ) {
    }

    public function find(string $id): ?SecureShare
    {
        $share = $this->inner->find($id);

        if (!$share instanceof SecureShare) {
            return null;
        }

        if ($this->store->isReference($share->getCiphertext())) {
            $share->setCiphertext($this->store->download($share->getCiphertext()));
        }

        return $share;
    }

    /** @return list<SecureShare> */
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

    public function removeByCreatorOlderThan(object $creator, \DateTimeImmutable $before): int
    {
        return $this->inner->removeByCreatorOlderThan($creator, $before);
    }

    public function removeAllByCreator(object $creator): int
    {
        return $this->inner->removeAllByCreator($creator);
    }

    public function removeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->inner->removeOlderThan($before);
    }

    public function persist(SecureShare $share): void
    {
        if ($share->getPayloadKind() === 'file' && !$this->store->isReference($share->getCiphertext())) {
            $reference = $this->store->upload($share->getId(), $share->getCiphertext());
            $share->setCiphertext($reference);
        }

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
}
