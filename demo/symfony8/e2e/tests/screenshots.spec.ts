import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { createShare, revealShare, waitForToolbar } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). Fake demo text only. */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Yopass screenshots', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('share-created — one-click link, short link and decryption key', async ({ page }) => {
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
