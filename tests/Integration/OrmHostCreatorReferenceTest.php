<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Security\Core\User\UserInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 2.0.1 regression: the bundle's ORM repository on its private entity manager relates a share to a creator the
 * application's manager loaded through a reference of the private manager, also when no creator resolver converted it
 * first (a `database.driver: custom` repository decorating the bundle's one, a host alias of the resolver). On 2.0.0 the
 * flush failed with "A new entity was found through the relationship SecureShare#creator". The application's manager and
 * its user are never merged, detached, flushed or cleared.
 */
final class OrmHostCreatorReferenceTest extends TestCase
{
    private EntityManager $host;

    private YopassEntityManager $own;

    private DoctrineOrmShareRepository $shares;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-host-creator-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->host = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->host))->createSchema($this->host->getMetadataFactory()->getAllMetadata());
        $this->host->persist(new GuardUser(7, 'alice'));
        $this->host->flush();
        $this->host->clear();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->host);
        $this->own = new YopassEntityManager($registry, 'default');
        // No creator resolver: the users reach the repository as the application holds them.
        $this->shares = new DoctrineOrmShareRepository($this->host, null, null, null, null, $this->own);
    }

    public function testAShareOfAUserTheApplicationLoadedIsStoredAndQueriedThroughThePrivateManager(): void
    {
        $alice = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $alice->name = 'pending';

        $share = $this->share('00000000-0000-4000-8000-000000000001', $alice);
        $this->shares->persist($share);
        $this->shares->flush();

        self::assertNotSame($alice, $share->getCreator());
        self::assertTrue($this->own->get()->contains($share->getCreator()));
        self::assertSame('7', $share->getCreatorId());
        self::assertSame(1, (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));

        self::assertSame(1, $this->shares->countByCreator($alice));
        self::assertCount(1, $this->shares->findByCreator($alice));
        self::assertCount(1, $this->shares->findByCreatorPaginated($alice, 10, 0));
        self::assertSame(0, $this->shares->removeByCreatorOlderThan($alice, new DateTimeImmutable('-1 day')));
        self::assertSame(1, $this->shares->removeAllByCreator($alice));

        // The application's user is untouched: same managed instance, its pending change kept, nothing written.
        self::assertSame(UnitOfWork::STATE_MANAGED, $this->host->getUnitOfWork()->getEntityState($alice));
        self::assertSame($alice, $this->host->find(GuardUser::class, 7));
        self::assertSame('alice', $this->host->getConnection()->fetchOne('SELECT name FROM guard_users WHERE id = 7'));
        $this->host->flush();
        self::assertSame('pending', $this->host->getConnection()->fetchOne('SELECT name FROM guard_users WHERE id = 7'));
    }

    public function testAnUnmanagedUserWithAnIdIsReferencedByItsMappedIdentifier(): void
    {
        $detached = new GuardUser(7, 'alice');
        $share    = $this->share('00000000-0000-4000-8000-000000000002', $detached);
        $this->shares->persist($share);
        $this->shares->flush();

        self::assertNotSame($detached, $share->getCreator());
        self::assertSame('7', $share->getCreatorId());
        self::assertFalse($this->host->contains($detached), 'Never merged into the application\'s manager');
    }

    public function testOtherCreatorsAreLeftUnchanged(): void
    {
        // A user without an id yet: the flush reports it, the repository does not invent a reference.
        $unsaved = (new ReflectionClass(GuardUser::class))->newInstanceWithoutConstructor();
        $share   = $this->share('00000000-0000-4000-8000-000000000003', $unsaved);
        $this->shares->persist($share);
        self::assertSame($unsaved, $share->getCreator());

        // An object that is no `user_class` instance is never turned into a reference of another class.
        $dto   = new SecurityUserDto(7, 'alice');
        $other = $this->share('00000000-0000-4000-8000-000000000004', $dto);
        $this->shares->persist($other);
        self::assertSame($dto, $other->getCreator());
        $this->own->reset();

        // Without the private manager (a repository built by hand) the creator stays as given.
        $plain = new DoctrineOrmShareRepository($this->host);
        $alice = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $mine = $this->share('00000000-0000-4000-8000-000000000005', $alice);
        $plain->persist($mine);
        self::assertSame($alice, $mine->getCreator());
        $this->host->clear();
    }

    private function share(string $id, object $creator): SecureShare
    {
        $share = new SecureShare($id, $creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'));

        return $share;
    }
}
