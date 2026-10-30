<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Routing;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\InMemoryShareRepository;
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

use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host-like kernel for REQ-SF-004 rule 7. Its routing is the Symfony default `routing.controllers` resource
 * (what `config/routes.yaml` of a Symfony >= 7.3 skeleton loads) and, only when $importBundleRoutes is true,
 * the documented `type: nowo_yopass` route loader import. Shares live in an in-memory repository (custom driver).
 */
final class RoutingTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly bool $importBundleRoutes)
    {
        parent::__construct('test', true);
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
        return sys_get_temp_dir() . '/nowo-yopass-routing';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/' . ($this->importBundleRoutes ? 'imported' : 'controllers-only') . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'routing-test-secret',
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
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/routing-test.sqlite'],
            'orm'  => [],
        ]);
        if (PHP_VERSION_ID >= 80400) {
            // PHP 8.4+ with Doctrine Bundle 2 (Symfony 7.4 jobs): native lazy objects (default in Doctrine Bundle 3).
            $container->extension('doctrine', ['orm' => ['enable_native_lazy_objects' => true]]);
        }
        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(InMemoryShareRepository::class)->public();
        $container->extension('nowo_yopass', [
            'user_class' => TestUser::class,
            'database'   => ['driver' => 'custom', 'repository' => InMemoryShareRepository::class],
        ]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');

        if ($this->importBundleRoutes) {
            $routes->import('.', 'nowo_yopass');
        }
    }
}
