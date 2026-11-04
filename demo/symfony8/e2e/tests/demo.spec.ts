import { test, expect } from '@playwright/test';
import { createShare, DEMO_SECRET, openCreateDialog, revealShare } from './helpers';

/** REQ-DEMO-013 — Yopass demo happy path on the UiKit page contract (REQ-UI-003). */
test.describe('Yopass demo', () => {
  test('manage page renders the list scaffold with the New share dialog', async ({ page }) => {
    const response = await page.goto('/tools/yopass');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('[data-testid="yopass-manage"]')).toBeVisible();
    // web_ui.create_mode: dialog — no create card; the header action opens the dialog with the form.
    await expect(page.locator('[data-testid="yopass-create"]')).toHaveCount(0);
    await openCreateDialog(page);
    await expect(page.locator('dialog#nowo-yopass-create-dialog textarea').first()).toBeVisible();
    // No framework-only modal root: the preview is a native <dialog> (REQ-UI-001 modals).
    await expect(page.locator('dialog#nowo-yopass-preview')).toHaveCount(1);
  });

  test('create in the dialog: links in the dialog, Done wipes and refreshes the list', async ({ page }) => {
    const link = await createShare(page, DEMO_SECRET, false);
    const dialog = page.locator('dialog#nowo-yopass-create-dialog');
    await expect(dialog.locator('#yopass-create-result-short')).toHaveValue(/\/share\//);
    await expect(dialog.locator('#yopass-create-result-key')).not.toHaveValue('');
    expect(link).toContain('#decrypt_key=');

    await Promise.all([page.waitForEvent('load'), dialog.locator('[data-testid="yopass-create-done"]').click()]);
    await expect(page.locator('[data-testid="yopass-manage"] tbody tr').first()).toBeVisible();

    // Reopening shows an empty form: the secret and the result were wiped.
    await openCreateDialog(page);
    await expect(dialog.locator('textarea').first()).toHaveValue('');
    await expect(dialog.locator('[data-testid="yopass-create-result"]')).toBeHidden();
    expect(await page.evaluate(() => sessionStorage.length)).toBe(0);
  });

  test('create → reveal via one-click link → second read is unavailable', async ({ page }) => {
    const link = await createShare(page);
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
