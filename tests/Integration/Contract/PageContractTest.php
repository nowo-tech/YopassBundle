<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\YopassBundle\Tests\Support\PageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\AppVariable;
use Twig\Environment;

use function dirname;

/**
 * REQ-UI-003 page contract: the manage page extends the UiKit list scaffold, the share created page the show scaffold,
 * and the public reveal page is a unique page declared in docs/TEMPLATES.md on the guest layout; no <html>, CDN,
 * inline handler or confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or
 * missing translation keys (strict rule); the CSRF forms of the revoke / delete actions keep their hidden fields.
 */
final class PageContractTest extends PageContractTestCase
{
    /** Placeholder CSP nonce set as the request attribute csp_nonce (null = host without a nonce). */
    private ?string $cspNonce = null;

    /**
     * @return iterable<string, array{PageFixture}>
     */
    public static function yopassScriptPages(): iterable
    {
        yield 'manage' => [new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage())];
        yield 'created' => [new PageFixture('@NowoYopassBundle/manage/created.html.twig', PageFixtures::created())];
        yield 'reveal' => [new PageFixture('@NowoYopassBundle/public/reveal.html.twig', PageFixtures::reveal())];
    }

    #[DataProvider('yopassScriptPages')]
    public function testYopassScriptCarriesTheRequestCspNonce(PageFixture $fixture): void
    {
        $this->cspNonce = 'PLACEHOLDER-NONCE-abc123';

        $html = $this->renderPage($fixture)->html;

        self::assertMatchesRegularExpression('#<script type="module" src="[^"]*js/yopass\.js" nonce="PLACEHOLDER-NONCE-abc123"></script>#', $html);
    }

    #[DataProvider('yopassScriptPages')]
    public function testYopassScriptWithoutNonceIsUnchanged(PageFixture $fixture): void
    {
        $html = $this->renderPage($fixture)->html;

        self::assertMatchesRegularExpression('#<script type="module" src="[^"]*js/yopass\.js"></script>#', $html);
    }

    #[DataProvider('yopassScriptPages')]
    public function testYopassScriptNonceIsEscaped(PageFixture $fixture): void
    {
        $this->cspNonce = 'x"><script>alert(1)</script>';

        $html = $this->renderPage($fixture)->html;

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    protected function configureTwig(Environment $twig): void
    {
        if ($this->cspNonce === null) {
            return;
        }

        $app = $twig->getGlobals()['app'] ?? null;
        if ($app instanceof AppVariable) {
            $app->getRequest()?->attributes->set('csp_nonce', $this->cspNonce);
        }
    }

    public static function pageFixtures(): iterable
    {
        yield 'manage' => new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage());
        yield 'manage (empty, no file shares)' => new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(true, false));
        yield 'created' => new PageFixture('@NowoYopassBundle/manage/created.html.twig', PageFixtures::created());
        yield 'created (one-click link first)' => new PageFixture('@NowoYopassBundle/manage/created.html.twig', PageFixtures::created(true, true));
        yield 'created (no one-click link)' => new PageFixture('@NowoYopassBundle/manage/created.html.twig', PageFixtures::created(false));
        yield 'reveal (key)' => new PageFixture('@NowoYopassBundle/public/reveal.html.twig', PageFixtures::reveal());
        yield 'reveal (password)' => new PageFixture('@NowoYopassBundle/public/reveal.html.twig', PageFixtures::reveal('password'));
        yield 'reveal (unavailable)' => new PageFixture('@NowoYopassBundle/public/reveal.html.twig', PageFixtures::reveal('key', false));
    }

    protected static function templatePaths(): array
    {
        return ['NowoYopassBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
