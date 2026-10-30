<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Routing;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\InMemoryShareRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function class_exists;
use function dirname;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: the yopass routes are opt-in. A host whose routing is only the Symfony default
 * `routing.controllers` resource must not get any yopass route (not even the anonymous /share/{id} endpoints);
 * importing the `nowo_yopass` loader exposes them and the controllers keep their AbstractController container.
 */
final class BundleRoutesOptInTest extends TestCase
{
    private ?KernelInterface $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(dirname((new RoutingTestKernel(false))->getCacheDir()));
        (new Filesystem())->remove(dirname((new RoutingTestKernel(true))->getCacheDir()));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testRoutingControllersResourceDoesNotRegisterBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        self::assertSame([], $this->bundleRouteNames(false), 'Yopass routes must exist only where the host imports the nowo_yopass route loader.');
    }

    public function testImportedRoutesServeThePublicSharePage(): void
    {
        $names = $this->bundleRouteNames(true);
        self::assertContains('nowo_yopass_index', $names);
        self::assertContains('nowo_yopass_public_share', $names);

        $repository = $this->kernel?->getContainer()->get(InMemoryShareRepository::class);
        self::assertInstanceOf(InMemoryShareRepository::class, $repository);
        $share = new SecureShare('00000000-0000-4000-8000-000000000042', new TestUser('creator'));
        $share
            ->setCiphertext('{"v":1,"mode":"key","box":"ciphertext"}')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);
        $repository->add($share);

        // AbstractController::render() / createForm() need the container.service_subscriber locator.
        $response = $this->kernel?->handle(Request::create('/share/' . $share->getId()));
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/share/' . $share->getId() . '/consume', (string) $response->getContent());
    }

    /** @return list<string> */
    private function bundleRouteNames(bool $importBundleRoutes): array
    {
        $this->kernel = new RoutingTestKernel($importBundleRoutes);
        $this->kernel->boot();

        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return array_values(array_filter(
            array_keys($router->getRouteCollection()->all()),
            static fn (string $name): bool => str_starts_with($name, 'nowo_yopass'),
        ));
    }
}
