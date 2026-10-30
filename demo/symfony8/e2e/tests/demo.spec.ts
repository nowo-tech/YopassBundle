import { test, expect } from '@playwright/test';
import { createShare, revealShare } from './helpers';

/** REQ-DEMO-013 — Yopass demo happy path on the UiKit page contract (REQ-UI-003). */
test.describe('Yopass demo', () => {
  test('manage page renders the list scaffold with the create panel', async ({ page }) => {
    const response = await page.goto('/tools/yopass');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('[data-testid="yopass-manage"]')).toBeVisible();
    await expect(page.locator('form[data-nowo-yopass-create-target="form"] textarea').first()).toBeVisible();
    // No framework-only modal root: the preview is a native <dialog> (REQ-UI-001 modals).
    await expect(page.locator('dialog#nowo-yopass-preview')).toHaveCount(1);
  });

  test('create → reveal via one-click link → second read is unavailable', async ({ page }) => {
    const link = await createShare(page);
    await expect(page.locator('#yopass-created-short')).toHaveValue(/\/share\//);
    // REQ-SEC-004 M1: the key travels in the URL fragment, never in the query string.
    expect(link).toContain('#decrypt_key=');
    expect(link.split('#')[0]).not.toContain('decrypt_key');

    await revealShare(page, link);

    await page.reload();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('[data-nowo-share-reveal-target="revealButton"]')).toHaveCount(0);
  });

  test('preview dialog opens from the row action and revoke goes through the confirm dialog', async ({ page }) => {
    await createShare(page);
    await page.goto('/tools/yopass');

    const row = page.locator('[data-testid="yopass-manage"] tbody tr').first();
    await row.locator('button[aria-controls="nowo-yopass-preview"]').first().click();
    const dialog = page.locator('dialog#nowo-yopass-preview');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-nowo-yopass-manage-preview-target="availability"]')).not.toBeEmpty();
    await dialog.locator('[data-nowo-ui-dialog-close]').first().click();
    await expect(dialog).toBeHidden();

    await row.locator('.nowo-ui-action--cancel').first().click();
    const confirm = page.locator('dialog[open]');
    await expect(confirm).toBeVisible();
    await confirm.locator('button[type="submit"]').click();
    await expect(page.locator('[data-testid="yopass-manage"] tbody tr').first()).toContainText(/Revoked/);
  });
});
