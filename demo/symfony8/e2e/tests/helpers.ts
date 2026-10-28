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

/** Create a one-time share from the manage page (browser-side encryption); returns the one-click link path + fragment. */
export async function createShare(page: Page): Promise<string> {
  await page.goto('/tools/yopass');
  const form = page.locator('form[data-nowo-yopass-create-target="form"]');
  await expect(form).toBeVisible();
  await form.locator('textarea').first().fill(DEMO_SECRET);
  await form.locator('button[data-nowo-yopass-create-target="submit"]').click();
  const oneClick = page.locator('#yopass-created-one-click');
  await expect(oneClick).toHaveValue(/\/share\//, { timeout: 15_000 });
  const url = new URL(await oneClick.inputValue());

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
