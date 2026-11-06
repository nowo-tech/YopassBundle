<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\YopassBundle\Tests\Support\PageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\AppVariable;
use Twig\Environment;

use function array_unique;
use function count;
use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;
use function substr_count;

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

    /** UiKit icon set of the render (null = the base test case's bootstrap-icons). */
    private ?string $iconSet = null;

    /** `nowo_ui_kit_row_actions_display` of the render (null = the base test case's default). */
    private ?string $rowActionsDisplay = null;

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

    public function testDialogModeRendersTheCreateDialogInsteadOfTheCard(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(false, true, true)))->html;

        self::assertStringContainsString('id="nowo-yopass-create-dialog"', $html);
        self::assertStringContainsString('data-nowo-ui-dialog-open="nowo-yopass-create-dialog"', $html);
        self::assertStringContainsString('data-testid="yopass-create-open"', $html);
        self::assertStringContainsString('data-nowo-yopass-create-async-value="true"', $html);
        self::assertStringContainsString('data-nowo-yopass-create-target="result"', $html);
        self::assertStringContainsString('data-testid="yopass-create-done"', $html);
        self::assertStringContainsString('id="yopass-create-result-short"', $html);
        self::assertStringNotContainsString('data-testid="yopass-create"', $html, 'no create card in dialog mode');
    }

    public function testCreateDialogAnatomy(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(false, true, true)))->html;

        // The controller wraps the <dialog>, so the footer buttons are in its scope.
        self::assertMatchesRegularExpression('#<div class="nowo-yopass-create__root" data-controller="nowo-yopass-create"[^>]*data-nowo-yopass-create-async-value="true"[^>]*>\s*<dialog id="nowo-yopass-create-dialog"#', $html);
        self::assertSame(1, preg_match('#<dialog id="nowo-yopass-create-dialog".*?</dialog>#s', $html, $match));
        $dialog = $match[0];

        // Medium (default) size: no size modifier; height from the UiKit tokens.
        self::assertMatchesRegularExpression('#<dialog id="nowo-yopass-create-dialog" class="nowo-ui-dialog[ "]#', $dialog);
        self::assertDoesNotMatchRegularExpression('#nowo-ui-dialog--(lg|wide|sm|xl)#', $dialog);
        // The form in the body is bound to the footer submit with form="…"; no submit inside the form.
        self::assertMatchesRegularExpression('#<form [^>]*id="nowo-yopass-create-dialog-form"#', $dialog);
        self::assertSame(1, preg_match('#<form [^>]*id="nowo-yopass-create-dialog-form".*?</form>#s', $dialog, $form));
        self::assertStringNotContainsString('type="submit"', $form[0]);
        self::assertSame(1, preg_match('#</div>\s*<footer class="[^"]*nowo-ui-dialog__footer[^"]*"[^>]*>(.*?)</footer>\s*</dialog>#s', $dialog, $footer));
        // Footer: Cancel (secondary) then the only primary, last: Create; Close (secondary, spec §16.6) replaces both after the create.
        self::assertMatchesRegularExpression('#^\s*<button type="button" class="[^"]*nowo-ui-btn--secondary[^"]*" data-nowo-ui-dialog-close data-nowo-yopass-create-target="cancel"[^>]*>\s*Cancel\s*</button>\s*<button type="submit" form="nowo-yopass-create-dialog-form" class="[^"]*nowo-ui-btn--primary[^"]*" data-nowo-yopass-create-target="submit"#', $footer[1]);
        self::assertMatchesRegularExpression('#<button type="button" class="[^"]*nowo-ui-btn--secondary[^"]*" hidden data-nowo-ui-dialog-close data-action="nowo-yopass-create\#done" data-nowo-yopass-create-target="done" data-testid="yopass-create-done">\s*Close\s*</button>#', $footer[1]);
        self::assertSame(1, substr_count($dialog, 'data-testid="yopass-create-done"'), 'Close (result step) only in the footer');
        self::assertSame(1, substr_count($dialog, 'data-nowo-yopass-create-target="submit"'), 'one submit, in the footer');
        // Dialog rule v2: exactly one primary in the create dialog (markup, not only the visible step).
        self::assertSame(1, substr_count($dialog, 'nowo-ui-btn--primary'), 'one primary in the create dialog');
    }

    public function testCreateTabsUseTheUiKitTabsPartialWithIcons(): void
    {
        // Label only with bootstrap-icons (the tabler: defaults need tabler-icons or ux_icon).
        $plain = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage()))->html;
        self::assertStringContainsString('data-testid="yopass-create-tabs"', $plain);
        self::assertStringNotContainsString('nowo-ui-tabs__icon', $plain);

        $this->iconSet = 'tabler-icons';
        $html          = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage()))->html;

        self::assertMatchesRegularExpression('#<nav class="[^"]*" aria-label="Secret type"[^>]*role="tablist"[^>]*data-testid="yopass-create-tabs"#', $html);
        self::assertMatchesRegularExpression('#<a\s+class="[^"]*"\s+href="\#"\s+aria-current="page"\s+data-rail-label="Text"\s+role="tab" aria-selected="true" data-action="nowo-yopass-create\#switchTab" data-yopass-tab="text"\s*><span class="nowo-ui-tabs__icon" aria-hidden="true">#', $html);
        self::assertMatchesRegularExpression('#data-yopass-tab="file"\s*><span class="nowo-ui-tabs__icon" aria-hidden="true">.*?</span><span class="nowo-ui-tabs__label">File</span></a>#s', $html);

        // Dialog mode carries the same tabs.
        $dialog = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(false, true, true)))->html;
        self::assertStringContainsString('data-testid="yopass-create-tabs"', $dialog);
        self::assertStringContainsString('<span class="nowo-ui-tabs__label">Text</span>', $dialog);
    }

    public function testEmptyDialogModeOffersNewShareInTheEmptyState(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(true, false, true)))->html;

        self::assertStringContainsString('data-testid="yopass-create-open-empty"', $html);
        self::assertStringNotContainsString('data-testid="yopass-create-open"', $html, 'one create action per screen');
        self::assertStringNotContainsString('data-testid="yopass-delete-all"', $html);
    }

    public function testPageModeKeepsTheCardAndNoDialog(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage()))->html;

        self::assertStringContainsString('data-testid="yopass-create"', $html);
        self::assertStringContainsString('data-nowo-yopass-create-async-value="false"', $html);
        self::assertStringNotContainsString('nowo-yopass-create-dialog', $html);
        self::assertStringContainsString('role="tablist"', $html);
    }

    public function testTheTypeSwitchIsHiddenWithASingleType(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(false, false)))->html;

        self::assertStringNotContainsString('role="tablist"', $html);
    }

    public function testPreviewDialogLayout(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage()))->html;

        self::assertSame(1, preg_match('#<dialog id="nowo-yopass-preview".*?</dialog>#s', $html, $match));
        $dialog = $match[0];

        // Status badge inline in the title.
        self::assertMatchesRegularExpression('#<h2[^>]*id="nowo-yopass-preview-title"[^>]*>.*?data-nowo-yopass-manage-preview-target="availability".*?</h2>#s', $dialog);
        // One primary button per dialog ("Show content"), attached to the key input group.
        self::assertSame(1, preg_match_all('#class="[^"]*nowo-ui-btn--primary[^"]*"#', $dialog));
        self::assertMatchesRegularExpression('#class="nowo-yopass-input-group".*?data-nowo-yopass-manage-preview-target="decryptButton"#s', $dialog);
        // Revealed content block with Copy; public link copy icon button (UiKit `copy` kind).
        self::assertStringContainsString('data-action="nowo-yopass-manage-preview#copyOutput"', $dialog);
        self::assertMatchesRegularExpression('#data-testid="yopass-preview-copy-link"#', $dialog);
        // UiKit 2.29 size: wide (tables / guides); footer outside the body with Close only (owner decision: the single
        // primary "Show content" stays in the body next to the key field, Beacon UI-PATTERNS §16).
        self::assertMatchesRegularExpression('#<dialog id="nowo-yopass-preview" class="[^"]*nowo-ui-dialog--wide#', $dialog);
        self::assertMatchesRegularExpression('#</div>\s*<footer class="[^"]*nowo-ui-dialog__footer[^"]*"[^>]*>\s*<button type="button" class="[^"]*nowo-ui-btn--secondary[^"]*" data-nowo-ui-dialog-close[^>]*>\s*Close\s*</button>\s*</footer>\s*</dialog>#', $dialog);
        self::assertSame(1, preg_match('#<footer.*?</footer>#s', $dialog, $footer));
        self::assertStringNotContainsString('nowo-ui-btn--primary', $footer[0]);
        // Extend: "Now: …", one row [time] [reads] [Extend (secondary, disabled until a select changes)], the live
        // "Will be: …" preview (aria-live polite), the inline error and the inline success (aria-live polite).
        self::assertSame(1, preg_match('#<section [^>]*data-testid="yopass-extend">.*?</section>#s', $dialog, $extend));
        $extend = $extend[0];
        self::assertMatchesRegularExpression('#data-testid="yopass-extend-now">\s*Now: <span data-nowo-yopass-manage-preview-target="extendSummary"></span>#', $extend);
        self::assertMatchesRegularExpression('#<div class="nowo-yopass-extend__row">\s*<div class="nowo-yopass-extend__field">.*?id="yopass-extend-expires".*?</div>\s*<div class="nowo-yopass-extend__field">.*?data-nowo-yopass-manage-preview-target="extendMaxReads".*?<div class="nowo-yopass-extend__action">\s*<button type="button" class="[^"]*nowo-ui-btn--secondary[^"]*" disabled data-action="nowo-yopass-manage-preview\#submitExtend" data-nowo-yopass-manage-preview-target="extendButton"[^>]*>\s*Extend\s*</button>#s', $extend);
        self::assertSame(2, substr_count($extend, 'data-action="change-&gt;nowo-yopass-manage-preview#syncExtend"'));
        self::assertMatchesRegularExpression('#<option value="1h" data-seconds="3600"#', $extend);
        self::assertMatchesRegularExpression('#hidden aria-live="polite" data-nowo-yopass-manage-preview-target="extendPreview"#', $extend);
        self::assertMatchesRegularExpression('#role="alert" hidden data-nowo-yopass-manage-preview-target="extendError"#', $extend);
        self::assertMatchesRegularExpression('#role="status" aria-live="polite" hidden data-nowo-yopass-manage-preview-target="extendSuccess"#', $extend);
        self::assertStringNotContainsString('nowo-ui-btn--primary', $extend);
        self::assertStringNotContainsString('nowo-yopass-grid-2', $extend);
        // The labels of the "Now / Will be" lines reach the controller.
        self::assertStringContainsString('data-nowo-yopass-manage-preview-extend-labels-value="{&quot;preview&quot;:&quot;Will be&quot;,&quot;summary&quot;:&quot;expires %expires% \u00b7 %reads%&quot;,&quot;reads_one&quot;:&quot;%count% read&quot;,&quot;reads_other&quot;:&quot;%count% reads&quot;}"', $html);
        // Layout hook for the scrolling body; no inline style anywhere.
        self::assertStringContainsString('data-nowo-yopass-dialog="preview"', $dialog);
        self::assertStringNotContainsString('style=', $dialog);
        self::assertStringContainsString('css/yopass.css', $html);
    }

    /**
     * One icon per action: row actions follow `row_actions_display`, the labels stay.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function rowActionKinds(): iterable
    {
        yield 'View' => ['view', 'bi-eye', 'View'];
        yield 'Extend' => ['extend', 'bi-calendar-plus', 'Extend'];
        yield 'Revoke' => ['revoke', 'bi-person-x', 'Revoke'];
        yield 'Delete' => ['delete', 'bi-trash', 'Delete'];
    }

    #[DataProvider('rowActionKinds')]
    public function testRowActionsDrawTheirKindAndFollowTheDisplay(string $kind, string $icon, string $label): void
    {
        $pattern = sprintf('#<button[^>]*nowo-ui-action--%s[^>]*>(.*?)</button>#s', $kind);

        $this->rowActionsDisplay = 'icon_text';
        self::assertSame(1, preg_match($pattern, $this->manageRows(), $match));
        self::assertStringContainsString($icon, $match[1]);
        self::assertStringContainsString($label, $match[1]);

        $this->rowActionsDisplay = 'text';
        self::assertSame(1, preg_match($pattern, $this->manageRows(), $match));
        self::assertStringNotContainsString($icon, $match[1]);
        self::assertStringContainsString($label, $match[1]);
    }

    public function testViewIsNotAFilledPrimaryInTheRow(): void
    {
        $rows = $this->manageRows();

        self::assertSame(4, preg_match_all('#<button[^>]*nowo-ui-action--view[^>]*>#', $rows, $views));
        foreach ($views[0] as $view) {
            self::assertStringContainsString('nowo-ui-btn--secondary', $view);
        }
        self::assertStringNotContainsString('nowo-ui-btn--primary', $rows);
    }

    public function testHeaderActionsDrawTheirKindAndKeepTheirLabel(): void
    {
        $html = $this->manageHtml();

        self::assertSame(1, preg_match('#<button[^>]*nowo-ui-action--share[^>]*data-testid="yopass-create-open"[^>]*>(.*?)</button>#s', $html, $share));
        self::assertStringContainsString('bi-share', $share[1]);
        self::assertStringContainsString('New share', $share[1]);
        self::assertSame(1, preg_match('#<button[^>]*nowo-ui-btn--primary[^>]*nowo-ui-action--share#', $html));

        self::assertSame(1, preg_match('#<button[^>]*nowo-ui-action--delete[^>]*data-testid="yopass-delete-all"[^>]*>(.*?)</button>#s', $html, $deleteAll));
        self::assertStringContainsString('bi-trash', $deleteAll[1]);
        self::assertStringContainsString('Delete all', $deleteAll[1]);

        $empty = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(true, false, true)))->html;
        self::assertSame(1, preg_match('#<button[^>]*nowo-ui-action--share[^>]*data-testid="yopass-create-open-empty"[^>]*>(.*?)</button>#s', $empty, $emptyShare));
        self::assertStringContainsString('bi-share', $emptyShare[1]);
    }

    public function testIconSetNoneKeepsTheHeaderActionsTextOnly(): void
    {
        $this->iconSet = 'none';

        self::assertSame(1, preg_match('#<button[^>]*data-testid="yopass-create-open"[^>]*>(.*?)</button>#s', $this->manageHtml(), $share));
        self::assertSame(1, substr_count($share[1], 'New share'));
        self::assertStringNotContainsString('bi-share', $share[1]);
    }

    public function testDestructiveActionsKeepTheConfirmDialogAndCsrfForm(): void
    {
        $html = $this->manageHtml();
        $id   = '00000000-0000-4000-8000-000000000001';

        foreach ([
            ['revoke', '/tools/yopass/' . $id . '/revoke'],
            ['delete', '/tools/yopass/' . $id . '/delete'],
            ['delete', '/tools/yopass/delete-all'],
        ] as [$kind, $target]) {
            // Toggle in the row / header: a type="button" that only opens the dialog.
            self::assertMatchesRegularExpression(sprintf('#<button type="button"[^>]*nowo-ui-action--%s[^>]*data-nowo-confirm-target#', $kind), $html);
            // The POST lives in the dialog, with the per-action CSRF field.
            self::assertSame(1, preg_match(sprintf('#<form[^>]*action="%s"[^>]*>.*?</form>#s', preg_quote($target, '#')), $html, $form), $target);
            self::assertMatchesRegularExpression('#method="post"#i', $form[0]);
            self::assertMatchesRegularExpression('#name="_token"#', $form[0]);
            self::assertMatchesRegularExpression('#<dialog[^>]*>(?:(?!</dialog>).)*' . preg_quote($target, '#') . '#s', $html);
        }
    }

    public function testEveryStimulusHookOfThePreviewControllerIsOnThePage(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Resources/assets/src/yopass-manage-preview-controller.ts');
        $html   = $this->manageHtml();

        self::assertSame(1, preg_match('#static targets = \[(.*?)\];#s', $source, $targets));
        preg_match_all("#'(\w+)'#", $targets[1], $names);
        self::assertGreaterThan(20, count($names[1]));
        foreach ($names[1] as $target) {
            self::assertStringContainsString(sprintf('data-nowo-yopass-manage-preview-target="%s"', $target), $html);
        }

        // Every action the page wires exists on the controller, including the two row actions.
        preg_match_all('/nowo-yopass-manage-preview#(\w+)/', $html, $actions);
        self::assertContains('open', $actions[1]);
        self::assertContains('openExtend', $actions[1]);
        foreach (array_unique($actions[1]) as $method) {
            self::assertMatchesRegularExpression(sprintf('#^\s*(?:async\s+)?%s\(#m', $method), $source, $method);
        }

        // Row hooks read by open() / openExtend(): the share id and the dialog opener, on the same button.
        $id = '00000000-0000-4000-8000-000000000001';
        foreach (['view' => '#open', 'extend' => '#openExtend'] as $kind => $method) {
            self::assertSame(1, preg_match(sprintf('#<button[^>]*nowo-ui-action--%s[^>]*>#', $kind), $html, $button));
            self::assertStringContainsString(sprintf('data-action="nowo-yopass-manage-preview%s"', $method), $button[0]);
            self::assertStringContainsString(sprintf('data-share-id="%s"', $id), $button[0]);
            self::assertStringContainsString('data-nowo-ui-dialog-open="nowo-yopass-preview"', $button[0]);
        }

        // Values the controller reads for the extend POST and the status badge.
        foreach (['preview-path-template', 'extend-path-template', 'csrf-token', 'extend-success-message', 'extend-labels', 'errors', 'availability-labels', 'copied-message', 'allow-embed-in-url'] as $value) {
            self::assertStringContainsString(sprintf('data-nowo-yopass-manage-preview-%s-value=', $value), $html);
        }
    }

    public function testRetentionNoticeShowsATranslatedDurationOnThePage(): void
    {
        self::assertMatchesRegularExpression('#<caption[^>]*>\s*Shares older than 1 month are automatically deleted\.\s*</caption>#', $this->manageHtml());
    }

    public function testHostColumnValuesAreEscaped(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(false, true, false, true)))->html;

        self::assertStringContainsString('&lt;b&gt;placeholder-user&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>placeholder-user</b>', $html);
        self::assertStringContainsString('data-nowo-yopass-column="createdBy"', $html);
        self::assertStringContainsString('Created by', $html);
    }

    private function manageHtml(): string
    {
        return $this->renderPage(new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(false, true, true)))->html;
    }

    /**
     * The list's rows only: the header and the dialogs have action buttons of the same kinds.
     */
    private function manageRows(): string
    {
        self::assertSame(1, preg_match('#<tbody.*?</tbody>#s', $this->manageHtml(), $rows));

        return $rows[0];
    }

    protected function configureTwig(Environment $twig): void
    {
        if ($this->iconSet !== null) {
            $twig->addGlobal('nowo_ui_kit_icon_set', $this->iconSet);
        }

        if ($this->rowActionsDisplay !== null) {
            $twig->addGlobal('nowo_ui_kit_row_actions_display', $this->rowActionsDisplay);
        }

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
        yield 'manage (dialog create mode, host column)' => new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(false, true, true, true));
        yield 'manage (dialog create mode, empty, text only)' => new PageFixture('@NowoYopassBundle/manage/index.html.twig', PageFixtures::manage(true, false, true));
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
