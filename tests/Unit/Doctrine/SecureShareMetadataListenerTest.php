<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SecureShareMetadataListenerTest extends TestCase
{
    public function testAppliesTableNameAndUserClass(): void
    {
        $metadata                                 = new ClassMetadata(SecureShare::class);
        $metadata->table                          = ['name' => 'yopass_secure_shares'];
        $metadata->associationMappings['creator'] = [
            'fieldName'    => 'creator',
            'targetEntity' => \Symfony\Component\Security\Core\User\UserInterface::class,
        ];

        $args = $this->createMock(LoadClassMetadataEventArgs::class);
        $args->method('getClassMetadata')->willReturn($metadata);

        (new SecureShareMetadataListener('vault_secure_shares', 'App\\Entity\\User', 'vault_share_access_logs'))->loadClassMetadata($args);

        self::assertSame('vault_secure_shares', $metadata->table['name']);
        self::assertSame('App\\Entity\\User', $metadata->associationMappings['creator']['targetEntity']);
    }

    public function testAppliesAccessLogTableName(): void
    {
        $metadata        = new ClassMetadata(ShareAccessLog::class);
        $metadata->table = ['name' => 'yopass_share_access_logs'];

        $args = $this->createMock(LoadClassMetadataEventArgs::class);
        $args->method('getClassMetadata')->willReturn($metadata);

        (new SecureShareMetadataListener('vault_secure_shares', 'App\\Entity\\User', 'vault_share_access_logs'))->loadClassMetadata($args);

        self::assertSame('vault_share_access_logs', $metadata->table['name']);
    }

    public function testIgnoresOtherEntities(): void
    {
        $metadata        = new ClassMetadata(stdClass::class);
        $metadata->table = ['name' => 'std'];

        $args = $this->createMock(LoadClassMetadataEventArgs::class);
        $args->method('getClassMetadata')->willReturn($metadata);

        (new SecureShareMetadataListener('custom', 'App\\Entity\\User'))->loadClassMetadata($args);

        self::assertSame('std', $metadata->table['name']);
    }

    public function testSkipsCreatorMappingWhenMissing(): void
    {
        $metadata        = new ClassMetadata(SecureShare::class);
        $metadata->table = ['name' => 'yopass_secure_shares'];

        $args = $this->createMock(LoadClassMetadataEventArgs::class);
        $args->method('getClassMetadata')->willReturn($metadata);

        (new SecureShareMetadataListener('custom_table', 'App\\Entity\\User'))->loadClassMetadata($args);

        self::assertSame('custom_table', $metadata->table['name']);
        self::assertArrayNotHasKey('creator', $metadata->associationMappings);
    }
}
