<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost;

use LogicException;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function extension_loaded;

/**
 * 2.0 (REQ-ARCH-001 rule 3): a host class registered by autodiscovery that still implements the removed
 * `YopassAccessCheckerInterface` (with `#[AsAlias]` or `#[AsDecorator]`) cannot be loaded. Symfony keeps its definition
 * with only a "Class ... not found" error and would remove it silently, because nothing references it: the bundle
 * fails the container build and names the service instead.
 */
final class AutodiscoveredRemovedPortKernelTest extends TestCase
{
    private const FIXTURES = 'Nowo\YopassBundle\Tests\Fixtures\RemovedPort\\';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(DddHostTestKernel::baseDir());
    }

    public function testAnAutodiscoveredCheckerOnTheRemovedPortFailsTheBuild(): void
    {
        $kernel = new DddHostTestKernel(false, ['database' => ['platform' => 'sqlite']], [self::FIXTURES, dirname(__DIR__, 2) . '/Fixtures/RemovedPort/']);

        try {
            $kernel->boot();
            self::fail('The container build must fail on a service still implementing the removed interface.');
        } catch (LogicException $exception) {
            self::assertMatchesRegularExpression('/^The service "Nowo\\\\YopassBundle\\\\Tests\\\\Fixtures\\\\RemovedPort\\\\(Aliased|Decorating)LegacyAccessChecker" cannot be loaded because it still uses "Nowo\\\\YopassBundle\\\\Security\\\\YopassAccessCheckerInterface", removed in 2\.0 \(.*not found.*\)\. Implement "Nowo\\\\YopassBundle\\\\Security\\\\YopassAccessPolicyInterface" instead, and alias or decorate that interface; see UPGRADING "To 2\.0\.0"\.$/s', $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTheSameKernelWithoutTheLegacyClassesBoots(): void
    {
        $kernel = new DddHostTestKernel(false, ['database' => ['platform' => 'sqlite']]);
        $kernel->boot();

        self::assertTrue($kernel->getContainer()->has('test.service_container'));
        self::assertInstanceOf(YopassAccessDecider::class, $kernel->getContainer()->get('test.service_container')->get(YopassAccessDecider::class));
        $kernel->shutdown();
    }
}
