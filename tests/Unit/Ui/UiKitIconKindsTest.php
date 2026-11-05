<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Tests\Support\PageFixtures;
use Nowo\YopassBundle\Ui\YopassPageFactory;
use Nowo\YopassBundle\Ui\YopassPages;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function array_unique;
use function array_values;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function sort;
use function sprintf;

/**
 * Every icon the bundle hands to UiKit (`Action::$icon`, else `Action::$kind`, in the PHP view builders and as literals
 * in the Twig templates) must be a canonical UiKit icon kind. An unknown name renders as `bi:<name>` through the
 * `ux_icon` set, which does not exist and fails the page (1.12.0: `icon: 'add'` → 500 on the manage page).
 */
final class UiKitIconKindsTest extends TestCase
{
    public function testUiKitRegistryIsReadable(): void
    {
        $kinds = $this->uiKitKinds();

        self::assertContains('create', $kinds);
        self::assertContains('delete', $kinds);
        self::assertNotContains('add', $kinds);
    }

    public function testEveryActionOfTheViewBuildersUsesAKnownKind(): void
    {
        $kinds = $this->uiKitKinds();

        foreach ($this->actions() as $where => $action) {
            $icon = $action->icon ?? $action->kind;
            self::assertContains($icon, $kinds, sprintf('%s uses the unknown UiKit icon kind "%s".', $where, $icon));
        }
    }

    public function testNoUnknownIconLiteralInSourcesOrTemplates(): void
    {
        $kinds = $this->uiKitKinds();
        $root  = dirname(__DIR__, 3) . '/src';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!in_array($file->getExtension(), ['php', 'twig'], true)) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            // PHP named argument / array key, Twig hash key: icon: 'x', 'icon' => 'x', icon_name: 'x'.
            preg_match_all("/\\b(?:icon|icon_name)'?\\s*(?::|=>)\\s*'([a-z_]+)'/", $code, $matches);
            // Twig: include('@NowoUiKitBundle/components/_icon.html.twig', {name: 'x'}).
            preg_match_all("/_icon\\.html\\.twig'\\s*,\\s*\\{\\s*name:\\s*'([a-z_]+)'/", $code, $includes);
            foreach ([...$matches[1], ...$includes[1]] as $icon) {
                self::assertContains($icon, $kinds, sprintf('%s passes the unknown UiKit icon kind "%s".', $file->getPathname(), $icon));
            }
        }
    }

    /**
     * Actions of every page the bundle builds (manage in page / dialog mode, empty and filled, created page).
     *
     * @return iterable<string, Action>
     */
    private function actions(): iterable
    {
        $pages = [
            'manage'                 => PageFixtures::manage()['page'],
            'manage (empty, dialog)' => PageFixtures::manage(true, false, true)['page'],
            'manage (dialog)'        => PageFixtures::manage(false, true, true, true)['page'],
            'created'                => (new YopassPageFactory(new YopassPages()))->createdPage('/tools/yopass'),
        ];

        foreach ($pages as $name => $page) {
            foreach ($page->header->actions as $i => $action) {
                yield $name . ' header #' . $i => $action;
            }

            if ($page instanceof ListPage) {
                if ($page->empty?->action instanceof Action) {
                    yield $name . ' empty state' => $page->empty->action;
                }

                foreach ($page->rows as $r => $row) {
                    self::assertInstanceOf(SecureShare::class, $row);
                    foreach ($page->rowActions($row, $r) as $i => $action) {
                        yield $name . ' row ' . $r . ' action #' . $i => $action;
                    }
                }
            }

            self::assertTrue($page instanceof ListPage || $page instanceof ShowPage);
        }
    }

    /**
     * Canonical icon kinds of the installed UiKit (keys of the `ux_icon` map of components/_icon.html.twig, the
     * strictest icon set: an unknown name there is an error, not a fallback class).
     *
     * @return list<string>
     */
    private function uiKitKinds(): array
    {
        $file    = dirname((string) (new ReflectionClass(Action::class))->getFileName(), 2) . '/Resources/views/components/_icon.html.twig';
        $content = (string) file_get_contents($file);

        self::assertSame(1, preg_match('/ux_map = \\{(.*?)\\}/s', $content, $block), 'UiKit ux_icon map not found in ' . $file);
        preg_match_all('/^\\s*([a-z_]+):/m', is_string($block[1] ?? null) ? $block[1] : '', $keys);

        $kinds = array_values(array_unique($keys[1]));
        sort($kinds);

        return $kinds;
    }
}
