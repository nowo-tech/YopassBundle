// @vitest-environment happy-dom
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import YopassCreateController, { CREATED_EVENT } from './yopass-create-controller';
import { encryptPayload } from './yopass-crypto';
import { reloadPage } from './yopass-navigation';
import { PENDING_KEY_STORAGE } from './yopass-share-keys';

// Deterministic placeholders: no real crypto, no real secret.
vi.mock('./yopass-crypto', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./yopass-crypto')>();

    return { ...actual, encryptPayload: vi.fn() };
});
vi.mock('./yopass-navigation', () => ({ reloadPage: vi.fn() }));

const PLAINTEXT = 'PLACEHOLDER-PLAINTEXT';
const KEY = 'PLACEHOLDER-KEY';
const PASSWORD = 'PLACEHOLDER-PASSWORD';
const CIPHERTEXT = '{"v":1,"ciphertext":"PLACEHOLDER-CT"}';

let app: Application | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

const ERRORS = JSON.stringify({
    CREATE_FAILED: 'Could not create the share.',
    FILE_NOT_ENABLED: 'File shares are not enabled.',
    PASSWORD_REQUIRED: 'Please enter a password.',
});

function markup(options: { allowEmbed?: boolean; remember?: boolean; oneClick?: boolean; file?: boolean; password?: boolean; rememberTarget?: boolean } = {}): string {
    return `
        <dialog id="create-dialog" open>
            <div data-controller="create"
                 data-create-max-secret-chars-value="100"
                 data-create-errors-value='${ERRORS}'
                 data-create-async-value="true"
                 data-create-allow-embed-in-url-value="${options.allowEmbed ?? true ? 'true' : 'false'}"
                 data-create-show-remember-notice-value="${options.remember ?? true ? 'true' : 'false'}">
                <form data-create-target="form" action="/tools/yopass/create" method="post" data-action="submit->create#submit">
                    <input type="hidden" name="share_create[ciphertext]" data-create-target="ciphertext">
                    <input type="hidden" name="share_create[payloadKind]" value="text" data-create-target="payloadKind">
                    <input type="hidden" name="share_create[_token]" value="CSRF-PLACEHOLDER">
                    <div data-create-target="textPanel">
                        <textarea data-create-target="secret"></textarea>
                        <span data-create-target="secretCounter"></span>
                    </div>
                    <input type="radio" name="enc" data-create-target="encryptionAuto" data-action="create#syncKeyMode" checked>
                    <input type="radio" name="enc" data-create-target="encryptionPassword" data-action="create#syncKeyMode">
                    ${options.password ?? true ? `<div data-create-target="customPasswordPanel" hidden>
                        <input type="password" data-create-target="customPassword">
                    </div>` : ''}
                    ${options.file ? '<div data-create-target="filePanel" hidden><input type="file" data-create-target="fileInput"></div>' : ''}
                    <div data-create-target="error" hidden></div>
                    <button type="submit" data-create-target="submit">Create</button>
                </form>
                <section data-create-target="result" hidden>
                    ${options.rememberTarget ?? true ? '<div data-create-target="rememberNotice" hidden>remember</div>' : ''}
                    <form id="links" data-action="submit->create#preventSubmit">
                        ${options.oneClick ?? true ? '<input id="one-click" readonly data-create-target="resultOneClickLink">' : ''}
                        <input id="short" readonly data-create-target="resultShortLink">
                        <div data-create-target="resultKeyRow"><input id="key" readonly data-create-target="resultKey"></div>
                        <p data-create-target="passwordNote" hidden>note</p>
                    </form>
                    <button type="button" id="copy-one-click" data-action="create#copyOneClickLink">c1</button>
                    <button type="button" id="copy-short" data-action="create#copyShortLink">c2</button>
                    <button type="button" id="copy-key" data-action="create#copyKey">c3</button>
                    <button type="button" id="done" data-action="create#done">Done</button>
                </section>
            </div>
        </dialog>`;
}

