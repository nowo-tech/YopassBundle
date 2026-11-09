<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Creator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Default creator reference resolver: user_class instances unchanged, listed wrapper classes become references, any
 * other user is refused (audit JX-Y1).
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

    public function testAnAcceptedWrapperBecomesAReferenceWithItsRawId(): void
    {
        $reference = new GuardUser(5, 'ref');
        $manager   = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('getReference')->with(GuardUser::class, 5)->willReturn($reference);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManager')->with('shares')->willReturn($manager);

        $resolver = new DoctrineCreatorReferenceResolver('\\' . GuardUser::class, $registry, 'shares', new CreatorClassPolicy(GuardUser::class, ['\\' . SecurityUserDto::class]));
        self::assertSame($reference, $resolver->reference(new SecurityUserDto(5)));
        self::assertSame('5', $resolver->idOf(new SecurityUserDto(5)));
        self::assertSame('viewer', $resolver->idOf(new TestUser('viewer')));
        self::assertNull($resolver->idOf(new stdClass()));
    }

    public function testAnUnlistedClassIsRefusedByDefault(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $resolver = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry);

        $this->expectException(AccessDeniedException::class);
        $resolver->reference(new SecurityUserDto(5));
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function refusedUsers(): iterable
    {
        yield 'no getId()' => [new stdClass()];
        yield 'empty id' => [new SecurityUserDto('')];
        yield 'zero' => [new SecurityUserDto(0)];
        yield 'negative' => [new SecurityUserDto(-5)];
        yield 'numeric string zero' => [new SecurityUserDto('0')];
        yield 'negative numeric string' => [new SecurityUserDto('-3')];
    }

    #[DataProvider('refusedUsers')]
    public function testUnusableIdsAreRefused(object $user): void
    {
        $resolver = new DoctrineCreatorReferenceResolver(GuardUser::class, $this->createStub(ManagerRegistry::class), null, new CreatorClassPolicy(GuardUser::class, [SecurityUserDto::class, stdClass::class]));

        $this->expectException(AccessDeniedException::class);
        $resolver->reference($user);
    }

    public function testManagersWithoutReferencesOrANonObjectReferenceKeepTheUser(): void
    {
        $policy   = new CreatorClassPolicy(GuardUser::class, [SecurityUserDto::class]);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $dto = new SecurityUserDto(3);
        self::assertSame($dto, (new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, null, $policy))->reference($dto), 'Manager without getReference()');

        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getReference')->willReturn(null);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        self::assertSame($dto, (new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, null, $policy))->reference($dto));
    }
}
