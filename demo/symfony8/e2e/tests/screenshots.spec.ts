import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { createShare, revealShare, waitForToolbar } from './helpers';

/**
 * REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs): demo masthead +
 * bundle UI in a useful state + Symfony WebProfiler toolbar. Fake demo text only.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Yopass screenshots', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('manage — create panel and the list of shares (UiKit list scaffold)', async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: 1280, height: 1500 });
    // A few shares so the list shows active / revoked states.
    await createShare(page, 'Demo only: staging database password is not-a-real-secret-1');
    await createShare(page, 'Demo only: Wi-Fi guest code is not-a-real-secret-42');
    await page.goto('/tools/yopass');
    await page.locator('[data-testid="yopass-manage"] tbody tr').first().locator('.nowo-ui-action--cancel').first().click();
    await page.locator('dialog[open] button[type="submit"]').click();
    await expect(page.locator('[data-testid="yopass-manage"] tbody tr').first()).toContainText(/Revoked/);
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'manage.png') });
  });

  test('share-created — short link, decryption key and one-click link', async ({ page }) => {
    await createShare(page);
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'share-created.png') });
  });

  test('share-revealed — recipient decrypts the one-time secret in the browser', async ({ page }) => {
    const link = await createShare(page);
    await revealShare(page, link);
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'share-revealed.png') });
  });
});
