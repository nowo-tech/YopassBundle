import { expect, type Page } from '@playwright/test';

/** Obviously fake demo text — never put real credentials in e2e tests or screenshots. */
export const DEMO_SECRET = 'Demo only: Wi-Fi guest code is not-a-real-secret-42';

/** Wait for the Symfony WebProfiler toolbar (dev) so it is part of the capture. */
export async function waitForToolbar(page: Page): Promise<void> {
  const toolbar = page.locator('.sf-toolbar');
  if (await toolbar.count()) {
    await expect(toolbar.locator('.sf-toolbar-block').first()).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(400);
  }
}

/** Open the create dialog (web_ui.create_mode: dialog) from the header or the empty state. */
export async function openCreateDialog(page: Page): Promise<void> {
  // One create action per screen: the header on a filled list, the empty state on an empty one.
  await page.locator('[data-testid="yopass-create-open"], [data-testid="yopass-create-open-empty"]').first().click();
  await expect(page.locator('dialog#nowo-yopass-create-dialog')).toBeVisible();
}

/**
 * Create a one-time share in the create dialog (browser-side encryption, async create); returns the one-click link
 * path + fragment. Leaves the dialog open on the result unless `close` is true (Done → wipe + reload).
 */
export async function createShare(page: Page, secret: string = DEMO_SECRET, close = true): Promise<string> {
  await page.goto('/tools/yopass');
  await openCreateDialog(page);
  const dialog = page.locator('dialog#nowo-yopass-create-dialog');
  await dialog.locator('textarea').first().fill(secret);
  await dialog.locator('button[data-nowo-yopass-create-target="submit"]').click();
  const oneClick = dialog.locator('#yopass-create-result-one-click');
  await expect(oneClick).toHaveValue(/\/share\//, { timeout: 15_000 });
  const url = new URL(await oneClick.inputValue());

  if (close) {
    await Promise.all([page.waitForEvent('load'), dialog.locator('[data-testid="yopass-create-done"]').click()]);
  }

  return `${url.pathname}${url.search}${url.hash}`;
}

/** Open a share link and reveal it (the one-click link may auto-reveal once the key is read from the fragment). */
export async function revealShare(page: Page, link: string): Promise<void> {
  await page.goto(link);
  const output = page.locator('[data-nowo-share-reveal-target="output"]');
  const button = page.locator('[data-nowo-share-reveal-target="revealButton"]');
  await expect(
    page.locator('[data-nowo-share-reveal-target="output"]:visible, [data-nowo-share-reveal-target="revealButton"]:visible').first(),
  ).toBeVisible({ timeout: 15_000 });
  if (!(await output.isVisible()) && (await button.isVisible())) {
    // The controller may auto-reveal (hiding the button) while we click; a short timeout covers that race.
    await button.click({ timeout: 3_000 }).catch(() => undefined);
  }
  await expect(output).toHaveText(DEMO_SECRET, { timeout: 15_000 });
}
