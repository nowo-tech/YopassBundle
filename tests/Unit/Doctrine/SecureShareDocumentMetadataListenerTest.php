<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Doctrine;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Event\LoadClassMetadataEventArgs;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Document\SecureShareDocument;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SecureShareDocumentMetadataListenerTest extends TestCase
{
    public function testAppliesCollectionNameAndUserClass(): void
    {
        $metadata                                 = new ClassMetadata(SecureShareDocument::class);
        $metadata->associationMappings['creator'] = [
            'fieldName'      => 'creator',
            'targetDocument' => \Symfony\Component\Security\Core\User\UserInterface::class,
        ];

        $args = new LoadClassMetadataEventArgs(
            $metadata,
            $this->createMock(DocumentManager::class),
        );

        (new SecureShareDocumentMetadataListener('yopass_secure_shares', 'App\\Entity\\User'))->loadClassMetadata($args);

        self::assertSame('yopass_secure_shares', $metadata->collection);
        self::assertSame('App\\Entity\\User', $metadata->associationMappings['creator']['targetDocument']);
    }

    public function testIgnoresOtherDocuments(): void
    {
        $metadata = new ClassMetadata(stdClass::class);

        $args = new LoadClassMetadataEventArgs(
            $metadata,
            $this->createMock(DocumentManager::class),
        );

        (new SecureShareDocumentMetadataListener('custom', 'App\\Entity\\User'))->loadClassMetadata($args);

        self::assertArrayNotHasKey('creator', $metadata->associationMappings);
    }
}
