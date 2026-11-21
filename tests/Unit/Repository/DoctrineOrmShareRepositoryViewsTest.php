<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Share\ShareView;
use PHPUnit\Framework\TestCase;

/**
 * 2.1.0: the scalar share views of the Doctrine ORM repository (relation mapping, identifier types, raw date values).
 */
final class DoctrineOrmShareRepositoryViewsTest extends TestCase
{
    /** @var list<mixed> */
    private array $boundValues = [];

    public function testRelationCreatorIdsOfAStringKeyAreQueriedAsGivenAndReCheckedExactly(): void
    {
        $repository = new DoctrineOrmShareRepository($this->entityManager('string', [
            $this->row('s-1', 'abc'),
            $this->row('s-2', 'AbC'),
            $this->row('s-3', null),
        ]));
        $now = new DateTimeImmutable('2026-11-21 12:00:00');

        $views = $repository->viewsByCreatorIds(['abc'], $now);
        self::assertSame(['abc'], $this->boundValues);
        self::assertCount(1, $views);
        self::assertSame('s-1', $views[0]->id);
        self::assertSame('2026-11-21 10:00:00', $views[0]->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(ShareView::STATE_REVOKED, $views[0]->state);
        self::assertSame(2, $views[0]->maxReads);
    }

    public function testRelationCreatorIdsOfAnIntegerKeyAreCastAndLookAlikesDropped(): void
    {
        $repository = new DoctrineOrmShareRepository($this->entityManager('integer', [$this->row('s-1', 7)]));

        self::assertCount(1, $repository->viewsByCreatorIds(['7', '07', 'abc', '-1'], new DateTimeImmutable()));
        self::assertSame([7], $this->boundValues);
        self::assertSame([], (new DoctrineOrmShareRepository($this->entityManager('integer', [])))->viewsByCreatorIds(['abc'], new DateTimeImmutable()));
    }

    public function testACompositeCreatorKeyIsQueriedWithTheIdsAsGiven(): void
    {
        $repository = new DoctrineOrmShareRepository($this->entityManager(null, [$this->row('s-1', 'a')]));

        self::assertCount(1, $repository->viewsByCreatorIds(['a'], new DateTimeImmutable()));
        self::assertSame(['a'], $this->boundValues);
    }

    public function testViewsByIdsReCheckTheIdExactly(): void
    {
        $repository = new DoctrineOrmShareRepository($this->entityManager('string', [$this->row('s-1', 'abc'), $this->row('S-1', 'abc')]));

        $views = $repository->viewsByIds(['s-1'], new DateTimeImmutable());
        self::assertSame(['s-1'], [$views[0]->id]);
        self::assertCount(1, $views);
        self::assertSame(0, $repository->removeByIds([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, int|string|null $creator): array
    {
        return [
            'id'          => $id,
            'creator'     => $creator,
            'createdAt'   => '2026-11-21 10:00:00',
            'expiresAt'   => new DateTimeImmutable('2026-11-22 10:00:00'),
            'maxReads'    => '2',
            'readsLeft'   => 1,
            'payloadKind' => 'text',
            'revokedAt'   => '2026-11-21 11:00:00',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function entityManager(?string $identifierType, array $rows): EntityManagerInterface
    {
        $shareMetadata = $this->createMock(ClassMetadata::class);
        $shareMetadata->method('hasField')->with('creator')->willReturn(false);
        $shareMetadata->method('getAssociationTargetClass')->with('creator')->willReturn('App\User');
        $userMetadata = $this->createMock(ClassMetadata::class);
        $userMetadata->method('getIdentifierFieldNames')->willReturn($identifierType === null ? ['tenant', 'id'] : ['id']);
        $userMetadata->method('getTypeOfField')->willReturn($identifierType);

        $query = $this->getMockBuilder(Query::class)->disableOriginalConstructor()->onlyMethods(['getArrayResult'])->getMock();
        $query->method('getArrayResult')->willReturn($rows);
        $builder = $this->getMockBuilder(QueryBuilder::class)->disableOriginalConstructor()->onlyMethods(['select', 'from', 'where', 'setParameter', 'getQuery'])->getMock();
        $builder->method('select')->willReturnSelf();
        $builder->method('from')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use ($builder): QueryBuilder {
            $this->boundValues = $value;

            return $builder;
        });
        $builder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => $class === SecureShare::class ? $shareMetadata : $userMetadata);
        $entityManager->method('createQueryBuilder')->willReturn($builder);

        return $entityManager;
    }
}
