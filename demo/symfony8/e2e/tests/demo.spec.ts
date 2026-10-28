import { test, expect } from '@playwright/test';
import { createShare, revealShare } from './helpers';

/** REQ-DEMO-013 — Yopass demo happy path: create an encrypted one-time share, reveal it once, then it is gone. */
test.describe('Yopass demo', () => {
  test('manage page shows the create-share form', async ({ page }) => {
    const response = await page.goto('/tools/yopass');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('h1.page-title')).toBeVisible();
    await expect(page.locator('form[data-nowo-yopass-create-target="form"] textarea').first()).toBeVisible();
  });

  test('create → reveal via one-click link → second read is unavailable', async ({ page }) => {
    const link = await createShare(page);
    await expect(page.locator('#yopass-created-short')).toHaveValue(/\/share\//);

    await revealShare(page, link);

    await page.reload();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('[data-nowo-share-reveal-target="revealButton"]')).toHaveCount(0);
  });
});
