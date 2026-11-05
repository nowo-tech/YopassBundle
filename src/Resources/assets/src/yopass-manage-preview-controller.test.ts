// @vitest-environment happy-dom
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import YopassManagePreviewController from './yopass-manage-preview-controller';
import { decryptPayload, encryptPayload, readFileAsPayload } from './yopass-crypto';
import { storeShareKey } from './yopass-share-keys';

// Real crypto by default; password-mode tests override decryptPayload once.
vi.mock('./yopass-crypto', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./yopass-crypto')>();

    return { ...actual, decryptPayload: vi.fn(actual.decryptPayload) };
});

const ERRORS = JSON.stringify({
    KEY_REQUIRED: 'Enter the key.',
    PASSWORD_REQUIRED: 'Enter the password.',
    INVALID_KEY: 'Wrong key.',
    PREVIEW_FAILED: 'Preview failed.',
    EXTEND_FAILED: 'Extend failed.',
});

const CSRF_PLACEHOLDER = 'REDACTED-TEST-CSRF';

let app: Application | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

type MountOptions = { extendPath?: string; optionalTargets?: boolean; allowEmbed?: boolean; extendLabels?: boolean; extendRow?: boolean };

const EXTEND_LABELS = JSON.stringify({
    preview: 'Will be',
    summary: 'expires %expires% · %reads%',
    reads_one: '%count% read',
    reads_other: '%count% reads',
});

function markup(options: MountOptions = {}): string {
    const optional = options.optionalTargets === false
        ? ''
        : `
            <div data-preview-target="error" hidden></div>
            <div data-preview-target="unlock"></div>
            <div data-preview-target="outputPanel" hidden>
                <pre data-preview-target="output" hidden></pre>
                <button id="copy-output" data-action="preview#copyOutput">Copy</button>
            </div>
            <a data-preview-target="downloadLink" hidden></a>
            <input data-preview-target="publicLink">
            <button id="copy" data-action="preview#copyPublicLink">Copy</button>
            <span data-preview-target="availability"></span>
            <div data-preview-target="passwordPanel" hidden></div>
            <div data-preview-target="keyPanel" hidden></div>
            <button data-preview-target="decryptButton">Decrypt</button>
            <div data-preview-target="extendPanel" hidden>
                <span data-preview-target="extendSummary"></span>
                <div data-preview-target="extendSuccess" hidden></div>
            </div>
            <div data-preview-target="accessLogPanel" hidden>
                <p data-preview-target="accessLogEmpty" hidden>No reads yet</p>
                <table data-preview-target="accessLogTable" hidden>
                    <tbody data-preview-target="accessLogBody"></tbody>
                </table>
            </div>`;

    return `
        <div data-controller="preview"
             data-preview-preview-path-template-value="/manage/%7Bid%7D/preview"
             data-preview-extend-path-template-value="${options.extendPath ?? '/manage/{id}/extend'}"
             data-preview-csrf-token-value="${CSRF_PLACEHOLDER}"
             data-preview-errors-value='${ERRORS}'
             data-preview-availability-labels-value='{"active":"Active"}'
             data-preview-extend-success-message-value="Limits extended."
             data-preview-copied-message-value="Copied to clipboard."
             ${options.allowEmbed === true ? 'data-preview-allow-embed-in-url-value="true"' : ''}
             ${options.extendLabels === true ? `data-preview-extend-labels-value='${EXTEND_LABELS}'` : ''}>
            <button id="open" data-share-id="share-1" data-action="preview#open">Preview</button>
            <button id="open-extend" data-share-id="share-1" data-action="preview#openExtend">Extend</button>
            <button id="open-missing" data-action="preview#open">Broken</button>
            <dialog data-preview-target="modal">
                ${optional}
                <form id="decrypt-form" data-action="submit->preview#decryptFromForm">
                    <input data-preview-target="password">
                    <input data-preview-target="key">
                </form>
                <form id="extend-form" data-action="submit->preview#submitExtend">
                    <select data-preview-target="extendExpiresIn" data-action="change->preview#syncExtend">
                        <option value="">Keep</option>
                        <option value="1d" data-seconds="86400">1 day</option>
                        <option value="odd">no seconds</option>
                    </select>
                    <select data-preview-target="extendMaxReads" data-action="change->preview#syncExtend">
                        <option value="">Keep</option>
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                        <option value="x">bogus</option>
                    </select>
                    ${options.extendRow === false ? '' : `
                    <button type="button" id="extend-submit" disabled data-preview-target="extendButton" data-action="preview#submitExtend">Extend</button>
                    <p data-preview-target="extendPreview" hidden aria-live="polite"></p>
                    <div data-preview-target="extendError" role="alert" hidden></div>`}
                </form>
                <button id="close" data-action="preview#close">Close</button>
            </dialog>
        </div>`;
}

