<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\InMemoryShareRepository;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * JX-Y6: with the custom driver (no Doctrine manager) the accepted-class check applies as with ORM / ODM, on create,
 * list, delete all and the retention purge.
 */
final class CustomDriverAcceptedClassesTest extends TestCase
{
    public function testUnlistedUsersCannotCreateAndListedOnesPassAsGiven(): void
    {
        $repository       = new InMemoryShareRepository();
        $resolver         = new DoctrineCreatorReferenceResolver(GuardUser::class, null, null, new CreatorClassPolicy(GuardUser::class, [SecurityUserDto::class]));
        $creator          = new ShareCreator($repository, DefaultShareOptions::get()['expiration_options'], null, null, $resolver);
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $refused          = [];

        $wrapper = new SecurityUserDto(5);
        self::assertSame($wrapper, $creator->create($wrapper, $data)->getCreator());
        $lister = new ShareLister($repository, new EventDispatcher(), GuardUser::class, $resolver);
        self::assertSame(1, $lister->list($wrapper, 10, 1)['total']);
        $deleter = new ShareDeleter($repository, null, GuardUser::class, null, $resolver);
        $purger  = new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '1 day']], null, null, null, $resolver);

        $other = new TestUser('5');
        foreach ([
            'create' => static fn (): mixed => $creator->create($other, $data),
            'list'   => static fn (): mixed => $lister->list($other, 10, 1),
            'delete' => static fn (): mixed => $deleter->deleteAll($other),
            'purge'  => static fn (): mixed => $purger->purgeForCreator($other),
        ] as $path => $call) {
            try {
                $call();
                self::fail($path . ' should be refused');
            } catch (AccessDeniedException) {
                $refused[] = $path;
            }
        }
        self::assertSame(['create', 'list', 'delete', 'purge'], $refused);
        self::assertSame(1, $repository->countByCreator($wrapper), 'Nothing removed');
    }
}
