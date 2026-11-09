<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Creator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Default creator reference resolver: user_class instances unchanged, other users with an id become references.
 */
final class DoctrineCreatorReferenceResolverTest extends TestCase
{
    public function testUserClassInstancesAndMissingManagerKeepTheUser(): void
    {
        $user = new GuardUser(1, 'alice');
        self::assertSame($user, (new DoctrineCreatorReferenceResolver(GuardUser::class, $this->createStub(ManagerRegistry::class)))->reference($user));

        $dto = new SecurityUserDto(1);
        self::assertSame($dto, (new DoctrineCreatorReferenceResolver(GuardUser::class))->reference($dto), 'Custom driver: no manager');
    }

    public function testANonEntityUserBecomesAReferenceWithItsRawId(): void
    {
        $reference = new GuardUser(5, 'ref');
        $manager   = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('getReference')->with(GuardUser::class, 5)->willReturn($reference);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManager')->with('shares')->willReturn($manager);

        $resolver = new DoctrineCreatorReferenceResolver('\\' . GuardUser::class, $registry, 'shares');
        self::assertSame($reference, $resolver->reference(new SecurityUserDto(5)));
        self::assertSame('5', $resolver->idOf(new SecurityUserDto(5)));
        self::assertSame('viewer', $resolver->idOf(new TestUser('viewer')));
        self::assertNull($resolver->idOf(new stdClass()));
    }

    public function testUsersWithoutIdOrManagersWithoutReferencesKeepTheUser(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $resolver = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry);

        $object = new stdClass();
        self::assertSame($object, $resolver->reference($object));
        $empty = new SecurityUserDto('');
        self::assertSame($empty, $resolver->reference($empty));
        $dto = new SecurityUserDto(3);
        self::assertSame($dto, $resolver->reference($dto), 'Manager without getReference()');
    }

    public function testANonObjectReferenceKeepsTheUser(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getReference')->willReturn(null);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        $dto = new SecurityUserDto(3);
        self::assertSame($dto, (new DoctrineCreatorReferenceResolver(GuardUser::class, $registry))->reference($dto));
    }
}