async function mount(options: MountOptions = {}): Promise<YopassManagePreviewController> {
    document.body.innerHTML = markup(options);
    app = new Application(document.documentElement);
    await app.start();
    app.register('preview', YopassManagePreviewController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    return app.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="preview"]')!,
        'preview',
    ) as YopassManagePreviewController;
}

function target<T extends HTMLElement = HTMLElement>(name: string): T {
    return document.querySelector(`[data-preview-target="${name}"]`) as T;
}

function visible(name: string): boolean {
    return !target(name).hidden;
}

function byId<T extends HTMLElement = HTMLElement>(id: string): T {
    return document.getElementById(id) as T;
}

function respond(body: unknown, ok = true): void {
    fetchMock.mockResolvedValueOnce({ ok, json: () => Promise.resolve(body) });
}

function submit(formId: string): void {
    byId(formId).dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
}

async function encryptText(text: string): Promise<{ ciphertext: string; key: string }> {
    const result = await encryptPayload({ kind: 'text', text }, { generateKey: true });

    return { ciphertext: result.ciphertext, key: result.decryptionKey! };
}

describe('yopass-manage-preview-controller', () => {
    beforeEach(() => {
        sessionStorage.clear();
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        app?.stop();
        app = null;
        document.body.innerHTML = '';
        document.body.className = '';
        vi.unstubAllGlobals();
    });

    it('ignores triggers without a share id', async () => {
        await mount();

        byId('open-missing').click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(target<HTMLDialogElement>('modal').open).toBe(false);
    });

    it('opens the modal, renders metadata and decrypts with the stored key', async () => {
        const { ciphertext, key } = await encryptText('preview me');
        storeShareKey('share-1', 'key', key);
        respond({
            status: 'ok',
            availability: 'active',
            ciphertext,
            mode: 'key',
            publicPath: '/s/share-1',
            accessLog: [],
            extendable: false,
        });

        await mount({ allowEmbed: true });
        byId('open').click();
        await vi.waitFor(() => expect(target('output').textContent).toBe('preview me'));

        expect(fetchMock).toHaveBeenCalledWith('/manage/share-1/preview', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const modal = target<HTMLDialogElement>('modal');
        expect(modal.open).toBe(true);
        expect(target('availability').textContent).toBe('Active');
        expect(target<HTMLInputElement>('publicLink').value)
            .toBe(`${window.location.origin}/s/share-1#decrypt_key=${encodeURIComponent(key)}`);
        expect(visible('accessLogPanel')).toBe(true);
        expect(visible('accessLogEmpty')).toBe(true);
        expect(visible('accessLogTable')).toBe(false);
        expect(visible('extendPanel')).toBe(false);
        expect(visible('decryptButton')).toBe(false);
        expect(visible('keyPanel')).toBe(false);
        expect(visible('unlock')).toBe(false);
        expect(visible('outputPanel')).toBe(true);
        expect(target<HTMLInputElement>('key').value).toBe(key);

        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        byId('copy-output').click();
        expect(writeText).toHaveBeenCalledWith('preview me');
        expect(modal.querySelector('[data-nowo-ui-toast]')?.textContent).toBe('Copied to clipboard.');

        byId('close').click();
        expect(visible('outputPanel')).toBe(false);
        expect(visible('unlock')).toBe(true);
        expect(modal.open).toBe(false);
        expect(target('output').textContent).toBe('');
        expect(visible('output')).toBe(false);
        expect(target<HTMLInputElement>('key').value).toBe('');
        expect(target<HTMLInputElement>('publicLink').value).toBe('');
    });

    it('wipes the decrypted text when the dialog is closed outside the controller (Escape, backdrop)', async () => {
        const { ciphertext, key } = await encryptText('do not linger');
        storeShareKey('share-1', 'key', key);
        respond({ status: 'ok', availability: 'active', ciphertext, mode: 'key', publicPath: '/s/share-1' });

        await mount();
        byId('open').click();
        await vi.waitFor(() => expect(target('output').textContent).toBe('do not linger'));

        target<HTMLDialogElement>('modal').close();
        await vi.waitFor(() => expect(target('output').textContent).toBe(''));
        expect(visible('output')).toBe(false);
        expect(target<HTMLInputElement>('key').value).toBe('');
        expect(document.body.textContent).not.toContain('do not linger');
    });

    it('resets the preview state when the dialog is closed outside the controller (Escape, UiKit close)', async () => {
        respond({ status: 'ok', availability: 'active', publicPath: '/s/share-1' });

        const controller = await mount();
        byId('open').click();
        await vi.waitFor(() => expect(target<HTMLDialogElement>('modal').open).toBe(true));
        expect((controller as unknown as { activeShareId: string | null }).activeShareId).toBe('share-1');

        target<HTMLDialogElement>('modal').close();
        await vi.waitFor(() => expect((controller as unknown as { activeShareId: string | null }).activeShareId).toBeNull());
    });

    it('never embeds the stored key in the public link when embedding is not allowed', async () => {
        const { ciphertext, key } = await encryptText('no embed');
        storeShareKey('share-1', 'key', key);
        respond({ status: 'ok', availability: 'active', ciphertext, mode: 'key', publicPath: '/s/share-1' });

        await mount();
        byId('open').click();
        await vi.waitFor(() => expect(target('output').textContent).toBe('no embed'));

        expect(target<HTMLInputElement>('publicLink').value).toBe(`${window.location.origin}/s/share-1`);
    });

    it('asks for the key when none is stored and decrypts from the form', async () => {
        const { ciphertext, key } = await encryptText('manual');
        respond({ status: 'ok', availability: 'expired', ciphertext, publicPath: '/s/share-1' });

        await mount();
        byId('open').click();
        await vi.waitFor(() => expect(visible('keyPanel')).toBe(true));

        expect(visible('passwordPanel')).toBe(false);
        expect(target('availability').textContent).toBe('expired');
        expect(target<HTMLInputElement>('publicLink').value).toBe(`${window.location.origin}/s/share-1`);
        expect(visible('accessLogPanel')).toBe(false);

        submit('decrypt-form');
        await vi.waitFor(() => expect(target('error').textContent).toBe('Enter the key.'));

        target<HTMLInputElement>('key').value = 'not-the-key';
        submit('decrypt-form');
        await vi.waitFor(() => expect(target('error').textContent).toBe('Wrong key.'));

        target<HTMLInputElement>('key').value = ` ${key} `;
        submit('decrypt-form');
        await vi.waitFor(() => expect(target('output').textContent).toBe('manual'));
        expect(visible('error')).toBe(false);
    });

    it('shows the password panel for password shares and prefills a stored password', async () => {
        storeShareKey('share-1', 'password', 'REDACTED-TEST-PASSWORD');
        respond({ status: 'ok', ciphertext: 'envelope', mode: 'password', publicPath: '/s/share-1' });

        await mount({ allowEmbed: true });
        byId('open').click();
        await vi.waitFor(() => expect(visible('passwordPanel')).toBe(true));

        expect(visible('keyPanel')).toBe(false);
        expect(target<HTMLInputElement>('password').value).toBe('REDACTED-TEST-PASSWORD');
        expect(target<HTMLInputElement>('publicLink').value)
            .toBe(`${window.location.origin}/s/share-1#decrypt_key=REDACTED-TEST-PASSWORD`);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        vi.mocked(decryptPayload).mockResolvedValueOnce({ kind: 'text', text: 'pw content' });
        submit('decrypt-form');
        await vi.waitFor(() => expect(target('output').textContent).toBe('pw content'));
        expect(decryptPayload).toHaveBeenLastCalledWith('envelope', 'REDACTED-TEST-PASSWORD');
    });

    it('requires a password in password mode and needs ciphertext to decrypt', async () => {
        respond({ status: 'ok', mode: 'password' });

        await mount();
        byId('open').click();
        await vi.waitFor(() => expect(visible('passwordPanel')).toBe(true));

        submit('decrypt-form');
        await vi.waitFor(() => expect(target('error').textContent).toBe('Enter the password.'));

        target<HTMLInputElement>('password').value = 'something';
        submit('decrypt-form');
        await vi.waitFor(() => expect(target('error').textContent).toBe('Preview failed.'));
    });

    it('renders the access log rows as text (no HTML injection)', async () => {
        respond({
            status: 'ok',
            accessLog: [
                { accessedAt: '2026-01-02T03:04:05Z', readNumber: 1, ipAddress: '192.0.2.1', userAgent: '<b>UA</b>' },
                { accessedAt: 'not a date', readNumber: 2, ipAddress: null, userAgent: null },
            ],
        });

        await mount();
        byId('open').click();
        await vi.waitFor(() => expect(visible('accessLogTable')).toBe(true));

        const rows = Array.from(target('accessLogBody').querySelectorAll('tr'));
        expect(rows).toHaveLength(2);
        const cells = rows.map((row) => Array.from(row.querySelectorAll('td')).map((td) => td.textContent));
        expect(cells[0][0]).toBe(new Date('2026-01-02T03:04:05Z').toLocaleString());
        expect(cells[0].slice(1)).toEqual(['1', '192.0.2.1', '<b>UA</b>']);
        expect(rows[0].querySelector('b')).toBeNull();
        expect(cells[1]).toEqual(['not a date', '2', '—', '—']);
        expect(visible('accessLogEmpty')).toBe(false);
    });

    it('offers decrypted files as a download link', async () => {
        const createObjectURL = vi.fn(() => 'blob:test-url');
        const original = URL.createObjectURL;
        URL.createObjectURL = createObjectURL;
        onTestFinished(() => {
            URL.createObjectURL = original;
        });
        const file = new File(['file body'], 'report.txt', { type: 'text/plain' });
        const encrypted = await encryptPayload(await readFileAsPayload(file), { generateKey: true });
        storeShareKey('share-1', 'key', encrypted.decryptionKey!);
        respond({ status: 'ok', ciphertext: encrypted.ciphertext });

        await mount();
        byId('open').click();
        await vi.waitFor(() => expect(visible('downloadLink')).toBe(true));

        const link = target<HTMLAnchorElement>('downloadLink');
        expect(link.getAttribute('href')).toBe('blob:test-url');
        expect(link.download).toBe('report.txt');
        expect(link.textContent).toBe('report.txt');
        expect(visible('output')).toBe(false);

        const revokeObjectURL = vi.fn();
        const originalRevoke = URL.revokeObjectURL;
        URL.revokeObjectURL = revokeObjectURL;
        onTestFinished(() => {
            URL.revokeObjectURL = originalRevoke;
        });

        byId('close').click();
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:test-url');
        expect(link.hasAttribute('href')).toBe(false);
        expect(link.textContent).toBe('');
        expect(visible('downloadLink')).toBe(false);
    });

    it('shows PREVIEW_FAILED when the preview request fails', async () => {
        respond({}, false);

        await mount();
        byId('open').click();
        await vi.waitFor(() => expect(target('error').textContent).toBe('Preview failed.'));

        fetchMock.mockRejectedValueOnce(new Error('offline'));
        byId('open').click();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await vi.waitFor(() => expect(visible('error')).toBe(true));
    });

    it('copies the public link to the clipboard', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await mount();
        target<HTMLInputElement>('publicLink').value = 'https://example.test/s/share-1';

        byId('copy').click();

        expect(writeText).toHaveBeenCalledWith('https://example.test/s/share-1');
        // Toast inside the open <dialog> (top layer), UiKit markup, no inline style.
        const toast = target('modal').querySelector<HTMLElement>('[data-nowo-ui-toast-stack] [data-nowo-ui-toast]');
        expect(toast?.className).toBe('nowo-ui-toast');
        expect(toast?.getAttribute('role')).toBe('status');
        expect(toast?.textContent).toBe('Copied to clipboard.');
        expect(toast?.hasAttribute('style')).toBe(false);
    });

    it('does not copy an empty output', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await mount();

        byId('copy-output').click();

        expect(writeText).not.toHaveBeenCalled();
        expect(target('modal').querySelector('[data-nowo-ui-toast]')).toBeNull();
    });

    describe('extend', () => {
        const extendable = {
            status: 'ok',
            mode: 'key',
            extendable: true,
            maxReads: 3,
            readsLeft: 2,
            expiresAt: '2026-05-01T10:00:00Z',
            accessLog: [],
        };

        it('opens on the extend panel and hides read limits that would not extend', async () => {
            respond(extendable);

            await mount();
            byId('open-extend').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'nearest' });
            expect(target('extendSummary').textContent)
                .toBe(`${new Date('2026-05-01T10:00:00Z').toLocaleString()} · 2 / 3`);
            const hidden = Array.from(target<HTMLSelectElement>('extendMaxReads').options).map((o) => [o.value, o.hidden]);
            expect(hidden).toEqual([['', false], ['1', true], ['3', true], ['5', false], ['x', true]]);
        });

        it('summarises missing limits with placeholders', async () => {
            respond({ status: 'ok', extendable: true });

            await mount();
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
            expect(target('extendSummary').textContent).toBe('— · 0 / 0');
        });

        it('posts the new limits with the CSRF token and updates the dialog in place (no reload)', async () => {
            const reload = vi.fn();
            Object.defineProperty(window.location, 'reload', { value: reload, configurable: true });
            respond({ ...extendable, publicPath: '/s/share-1' });
            storeShareKey('share-1', 'key', 'REDACTED-TEST-KEY');

            await mount({ allowEmbed: true });
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));
            const linkBefore = target<HTMLInputElement>('publicLink').value;
            expect(linkBefore).toBe(`${window.location.origin}/s/share-1#decrypt_key=REDACTED-TEST-KEY`);

            target<HTMLSelectElement>('extendExpiresIn').value = '1d';
            target<HTMLSelectElement>('extendMaxReads').value = '5';
            respond({ status: 'ok', availability: 'active', maxReads: 5, readsLeft: 4, expiresAt: '2026-05-02T10:00:00Z' });
            submit('extend-form');
            await vi.waitFor(() => expect(visible('extendSuccess')).toBe(true));

            expect(fetchMock).toHaveBeenLastCalledWith('/manage/share-1/extend', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_PLACEHOLDER,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ expiresIn: '1d', maxReads: 5 }),
                signal: expect.any(AbortSignal),
            });
            expect(target('extendSuccess').textContent).toBe('Limits extended.');
            expect(target('availability').textContent).toBe('Active');
            expect(target('extendSummary').textContent).toContain('4 / 5');
            // The re-rendered link keeps the key of the active share (renderMetadata receives the share id).
            expect(target<HTMLInputElement>('publicLink').value).toBe(linkBefore);
            // Selects reset, Extend disabled again, no "Will be" line, never a reload.
            expect(target<HTMLSelectElement>('extendExpiresIn').value).toBe('');
            expect(target<HTMLSelectElement>('extendMaxReads').value).toBe('');
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(true);
            expect(visible('extendPreview')).toBe(false);
            await new Promise((resolve) => setTimeout(resolve, 900));
            expect(reload).not.toHaveBeenCalled();
        });

        it('sends an empty body when nothing changes and shows server errors', async () => {
            respond(extendable);
            await mount();
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            respond({ status: 'error', error: 'NOT_EXTENDABLE' }, false);
            submit('extend-form');
            await vi.waitFor(() => expect(target('extendError').textContent).toBe('NOT_EXTENDABLE'));
            expect(fetchMock.mock.calls[1][1].body).toBe('{}');
            expect(visible('extendSuccess')).toBe(false);

            respond({ status: 'error' }, false);
            submit('extend-form');
            await vi.waitFor(() => expect(target('extendError').textContent).toBe('Extend failed.'));

            target('extendError').hidden = true;
            fetchMock.mockRejectedValueOnce(new Error('offline'));
            submit('extend-form');
            await vi.waitFor(() => expect(visible('extendError')).toBe(true));
            expect(target('extendError').textContent).toBe('Extend failed.');
        });

        it('shows extend errors in the dialog error region without an inline extend error target', async () => {
            respond(extendable);
            await mount({ extendRow: false });
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            respond({ status: 'error', error: 'NOT_EXTENDABLE' }, false);
            submit('extend-form');
            await vi.waitFor(() => expect(target('error').textContent).toBe('NOT_EXTENDABLE'));
            // syncExtend without the button / preview targets is a no-op.
            target<HTMLSelectElement>('extendMaxReads').value = '5';
            target('extendMaxReads').dispatchEvent(new Event('change'));
        });

        it('keeps Extend disabled until a select changes and previews the result', async () => {
            vi.useFakeTimers({ now: new Date('2026-04-30T10:00:00Z'), toFake: ['Date'] });
            onTestFinished(() => {
                vi.useRealTimers();
            });
            respond(extendable);
            await mount({ extendLabels: true });

            const select = (name: string, value: string): void => {
                target<HTMLSelectElement>(name).value = value;
                target(name).dispatchEvent(new Event('change'));
            };

            // Before the share loads: nothing to extend.
            select('extendMaxReads', '5');
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(true);
            expect(visible('extendPreview')).toBe(false);

            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));
            const now = new Date('2026-05-01T10:00:00Z').toLocaleString();
            expect(target('extendSummary').textContent).toBe(`expires ${now} · 2 reads`);
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(true);
            expect(visible('extendPreview')).toBe(false);

            // Later expiry: base is the current expiry (still in the future) + 1 day.
            select('extendExpiresIn', '1d');
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(false);
            expect(visible('extendPreview')).toBe(true);
            expect(target('extendPreview').textContent)
                .toBe(`Will be: expires ${new Date('2026-05-02T10:00:00Z').toLocaleString()} · 2 reads`);

            // Raising the maximum adds the difference to the reads left (3 → 5: 2 + 2).
            select('extendMaxReads', '5');
            expect(target('extendPreview').textContent)
                .toBe(`Will be: expires ${new Date('2026-05-02T10:00:00Z').toLocaleString()} · 4 reads`);

            // An option without seconds keeps the current expiry; a value that does not raise keeps the reads.
            select('extendExpiresIn', 'odd');
            select('extendMaxReads', '1');
            expect(target('extendPreview').textContent).toBe(`Will be: expires ${now} · 2 reads`);

            // Back to "No change" on both: disabled, no preview.
            select('extendExpiresIn', '');
            select('extendMaxReads', '');
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(true);
            expect(visible('extendPreview')).toBe(false);
            expect(target('extendPreview').textContent).toBe('');
        });

        it('previews from now when the share has no or a past expiry, with singular reads', async () => {
            vi.useFakeTimers({ now: new Date('2026-06-01T10:00:00Z'), toFake: ['Date'] });
            onTestFinished(() => {
                vi.useRealTimers();
            });
            respond({ status: 'ok', mode: 'key', extendable: true, maxReads: 1, readsLeft: 1, expiresAt: '2026-05-01T10:00:00Z', accessLog: [] });
            await mount({ extendLabels: true });
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            target<HTMLSelectElement>('extendExpiresIn').value = '1d';
            target('extendExpiresIn').dispatchEvent(new Event('change'));
            expect(target('extendPreview').textContent)
                .toBe(`Will be: expires ${new Date('2026-06-02T10:00:00Z').toLocaleString()} · 1 read`);

            app?.stop();
            respond({ status: 'ok', mode: 'key', extendable: true, accessLog: [] });
            await mount({ extendLabels: true });
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));
            expect(target('extendSummary').textContent).toBe('expires — · 0 reads');
            target<HTMLSelectElement>('extendExpiresIn').value = '1d';
            target('extendExpiresIn').dispatchEvent(new Event('change'));
            expect(target('extendPreview').textContent)
                .toBe(`Will be: expires ${new Date('2026-06-02T10:00:00Z').toLocaleString()} · 0 reads`);
        });

        it('falls back to the raw count without reads labels', async () => {
            respond(extendable);
            document.body.innerHTML = markup().replace(
                'data-preview-copied-message-value',
                `data-preview-extend-labels-value='{"summary":"%expires% / %reads%"}' data-preview-copied-message-value`,
            );
            app = new Application(document.documentElement);
            await app.start();
            app.register('preview', YopassManagePreviewController);
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));
            expect(target('extendSummary').textContent).toBe(`${new Date('2026-05-01T10:00:00Z').toLocaleString()} / 2`);
            target<HTMLSelectElement>('extendMaxReads').value = '5';
            target('extendMaxReads').dispatchEvent(new Event('change'));
            expect(target('extendPreview').textContent).toBe(`Will be: ${new Date('2026-05-01T10:00:00Z').toLocaleString()} / 4`);
        });

        it('FO-L1: a stale extend response after close + reopen never lands in the other share', async () => {
            storeShareKey('share-1', 'key', 'KEY-OF-A');
            storeShareKey('share-2', 'key', 'KEY-OF-B');
            await mount({ allowEmbed: true });
            const open2 = document.createElement('button');
            open2.id = 'open-2';
            open2.dataset.shareId = 'share-2';
            open2.dataset.action = 'preview#open';
            document.querySelector('[data-controller="preview"]')!.prepend(open2);
            await new Promise((resolve) => setTimeout(resolve, 0));

            respond({ ...extendable, publicPath: '/s/share-1' });
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            // Extend share-1; hold its response.
            let release: (value: unknown) => void = () => undefined;
            fetchMock.mockReturnValueOnce(new Promise((resolve) => {
                release = resolve;
            }));
            target<HTMLSelectElement>('extendExpiresIn').value = '1d';
            target('extendExpiresIn').dispatchEvent(new Event('change'));
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(false);
            submit('extend-form');
            // FO-I1: disabled while in flight; a second submit sends nothing.
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(true);
            target('extendMaxReads').dispatchEvent(new Event('change'));
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(true);
            submit('extend-form');
            expect(fetchMock).toHaveBeenCalledTimes(2);
            const signal = (fetchMock.mock.calls[1][1] as RequestInit).signal!;

            // Close: the request is aborted. Open share-2.
            byId('close').click();
            expect(signal.aborted).toBe(true);
            respond({ status: 'ok', mode: 'key', extendable: true, maxReads: 10, readsLeft: 10, expiresAt: '2026-06-01T10:00:00Z', publicPath: '/s/share-2' });
            open2.click();
            await vi.waitFor(() => expect(target<HTMLInputElement>('publicLink').value).toContain('/s/share-2'));
            const linkB = target<HTMLInputElement>('publicLink').value;
            const summaryB = target('extendSummary').textContent;
            expect(linkB).toBe(`${window.location.origin}/s/share-2#decrypt_key=KEY-OF-B`);

            release({ ok: true, json: () => Promise.resolve({ status: 'ok', availability: 'active', maxReads: 3, readsLeft: 2, expiresAt: '2026-05-02T10:00:00Z' }) });
            await new Promise((resolve) => setTimeout(resolve, 0));
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(target<HTMLInputElement>('publicLink').value).toBe(linkB);
            expect(target<HTMLInputElement>('publicLink').value).not.toContain('KEY-OF-A');
            expect(target('extendSummary').textContent).toBe(summaryB);
            expect(visible('extendSuccess')).toBe(false);
            expect(fetchMock.mock.calls.map((call) => call[0])).toEqual(['/manage/share-1/preview', '/manage/share-1/extend', '/manage/share-2/preview']);
        });

        it('FO-L1: an aborted or stale failing request shows no error; reopening the same share aborts too', async () => {
            respond(extendable);
            await mount();
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            let reject: (reason: unknown) => void = () => undefined;
            fetchMock.mockReturnValueOnce(new Promise((_resolve, rejectFn) => {
                reject = rejectFn;
            }));
            target<HTMLSelectElement>('extendMaxReads').value = '5';
            submit('extend-form');
            const signal = (fetchMock.mock.calls[1][1] as RequestInit).signal!;

            // Reopen (no close in between): the in-flight request is aborted.
            respond(extendable);
            byId('open').click();
            expect(signal.aborted).toBe(true);
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));
            reject(new DOMException('aborted', 'AbortError'));
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(visible('extendError')).toBe(false);
            // The new dialog can extend again.
            target<HTMLSelectElement>('extendMaxReads').value = '5';
            target('extendMaxReads').dispatchEvent(new Event('change'));
            expect(target<HTMLButtonElement>('extendButton').disabled).toBe(false);
        });

                it('does nothing without an open share or extend URL', async () => {
            await mount();
            submit('extend-form');

            app?.stop();
            respond(extendable);
            await mount({ extendPath: '' });
            byId('open').click();
            await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
            submit('extend-form');
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(fetchMock).toHaveBeenCalledTimes(1);
        });
    });

    it('works with only the required targets', async () => {
        const { ciphertext, key } = await encryptText('bare');
        storeShareKey('share-1', 'key', key);
        respond({ status: 'ok', ciphertext, publicPath: '/s/share-1', availability: 'active', extendable: true, accessLog: [] });

        const controller = await mount({ optionalTargets: false });
        byId('open').click();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await new Promise((resolve) => setTimeout(resolve, 0));

        respond({ status: 'ok', mode: 'password' });
        byId('open').click();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        respond({ status: 'ok', accessLog: [{ accessedAt: 'x', readNumber: 1, ipAddress: null, userAgent: null }] });
        byId('open').click();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));

        expect(() => controller.copyPublicLink()).not.toThrow();
        submit('decrypt-form');
        expect(() => controller.close()).not.toThrow();
    });
});
