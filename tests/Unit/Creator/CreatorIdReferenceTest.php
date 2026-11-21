<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Creator;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * 2.1.0: a CreatorId handed to the id-based ports names the `user_class` user with that id under the relation mapping.
 */
final class CreatorIdReferenceTest extends TestCase
{
    public function testTheCustomDriverReceivesTheCreatorIdAsGiven(): void
    {
        $id = new CreatorId('7');

        self::assertSame($id, (new DoctrineCreatorReferenceResolver(GuardUser::class))->reference($id));
    }

    public function testAnIntegerKeyTakesADecimalIdAsAnInteger(): void
    {
        $reference = new stdClass();
        $resolver  = $this->ormResolver(['id'], 'integer', static function (string $class, mixed $id) use ($reference): object {
            self::assertSame(GuardUser::class, $class);
            self::assertSame(7, $id);

            return $reference;
        });

        self::assertSame($reference, $resolver->reference(new CreatorId('7')));
    }

    public function testAStringKeyTakesTheIdAsGiven(): void
    {
        $reference = new stdClass();
        $resolver  = $this->ormResolver(['uuid'], 'string', static function (string $class, mixed $id) use ($reference): object {
            self::assertSame('abc', $id);

            return $reference;
        });

        self::assertSame($reference, $resolver->reference(new CreatorId('abc')));
    }

    public function testANonDecimalIdForAnIntegerKeyIsRefusedNeverCast(): void
    {
        $resolver = $this->ormResolver(['id'], 'integer', static fn (): object => self::fail('No reference'));

        foreach (['07', 'abc', '7.0', '-7'] as $id) {
            try {
                $resolver->reference(new CreatorId($id));
                self::fail('Refused: ' . $id);
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testACompositeKeyIsRefused(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->ormResolver(['tenant', 'id'], 'integer', static fn (): object => self::fail('No reference'))->reference(new CreatorId('7'));
    }

    public function testADocumentManagerGivesItsOwnReference(): void
    {
        $reference = new stdClass();
        $manager   = $this->createMock(DocumentManager::class);
        $manager->expects(self::once())->method('getReference')->with(GuardUser::class, '64b7f0c2a1b2c3d4e5f60718')->willReturn($reference);
        self::assertSame($reference, $this->resolverOn($manager)->reference(new CreatorId('64b7f0c2a1b2c3d4e5f60718')));

        $this->expectException(AccessDeniedException::class);
        $this->resolverOn($this->createMock(ObjectManager::class))->reference(new CreatorId('7'));
    }

    /**
     * @param list<string> $identifiers
     * @param callable(string, mixed): object $reference
     */
    private function ormResolver(array $identifiers, string $type, callable $reference): DoctrineCreatorReferenceResolver
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierFieldNames')->willReturn($identifiers);
        $metadata->method('getTypeOfField')->willReturn($type);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->method('getReference')->willReturnCallback($reference);

        return $this->resolverOn($manager);
    }

    private function resolverOn(ObjectManager $manager): DoctrineCreatorReferenceResolver
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'default');
    }
}
