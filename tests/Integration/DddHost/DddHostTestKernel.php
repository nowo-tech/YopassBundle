<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use Nowo\YopassBundle\YopassBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function array_replace;
use function md5;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host kernel for REQ-ARCH-001: Doctrine ORM on SQLite with the bundle on its default ORM driver. The DDD variant maps
 * the host aggregate and the bundle entities by XML only (`database.mapping: xml`), stores the creator as an id
 * (`user_mapping: id`) and plugs a framework-free access policy; the standard variant keeps the attribute mapping.
 */
final class DddHostTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $yopass extra `nowo_yopass` configuration
     */
    public function __construct(private readonly bool $ddd, private readonly array $yopass = [])
    {
        parent::__construct('test', false);
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-yopass-ddd-host';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new YopassBundle();
    }

    /** Outside the repository: the kernel would otherwise generate config/reference.php in the package. */
    public function getProjectDir(): string
    {
        return self::baseDir();
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . md5(serialize([$this->ddd, $this->yopass])) . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'ddd-host-test-secret',
            'test'                 => true,
            'http_method_override' => false,
            'router'               => ['utf8' => true],
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);
        $container->extension('security', [
            'providers' => ['users' => ['memory' => null]],
            'firewalls' => ['main' => ['stateless' => true, 'provider' => 'users']],
        ]);
        $orm = $this->ddd ? ['mappings' => ['DddHost' => [
            'type'      => 'xml',
            'is_bundle' => false,
            'dir'       => __DIR__ . '/config/doctrine',
            'prefix'    => __NAMESPACE__ . '\Domain',
        ]]] : ['mappings' => ['YopassTestUsers' => [
            'type'      => 'attribute',
            'is_bundle' => false,
            'dir'       => __DIR__ . '/../../Support/Orm',
            'prefix'    => 'Nowo\YopassBundle\Tests\Support\Orm',
        ]]];
        if (PHP_VERSION_ID >= 80400) {
            $orm['enable_native_lazy_objects'] = true;
        }
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/ddd-host.sqlite'],
            'orm'  => $orm,
        ]);
        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(MemberSharePolicy::class)->public();
        $container->extension('nowo_yopass', array_replace($this->ddd ? [
            'user_class'   => TestUser::class,
            'user_mapping' => 'id',
            'database'     => ['mapping' => 'xml', 'platform' => 'sqlite'],
            'security'     => ['access_checker' => MemberSharePolicy::class],
        ] : [
            'user_class' => GuardUser::class,
            'database'   => ['platform' => 'sqlite'],
        ], $this->yopass));
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'nowo_yopass');
    }
}
