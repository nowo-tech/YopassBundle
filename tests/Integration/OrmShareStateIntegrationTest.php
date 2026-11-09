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
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The ORM path keeps read counts and revocation dates across saves (the MongoDB path did not, DDD audit 2026-11-09).
 */
final class OrmShareStateIntegrationTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-0000000000e1';

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
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-state-proxies');
        $this->enableLazyObjects($config);

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->creator = new GuardUser(1, 'alice');
        $this->em->persist($this->creator);
        $this->em->flush();

        $this->repository = new DoctrineOrmShareRepository($this->em);
    }

    public function testExtendingAfterReadsKeepsTheReadsLeft(): void
    {
        $share = new SecureShare(self::ID, $this->creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);
        $this->repository->persist($share);
        $this->repository->flush();

        self::assertNotNull($this->repository->consumeReadIfAvailable(self::ID));
        self::assertNotNull($this->repository->consumeReadIfAvailable(self::ID));

        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);
        (new ShareExtender($this->repository, DefaultShareOptions::get()))->extend($loaded, '24h', null);
        $this->em->clear();

        self::assertSame(1, $this->repository->find(self::ID)?->getReadsLeft());
    }

    public function testRevocationDateIsKeptAcrossSaves(): void
    {
        $share = new SecureShare(self::ID, $this->creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);
        $this->repository->persist($share);
        $this->repository->flush();

        (new ShareRevoker($this->repository))->revoke($share);
        $revokedAt = $share->getRevokedAt()?->format('Y-m-d H:i:s');
        $this->em->clear();

        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);
        $this->repository->persist($loaded);
        $this->repository->flush();
        $this->em->clear();

        $again = $this->repository->find(self::ID);
        self::assertSame($revokedAt, $again?->getRevokedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(0, $again?->getReadsLeft());
        self::assertNull($this->repository->consumeReadIfAvailable(self::ID));
    }

    private function enableLazyObjects(Configuration $config): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
    }
}