async function mount(options: Parameters<typeof markup>[0] = {}): Promise<void> {
    document.body.innerHTML = markup(options);
    app = new Application(document.documentElement);
    await app.start();
    app.register('create', YopassCreateController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const el = <T extends HTMLElement = HTMLElement>(selector: string): T => document.querySelector(selector) as T;
const target = <T extends HTMLElement = HTMLElement>(name: string): T => el<T>(`[data-create-target="${name}"]`);

function typeSecret(value: string): void {
    target<HTMLTextAreaElement>('secret').value = value;
}

function submitForm(): void {
    target<HTMLFormElement>('form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
}

function respond(status: number, body: unknown): void {
    fetchMock.mockResolvedValueOnce(new Response(body === undefined ? 'not json' : JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    }));
}

function closeDialog(): void {
    el<HTMLDialogElement>('#create-dialog').dispatchEvent(new Event('close'));
}

function dumpDom(): string {
    const values = Array.from(document.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('input, textarea'))
        .map((input) => input.value)
        .join('|');

    return `${document.body.innerHTML}|${values}`;
}

describe('yopass-create-controller (dialog mode, async)', () => {
    beforeEach(() => {
        sessionStorage.clear();
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        vi.mocked(encryptPayload).mockReset();
        vi.mocked(encryptPayload).mockResolvedValue({ ciphertext: CIPHERTEXT, decryptionKey: KEY });
        vi.mocked(reloadPage).mockReset();
        HTMLFormElement.prototype.submit = vi.fn() as unknown as () => void;
    });

    afterEach(() => {
        app?.stop();
        app = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('posts the ciphertext with fetch (same origin, JSON) and renders the links in the dialog', async () => {
        respond(201, { id: 'share-1', public_path: '/share/share-1', created_url: '/tools/yopass/share-1/created' });
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));

        expect(HTMLFormElement.prototype.submit).not.toHaveBeenCalled();
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toContain('/tools/yopass/create');
        expect(init).toMatchObject({
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            mode: 'same-origin',
            redirect: 'error',
        });

        // The request carries the ciphertext and the CSRF token, never the plaintext or the key.
        const body = init.body as FormData;
        expect(body.get('share_create[ciphertext]')).toBe(CIPHERTEXT);
        expect(body.get('share_create[_token]')).toBe('CSRF-PLACEHOLDER');
        const sent = Array.from(body.values()).map(String).join('|');
        expect(sent).not.toContain(PLAINTEXT);
        expect(sent).not.toContain(KEY);

        expect(target<HTMLFormElement>('form').hidden).toBe(true);
        expect(target<HTMLInputElement>('resultShortLink').value).toBe(`${window.location.origin}/share/share-1`);
        expect(target<HTMLInputElement>('resultKey').value).toBe(KEY);
        expect(target<HTMLInputElement>('resultOneClickLink').value)
            .toBe(`${window.location.origin}/share/share-1#decrypt_key=${KEY}`);
        expect(target('rememberNotice').hidden).toBe(false);
        expect(target('passwordNote').hidden).toBe(true);
        // Key in memory / DOM only; the plaintext and the ciphertext are gone from the form.
        expect(sessionStorage.length).toBe(0);
        expect(target<HTMLTextAreaElement>('secret').value).toBe('');
        expect(target<HTMLInputElement>('ciphertext').value).toBe('');
    });

    it('omits the one-click link when embedding is not allowed and hides the remember notice when disabled', async () => {
        respond(201, { id: 'share-2', public_path: '/share/share-2' });
        await mount({ allowEmbed: false, remember: false });
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));

        expect(target<HTMLInputElement>('resultOneClickLink').value).toBe('');
        expect(target('rememberNotice').hidden).toBe(true);
    });

    it('works without the one-click field', async () => {
        respond(201, { id: 'share-3', public_path: '/share/share-3' });
        await mount({ oneClick: false });
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));

        el('#copy-one-click').click();
        expect(target<HTMLInputElement>('resultKey').value).toBe(KEY);
    });

    it('password mode: no key field, the note instead, the password wiped from the form', async () => {
        vi.mocked(encryptPayload).mockResolvedValueOnce({ ciphertext: CIPHERTEXT });
        respond(201, { id: 'share-4', public_path: '/share/share-4' });
        await mount();
        typeSecret(PLAINTEXT);
        target<HTMLInputElement>('encryptionPassword').checked = true;
        target<HTMLInputElement>('encryptionPassword').dispatchEvent(new Event('input'));
        target<HTMLInputElement>('customPassword').value = PASSWORD;
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));

        expect(target('resultKeyRow').hidden).toBe(true);
        expect(target('passwordNote').hidden).toBe(false);
        expect(target<HTMLInputElement>('resultKey').value).toBe('');
        expect(target<HTMLInputElement>('customPassword').value).toBe('');
        expect(target<HTMLInputElement>('resultOneClickLink').value).toContain('#decrypt_key=');
    });

    it.each([
        [422, { error: 'FILE_NOT_ENABLED' }, 'File shares are not enabled.'],
        [422, { error: 'CREATE_FAILED' }, 'Could not create the share.'],
        [422, { error: '<img src=x onerror=alert(1)>' }, 'Could not create the share.'],
        [500, undefined, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: 'https://evil.example/x' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '//evil.example/x' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/\\\\evil.example/x' }, 'Could not create the share.'],
        [201, { id: '../x', public_path: '/share/x' }, 'Could not create the share.'],
        // ET-I2: dot segments, encoded dot / backslash, tab / control characters (consistent with UiKit).
        [201, { id: 'share-5', public_path: '/.//evil.example' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/../evil.example' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/share/./x' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/%2e%2e/evil.example' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/%2E/evil.example' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/%5c/evil.example' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/\t/evil.example' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: '/share/x\u0000' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: ' /share/x' }, 'Could not create the share.'],
        [201, { id: 'share-5', public_path: 'javascript:alert(1)' }, 'Could not create the share.'],
        [201, null, 'Could not create the share.'],
        [200, { id: 'share-5', public_path: '/share/share-5' }, 'Could not create the share.'],
    ])('keeps the form and shows the error (status %s)', async (status, body, message) => {
        respond(status, body);
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('error').hidden).toBe(false));

        expect(target('error').textContent).toBe(message);
        expect(target('result').hidden).toBe(true);
        expect(target<HTMLFormElement>('form').hidden).toBe(false);
        expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
        expect(target<HTMLInputElement>('ciphertext').value).toBe('');
        expect(target<HTMLInputElement>('resultKey').value).toBe('');
    });

    it('handles the optional targets (file input, no custom password, no remember notice)', async () => {
        respond(201, { id: 'share-9', public_path: '/share/share-9' });
        await mount({ file: true, password: false, rememberTarget: false });
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));

        expect(target<HTMLInputElement>('fileInput').value).toBe('');
        closeDialog();
        expect(target<HTMLInputElement>('resultKey').value).toBe('');
        expect(reloadPage).toHaveBeenCalledOnce();
    });

    it('ET-L1: a 201 arriving after the dialog was closed writes nothing and keeps no key', async () => {
        let resolveFetch!: (response: Response) => void;
        fetchMock.mockReturnValueOnce(new Promise<Response>((resolve) => {
            resolveFetch = resolve; // ignores the abort signal on purpose: the stale-token guard must hold on its own
        }));
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        const signal = (fetchMock.mock.calls[0][1] as RequestInit).signal as AbortSignal;

        closeDialog();
        expect(signal.aborted).toBe(true);
        // The share may exist on the server: the close refreshes the list (id unknown).
        expect(reloadPage).toHaveBeenCalledOnce();
        expect(dumpDom()).not.toContain(KEY);

        resolveFetch(new Response(JSON.stringify({ id: 'late-1', public_path: '/share/late-1' }), { status: 201 }));
        await new Promise((resolve) => setTimeout(resolve, 20));

        expect(dumpDom()).not.toContain(KEY);
        expect(dumpDom()).not.toContain('late-1');
        expect(target('result').hidden).toBe(true);
        expect(target<HTMLFormElement>('form').hidden).toBe(false);
        expect(target('error').hidden).toBe(true);
        expect(sessionStorage.length).toBe(0);

        // The next close has nothing to refresh.
        closeDialog();
        expect(reloadPage).toHaveBeenCalledOnce();
    });

    it('ET-L1: an aborted request shows no error and the created event carries a null id', async () => {
        fetchMock.mockImplementationOnce((_url: string, init: RequestInit) => new Promise<Response>((_resolve, reject) => {
            init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
        }));
        const ids: Array<string | null> = [];
        document.addEventListener(CREATED_EVENT, (event) => {
            ids.push((event as CustomEvent<{ id: string | null }>).detail.id);
            event.preventDefault();
        }, { once: true });
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());

        closeDialog();
        await new Promise((resolve) => setTimeout(resolve, 20));

        expect(ids).toEqual([null]);
        expect(reloadPage).not.toHaveBeenCalled();
        expect(target('error').hidden).toBe(true);
        expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
    });

    it('ET-L1: closing while encrypting sends nothing', async () => {
        let resolveEncrypt!: (value: { ciphertext: string; decryptionKey: string }) => void;
        vi.mocked(encryptPayload).mockReturnValueOnce(new Promise((resolve) => {
            resolveEncrypt = resolve;
        }));
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(encryptPayload).toHaveBeenCalled());

        closeDialog();
        resolveEncrypt({ ciphertext: CIPHERTEXT, decryptionKey: KEY });
        await new Promise((resolve) => setTimeout(resolve, 20));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(target<HTMLInputElement>('ciphertext').value).toBe('');
        expect(dumpDom()).not.toContain(KEY);
        expect(reloadPage).not.toHaveBeenCalled();
    });

    it('maps a network error to CREATE_FAILED', async () => {
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('error').hidden).toBe(false));

        expect(target('error').textContent).toBe('Could not create the share.');
    });

    it('wipes the form, the result, the key and sessionStorage on close, then reloads after a create', async () => {
        respond(201, { id: 'share-6', public_path: '/share/share-6' });
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));
        sessionStorage.setItem(PENDING_KEY_STORAGE, JSON.stringify({ mode: 'key', material: KEY }));

        const seen: string[] = [];
        document.addEventListener(CREATED_EVENT, (event) => {
            seen.push((event as CustomEvent<{ id: string }>).detail.id);
        });

        closeDialog();

        expect(seen).toEqual(['share-6']);
        expect(reloadPage).toHaveBeenCalledOnce();
        expect(dumpDom()).not.toContain(KEY);
        expect(dumpDom()).not.toContain(PLAINTEXT);
        expect(target('result').hidden).toBe(true);
        expect(target<HTMLFormElement>('form').hidden).toBe(false);
        expect(sessionStorage.getItem(PENDING_KEY_STORAGE)).toBeNull();

        // A second close does not refresh again.
        closeDialog();
        expect(reloadPage).toHaveBeenCalledOnce();
    });

    it('lets a host refresh differently by preventing the created event', async () => {
        respond(201, { id: 'share-7', public_path: '/share/share-7' });
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));
        document.addEventListener(CREATED_EVENT, (event) => event.preventDefault(), { once: true });

        closeDialog();

        expect(reloadPage).not.toHaveBeenCalled();
        expect(dumpDom()).not.toContain(KEY);
    });

    it('wipes typed secrets on close without a create (no reload) and on the UiKit overlay event', async () => {
        await mount();
        typeSecret(PLAINTEXT);
        target<HTMLInputElement>('customPassword').value = PASSWORD;

        closeDialog();
        expect(target<HTMLTextAreaElement>('secret').value).toBe('');
        expect(target<HTMLInputElement>('customPassword').value).toBe('');
        expect(reloadPage).not.toHaveBeenCalled();

        typeSecret(PLAINTEXT);
        el('#create-dialog').dispatchEvent(new CustomEvent('nowo:overlay-close'));
        expect(target<HTMLTextAreaElement>('secret').value).toBe('');
    });

    it('Done closes the dialog; copy buttons copy the result fields; the links form never submits', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
        respond(201, { id: 'share-8', public_path: '/share/share-8' });
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));

        el('#copy-short').click();
        el('#copy-key').click();
        el('#copy-one-click').click();
        expect(writeText.mock.calls.map((call) => call[0])).toEqual([
            `${window.location.origin}/share/share-8`,
            KEY,
            `${window.location.origin}/share/share-8#decrypt_key=${KEY}`,
        ]);

        const submit = new Event('submit', { cancelable: true, bubbles: true });
        el('#links').dispatchEvent(submit);
        expect(submit.defaultPrevented).toBe(true);

        const dialog = el<HTMLDialogElement>('#create-dialog');
        const close = vi.spyOn(dialog, 'close');
        el('#done').click();
        expect(close).toHaveBeenCalled();
    });

    it('Done does nothing when the dialog is already closed', async () => {
        await mount();
        const dialog = el<HTMLDialogElement>('#create-dialog');
        dialog.removeAttribute('open');
        const close = vi.spyOn(dialog, 'close');

        el('#done').click();

        expect(close).not.toHaveBeenCalled();
    });

    it('aborts the request in flight when the controller disconnects', async () => {
        fetchMock.mockReturnValueOnce(new Promise<Response>(() => undefined));
        await mount();
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        const signal = (fetchMock.mock.calls[0][1] as RequestInit).signal as AbortSignal;

        el('[data-controller="create"]').remove();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(signal.aborted).toBe(true);
    });

    it('clears secrets when the controller disconnects', async () => {
        await mount();
        typeSecret(PLAINTEXT);
        const secret = target<HTMLTextAreaElement>('secret');

        el('[data-controller="create"]').remove();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(secret.value).toBe('');
    });

    it('works outside a dialog (no close listener)', async () => {
        document.body.innerHTML = markup().replace('<dialog id="create-dialog" open>', '<div id="create-dialog">').replace('</dialog>', '</div>');
        app = new Application(document.documentElement);
        await app.start();
        app.register('create', YopassCreateController);
        await new Promise((resolve) => setTimeout(resolve, 0));

        el('#done').click();
        expect(target('result').hidden).toBe(true);
    });

    it('survives an unavailable sessionStorage while wiping', async () => {
        await mount();
        const removeItem = vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new Error('denied');
        });

        try {
            expect(() => closeDialog()).not.toThrow();
        } finally {
            removeItem.mockRestore();
        }
    });

    it('UiKit 2.29 anatomy: the controller wraps the dialog and swaps Cancel / Create for Done in the footer', async () => {
        document.body.innerHTML = `
            <div data-controller="create"
                 data-create-max-secret-chars-value="100"
                 data-create-errors-value='${ERRORS}'
                 data-create-async-value="true">
                <dialog id="create-dialog" open>
                    <div class="body">
                        <form id="create-form" data-create-target="form" action="/tools/yopass/create" method="post" data-action="submit->create#submit">
                            <input type="hidden" name="share_create[ciphertext]" data-create-target="ciphertext">
                            <input type="hidden" name="share_create[payloadKind]" value="text" data-create-target="payloadKind">
                            <div data-create-target="textPanel"><textarea data-create-target="secret"></textarea></div>
                            <div data-create-target="error" hidden></div>
                        </form>
                        <section data-create-target="result" hidden>
                            <input id="short" readonly data-create-target="resultShortLink">
                            <div data-create-target="resultKeyRow"><input id="key" readonly data-create-target="resultKey"></div>
                            <p data-create-target="passwordNote" hidden>note</p>
                        </section>
                    </div>
                    <footer>
                        <button type="button" id="cancel" data-create-target="cancel">Cancel</button>
                        <button type="submit" form="create-form" data-create-target="submit">Create</button>
                        <button type="button" id="done" hidden data-create-target="done" data-action="create#done">Done</button>
                    </footer>
                </dialog>
            </div>`;
        app = new Application(document.documentElement);
        await app.start();
        app.register('create', YopassCreateController);
        await new Promise((resolve) => setTimeout(resolve, 0));

        respond(201, { id: 'share-w', public_path: '/share/share-w' });
        typeSecret(PLAINTEXT);
        submitForm();
        await vi.waitFor(() => expect(target('result').hidden).toBe(false));

        expect(el('#cancel').hidden).toBe(true);
        expect(target('submit').hidden).toBe(true);
        expect(el('#done').hidden).toBe(false);

        // The close listener was found below the controller (querySelector('dialog')): closing wipes and resets.
        closeDialog();
        expect(dumpDom()).not.toContain(KEY);
        expect(target('result').hidden).toBe(true);
        expect(el('#cancel').hidden).toBe(false);
        expect(target('submit').hidden).toBe(false);
        expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
        expect(el('#done').hidden).toBe(true);
    });
});
