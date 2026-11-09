<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * DDD ports (1.16): a security user that is no Doctrine entity creates and lists shares through the creator reference
 * resolver; raising max reads is one conditional UPDATE (JS-L1).
 */
final class OrmDddPortsIntegrationTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-0000000000f1';

    private EntityManager $em;

    private DoctrineOrmShareRepository $repository;

    private GuardUser $creator;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-ddd-proxies');
        $this->enableLazyObjects($config);

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->creator = new GuardUser(7, 'alice');
        $this->em->persist($this->creator);
        $this->em->flush();
        $this->em->clear();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $creators         = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'default');
        $this->repository = new DoctrineOrmShareRepository($this->em, null, null, null, $creators);
    }

    public function testANonEntitySecurityUserCreatesListsAndDeletesShares(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $creators   = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'default');
        $dispatcher = new EventDispatcher();
        $ids        = [];
        $dispatcher->addListener(YopassEvents::SHARE_CREATED, static function (ShareCreatedEvent $event) use (&$ids): void {
            $ids[] = $event->getCreatorId();
        });
        $viewer = new SecurityUserDto(7, 'alice');

        $share = (new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, $dispatcher, $creators))
            ->create($viewer, $this->data());
        self::assertInstanceOf(GuardUser::class, $share->getCreator(), 'A reference of user_class, not the DTO');
        self::assertSame('7', $share->getCreatorId());
        self::assertSame(['7'], $ids);
        $this->em->clear();

        self::assertSame(1, $this->repository->countByCreator($viewer));
        self::assertSame($share->getId(), $this->repository->findByCreator($viewer)[0]->getId());
        self::assertCount(1, $this->repository->findByCreatorPaginated($viewer, 10, 0));
        self::assertSame(0, $this->repository->countByCreator(new SecurityUserDto(8)));
        self::assertSame(0, $this->repository->removeByCreatorOlderThan($viewer, new DateTimeImmutable('-1 day')));
        self::assertSame(1, $this->repository->removeAllByCreator($viewer));
        self::assertSame(0, $this->repository->countByCreator($viewer));
    }

    public function testRaisingMaxReadsKeepsConcurrentConsumes(): void
    {
        $this->saveShare(3);
        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);

        // Another worker consumes two reads after this request loaded the share.
        $this->em->getConnection()->executeStatement('UPDATE yopass_secure_shares SET reads_left = reads_left - 2 WHERE id = ?', [self::ID]);

        (new ShareExtender($this->repository, DefaultShareOptions::get()))->extend($loaded, '24h', 10);
        self::assertSame(10, $loaded->getMaxReads());
        self::assertSame(8, $loaded->getReadsLeft(), 'The event sees the stored values');
        $this->em->clear();

        $stored = $this->repository->find(self::ID);
        self::assertSame(10, $stored?->getMaxReads());
        self::assertSame(8, $stored?->getReadsLeft(), '1 left + 7 added, the two consumed reads are not given back');
    }

    public function testARevocationMeanwhileStopsTheRaise(): void
    {
        $this->saveShare(3);
        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);
        $expiresAt = $loaded->getExpiresAt();
        $this->em->getConnection()->executeStatement('UPDATE yopass_secure_shares SET revoked_at = ?, reads_left = 0 WHERE id = ?', ['2026-01-01 00:00:00', self::ID]);

        try {
            (new ShareExtender($this->repository, DefaultShareOptions::get()))->extend($loaded, '24h', 10);
            self::fail('Expected ShareExtendException');
        } catch (ShareExtendException $exception) {
            self::assertSame('revoked', $exception->errorCode);
        }
        $this->em->clear();

        $stored = $this->repository->find(self::ID);
        self::assertSame(3, $stored?->getMaxReads());
        self::assertSame(0, $stored?->getReadsLeft());
        self::assertSame($expiresAt->format('U'), $stored?->getExpiresAt()->format('U'), 'Nothing extended');
    }

    public function testAMaxReadsChangedMeanwhileStopsTheRaise(): void
    {
        $this->saveShare(3);
        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);
        $this->em->getConnection()->executeStatement('UPDATE yopass_secure_shares SET max_reads = 5 WHERE id = ?', [self::ID]);

        $this->expectException(ShareExtendException::class);
        $this->expectExceptionMessage('max_reads_not_increased');
        (new ShareExtender($this->repository, DefaultShareOptions::get()))->extend($loaded, null, 10);
    }

    public function testRaiseRefusesALowerValue(): void
    {
        $this->saveShare(3);
        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);

        self::assertFalse($this->repository->raiseMaxReads($loaded, 3));
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = 3;

        return $data;
    }

    private function saveShare(int $maxReads): void
    {
        $share = new SecureShare(self::ID, $this->em->getReference(GuardUser::class, 7));
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads($maxReads);
        $this->repository->persist($share);
        $this->repository->flush();
        $this->em->clear();
    }

    private function enableLazyObjects(Configuration $config): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
    }
}
