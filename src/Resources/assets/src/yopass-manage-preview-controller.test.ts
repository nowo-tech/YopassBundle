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

type MountOptions = { extendPath?: string; optionalTargets?: boolean; allowEmbed?: boolean };

function markup(options: MountOptions = {}): string {
    const optional = options.optionalTargets === false
        ? ''
        : `
            <div data-preview-target="error" class="d-none"></div>
            <pre data-preview-target="output" class="d-none"></pre>
            <a data-preview-target="downloadLink" class="d-none"></a>
            <input data-preview-target="publicLink">
            <button id="copy" data-action="preview#copyPublicLink">Copy</button>
            <span data-preview-target="availability"></span>
            <div data-preview-target="passwordPanel" class="d-none"></div>
            <div data-preview-target="keyPanel" class="d-none"></div>
            <button data-preview-target="decryptButton">Decrypt</button>
            <div data-preview-target="extendPanel" class="d-none">
                <span data-preview-target="extendSummary"></span>
                <div data-preview-target="extendSuccess" class="d-none"></div>
            </div>
            <div data-preview-target="accessLogPanel" class="d-none">
                <p data-preview-target="accessLogEmpty" class="d-none">No reads yet</p>
                <table data-preview-target="accessLogTable" class="d-none">
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
             ${options.allowEmbed === true ? 'data-preview-allow-embed-in-url-value="true"' : ''}>
            <button id="open" data-share-id="share-1" data-action="preview#open">Preview</button>
            <button id="open-extend" data-share-id="share-1" data-action="preview#openExtend">Extend</button>
            <button id="open-missing" data-action="preview#open">Broken</button>
            <div data-preview-target="modal" aria-hidden="true" style="display:none">
                ${optional}
                <form id="decrypt-form" data-action="submit->preview#decryptFromForm">
                    <input data-preview-target="password">
                    <input data-preview-target="key">
                </form>
                <form id="extend-form" data-action="submit->preview#submitExtend">
                    <select data-preview-target="extendExpiresIn">
                        <option value="">Keep</option>
                        <option value="1d">1 day</option>
                    </select>
                    <select data-preview-target="extendMaxReads">
                        <option value="">Keep</option>
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                        <option value="x">bogus</option>
                    </select>
                </form>
                <button id="close" data-action="preview#close">Close</button>
            </div>
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
    return !target(name).classList.contains('d-none');
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
        expect(target('modal').style.display).toBe('none');
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
        const modal = target('modal');
        expect(modal.classList.contains('show')).toBe(true);
        expect(modal.style.display).toBe('block');
        expect(modal.hasAttribute('aria-hidden')).toBe(false);
        expect(document.body.classList.contains('modal-open')).toBe(true);
        expect(target('availability').textContent).toBe('Active');
        expect(target<HTMLInputElement>('publicLink').value)
            .toBe(`${window.location.origin}/s/share-1#decrypt_key=${encodeURIComponent(key)}`);
        expect(visible('accessLogPanel')).toBe(true);
        expect(visible('accessLogEmpty')).toBe(true);
        expect(visible('accessLogTable')).toBe(false);
        expect(visible('extendPanel')).toBe(false);
        expect(visible('decryptButton')).toBe(false);
        expect(visible('keyPanel')).toBe(false);
        expect(target<HTMLInputElement>('key').value).toBe(key);

        byId('close').click();
        expect(modal.classList.contains('show')).toBe(false);
        expect(modal.style.display).toBe('none');
        expect(modal.getAttribute('aria-hidden')).toBe('true');
        expect(document.body.classList.contains('modal-open')).toBe(false);
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

        it('posts the new limits with the CSRF token and schedules a reload', async () => {
            const reload = vi.fn();
            const timers: Array<{ fn: () => void; delay?: number }> = [];
            const realSetTimeout = window.setTimeout.bind(window);
            vi.spyOn(window, 'setTimeout').mockImplementation(((fn: () => void, delay?: number) => {
                if (delay === 800) {
                    timers.push({ fn, delay });

                    return 0;
                }

                return realSetTimeout(fn, delay);
            }) as typeof window.setTimeout);
            onTestFinished(() => {
                vi.mocked(window.setTimeout).mockRestore();
            });
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
            });
            expect(target('extendSuccess').textContent).toBe('Limits extended.');
            expect(target('availability').textContent).toBe('Active');
            expect(target('extendSummary').textContent).toContain('4 / 5');
            // The re-rendered link keeps the key of the active share (renderMetadata receives the share id).
            expect(target<HTMLInputElement>('publicLink').value).toBe(linkBefore);
            expect(timers).toHaveLength(1);

            Object.defineProperty(window.location, 'reload', { value: reload, configurable: true });
            timers[0].fn();
            expect(reload).toHaveBeenCalledTimes(1);
        });

        it('sends an empty body when nothing changes and shows server errors', async () => {
            respond(extendable);
            await mount();
            byId('open').click();
            await vi.waitFor(() => expect(visible('extendPanel')).toBe(true));

            respond({ status: 'error', error: 'NOT_EXTENDABLE' }, false);
            submit('extend-form');
            await vi.waitFor(() => expect(target('error').textContent).toBe('NOT_EXTENDABLE'));
            expect(fetchMock.mock.calls[1][1].body).toBe('{}');
            expect(visible('extendSuccess')).toBe(false);

            respond({ status: 'error' }, false);
            submit('extend-form');
            await vi.waitFor(() => expect(target('error').textContent).toBe('Extend failed.'));

            target('error').classList.add('d-none');
            fetchMock.mockRejectedValueOnce(new Error('offline'));
            submit('extend-form');
            await vi.waitFor(() => expect(visible('error')).toBe(true));
            expect(target('error').textContent).toBe('Extend failed.');
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
