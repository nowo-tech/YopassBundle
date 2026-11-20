<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Doctrine\YopassDocumentManager;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;

/**
 * The creator resolver and the event creator hand MongoDB ODM work to the private document manager (1.22.0); its own
 * behaviour is covered on a real server (tests/Integration/Mongo/PrivateDocumentManagerIntegrationTest). Without
 * ext-mongodb, a source manager that is not a document manager is refused before anything is created.
 */
final class YopassDocumentManagerWiringTest extends TestCase
{
    public function testTheResolverAndTheEventCreatorDelegateToThePrivateDocumentManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $own = new YopassDocumentManager($registry, 'shares');

        self::assertFalse($own->isCreated());
        self::assertSame(0, $own->pendingCount());
        $own->clear();
        $own->reset();

        $resolver = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'shares', new CreatorClassPolicy(GuardUser::class), ownManager: $own);
        try {
            $resolver->reference(new GuardUser(7, 'alice'));
            self::fail('A source that is not a document manager must be refused.');
        } catch (LogicException $exception) {
            self::assertSame('nowo_yopass: document manager "shares" is not a Doctrine MongoDB ODM document manager.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        (new HostCreatorReference($own))->forHost(new GuardUser(7, 'alice'));
    }
}
