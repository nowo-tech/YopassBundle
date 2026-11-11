<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as BundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Integration\DddHost\Domain\Ledger;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function array_map;
use function explode;
use function extension_loaded;
use function implode;
use function preg_match;
use function sort;

/**
 * REQ-ARCH-001 (1.19.0), both host kinds on a real kernel:
 *
 * - standard host: zero-config attribute mapping, creator relation to the host user entity, and the bundle's own
 *   entity manager by default (rule 6);
 * - DDD host: the host aggregate and the bundle entities mapped by XML only, creator stored by id, a framework-free
 *   access policy receiving a UserRef (rule 3), and the same rule-6 guarantee.
 */
final class DddHostKernelTest extends TestCase
{
    /** @var list<DddHostTestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(DddHostTestKernel::baseDir());
    }

    public function testTheXmlMappingCreatesTheSameSchemaAsTheAttributeMapping(): void
    {
        $attribute = $this->container(false);
        $xml       = $this->container(false, ['database' => ['platform' => 'sqlite', 'mapping' => 'xml']]);

        // Same tables, columns, keys and indexes (the XML driver lists the id column after the fields: order only).
        self::assertSame($this->schemaSql($attribute), $this->schemaSql($xml));
        self::assertCount(8, $this->schemaSql($xml));
        $drivers = $this->em($xml)->getConfiguration()->getMetadataDriverImpl();
        if ($drivers instanceof BundleMappingDriver) {
            $drivers = $drivers->getDriver();
        }
        self::assertInstanceOf(MappingDriverChain::class, $drivers);
        self::assertInstanceOf(XmlDriver::class, $drivers->getDrivers()['Nowo\YopassBundle\Entity'] ?? null);
    }

    public function testTheStandardHostUsesThePrivateManagerByDefault(): void
    {
        $container = $this->container(false);
        $em        = $this->em($container);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->persist(new GuardUser(7, 'alice'));
        $em->flush();

        $alice = $em->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $alice->name = 'pending';
        $em->persist(new GuardUser(8, 'bob'));

        $share = $this->service($container, ShareCreator::class, ShareCreator::class)->create($alice, $this->data());
        self::assertSame('7', $share->getCreatorId());
        self::assertSame('ok', $this->service($container, ShareRetriever::class, ShareRetriever::class)->consume($share->getId())['status']);

        $db = $em->getConnection();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));
        self::assertSame('alice', $db->fetchOne('SELECT name FROM guard_users WHERE id = 7'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM guard_users WHERE id = 8'));
        self::assertTrue($container->has(YopassEntityManager::class));
        self::assertNotSame($em, $this->service($container, YopassEntityManager::class, YopassEntityManager::class)->get());
    }

    public function testTheLegacySwitchKeepsTheHostManager(): void
    {
        $container = $this->container(false, ['database' => ['platform' => 'sqlite', 'private_entity_manager' => false]]);

        self::assertFalse($container->has(YopassEntityManager::class));
    }

    public function testTheDddHostWritesThroughPortsWithoutTouchingItsUnitOfWork(): void
    {
        $container = $this->container(true);
        $em        = $this->em($container);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $metadata = $em->getClassMetadata(SecureShare::class);
        self::assertTrue($metadata->hasField('creator'), 'Creator stored by id');

        $ledger = new Ledger('l-1', 'draft');
        $em->persist($ledger);

        $member = new TestUser('7', 'member@example.com');
        $share  = $this->service($container, ShareCreator::class, ShareCreator::class)->create($member, $this->data());
        $shares = $this->service($container, ShareRepositoryInterface::class, ShareRepositoryInterface::class);
        self::assertSame(1, $shares->countByCreator($member));

        $db = $em->getConnection();
        self::assertSame('7', $db->fetchOne('SELECT creator_id FROM yopass_secure_shares WHERE id = ?', [$share->getId()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM ddd_ledger'), 'The pending aggregate was not flushed');
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($ledger));
        $em->flush();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ddd_ledger'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));

        $decider = $this->service($container, YopassAccessDecider::class, YopassAccessDecider::class);
        $policy  = $this->service($container, MemberSharePolicy::class, MemberSharePolicy::class);
        self::assertTrue($decider->usesPolicy());
        self::assertTrue($decider->allows('create', null, $member));
        self::assertTrue($decider->allows('list', $member));
        self::assertFalse($decider->allows('revoke', $member));
        self::assertFalse($decider->allows('access', null, new TestUser('8')));
        self::assertFalse($decider->allows('access'));
        self::assertSame(['7', '7', '8', null], $policy->seen);
    }

    /**
     * @param array<string, mixed> $yopass
     */
    private function container(bool $ddd, array $yopass = []): ContainerInterface
    {
        $kernel = new DddHostTestKernel($ddd, $yopass);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $this->service($kernel->getContainer(), 'test.service_container', ContainerInterface::class);
    }

    private function em(ContainerInterface $container): EntityManagerInterface
    {
        return $this->service($container, 'doctrine.orm.entity_manager', EntityManagerInterface::class);
    }

    /**
     * @return list<string>
     */
    private function schemaSql(ContainerInterface $container): array
    {
        $em = $this->em($container);

        $sql = (new SchemaTool($em))->getCreateSchemaSql([
            $em->getClassMetadata(GuardUser::class),
            $em->getClassMetadata(SecureShare::class),
            $em->getClassMetadata(ShareAccessLog::class),
        ]);

        return array_map(static function (string $statement): string {
            if (preg_match('/^(CREATE TABLE \S+ \()(.*)\)$/', $statement, $match) !== 1) {
                return $statement;
            }
            $parts = explode(', ', $match[2]);
            sort($parts);

            return $match[1] . implode(', ', $parts) . ')';
        }, $sql);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function service(ContainerInterface $container, string $id, string $type): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($type, $service);

        return $service;
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = 1;

        return $data;
    }
}
