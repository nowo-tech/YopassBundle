// @vitest-environment happy-dom
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import ShareRevealController from './share-reveal-controller';
import { decryptPayload, encryptPayload, readFileAsPayload } from './yopass-crypto';
import { loadShareKey, storeShareKey } from './yopass-share-keys';

// Real crypto by default; password-mode tests override decryptPayload once.
vi.mock('./yopass-crypto', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./yopass-crypto')>();

    return { ...actual, decryptPayload: vi.fn(actual.decryptPayload) };
});

const ERRORS = JSON.stringify({
    KEY_REQUIRED: 'Enter the key.',
    PASSWORD_REQUIRED: 'Enter the password.',
    INVALID_KEY: 'Wrong key.',
    not_found: 'Share not found.',
});

let app: Application | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

type Options = {
    mode?: 'key' | 'password';
    shareId?: string;
    autoReveal?: boolean;
    optionalTargets?: boolean;
};

function markup(options: Options = {}): string {
    const optional = options.optionalTargets === false
        ? ''
        : `
            <div data-reveal-target="error" hidden></div>
            <pre data-reveal-target="output" hidden></pre>
            <a data-reveal-target="downloadLink" hidden></a>
            <div data-reveal-target="passwordPanel"></div>
            <div data-reveal-target="passwordFromLinkHint" hidden></div>
            <div data-reveal-target="keyPanel" hidden></div>
            <div data-reveal-target="keyFromLinkHint"></div>
            <button data-reveal-target="revealButton" data-action="reveal#reveal">Reveal</button>`;

    return `
        <div data-controller="reveal"
             data-reveal-consume-url-value="/s/share-1/consume"
             data-reveal-share-id-value="${options.shareId ?? 'share-1'}"
             data-reveal-mode-value="${options.mode ?? 'key'}"
             data-reveal-auto-reveal-value="${options.autoReveal === false ? 'false' : 'true'}"
             data-reveal-errors-value='${ERRORS}'>
            ${optional}
            <input data-reveal-target="password">
            <input data-reveal-target="key">
        </div>`;
}

async function mount(options: Options = {}): Promise<ShareRevealController> {
    document.body.innerHTML = markup(options);
    app = new Application(document.documentElement);
    await app.start();
    app.register('reveal', ShareRevealController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    return app.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="reveal"]')!,
        'reveal',
    ) as ShareRevealController;
}

function target<T extends HTMLElement = HTMLElement>(name: string): T {
    return document.querySelector(`[data-reveal-target="${name}"]`) as T;
}

function visible(name: string): boolean {
    return !target(name).hidden;
}

function respondWith(body: unknown): void {
    fetchMock.mockResolvedValueOnce({ json: () => Promise.resolve(body) });
}

async function encryptText(text: string): Promise<{ ciphertext: string; key: string }> {
    const result = await encryptPayload({ kind: 'text', text }, { generateKey: true });

    return { ciphertext: result.ciphertext, key: result.decryptionKey! };
}

describe('share-reveal-controller', () => {
    beforeEach(() => {
        sessionStorage.clear();
        window.history.replaceState(null, '', '/s/share-1');
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        app?.stop();
        app = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('auto-reveals with the key from the link, decrypting locally', async () => {
        const { ciphertext, key } = await encryptText('the secret text');
        window.history.replaceState(null, '', `/s/share-1?decrypt_key=${encodeURIComponent(key)}`);
        respondWith({ status: 'ok', ciphertext });

        await mount();
        await vi.waitFor(() => expect(visible('output')).toBe(true));

        expect(target('output').textContent).toBe('the secret text');
        // Key never leaves the browser: only a bare POST to the consume URL.
        expect(fetchMock).toHaveBeenCalledWith('/s/share-1/consume', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain(key);
        expect(window.location.search).toBe('');
        expect(loadShareKey('share-1')).toEqual({ mode: 'key', material: key });
        expect(target<HTMLInputElement>('key').value).toBe(key);
        expect(visible('keyPanel')).toBe(false);
        expect(visible('keyFromLinkHint')).toBe(true);
        expect(visible('revealButton')).toBe(false);
        expect(visible('passwordPanel')).toBe(false);
        expect(visible('downloadLink')).toBe(false);
    });

    it('auto-reveals with the key from a #decrypt_key= fragment link and clears the fragment', async () => {
        const { ciphertext, key } = await encryptText('fragment secret');
        window.history.replaceState(null, '', `/s/share-1#decrypt_key=${encodeURIComponent(key)}`);
        respondWith({ status: 'ok', ciphertext });

        await mount();
        await vi.waitFor(() => expect(visible('output')).toBe(true));

        expect(target('output').textContent).toBe('fragment secret');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain(key);
        expect(window.location.hash).toBe('');
        expect(window.location.search).toBe('');
    });

    it('uses a key stored in sessionStorage for the share', async () => {
        const { ciphertext, key } = await encryptText('stored');
        storeShareKey('share-1', 'key', key);
        respondWith({ status: 'ok', ciphertext });

        await mount();
        await vi.waitFor(() => expect(target('output').textContent).toBe('stored'));
    });

    it('shows the key panel and waits for manual input when no key is embedded', async () => {
        const { ciphertext, key } = await encryptText('manual');

        await mount();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(visible('keyPanel')).toBe(true);
        expect(visible('keyFromLinkHint')).toBe(false);

        target('revealButton').click();
        await vi.waitFor(() => expect(visible('error')).toBe(true));
        expect(target('error').textContent).toBe('Enter the key.');
        expect(fetchMock).not.toHaveBeenCalled();

        target<HTMLInputElement>('key').value = `  ${key}  `;
        respondWith({ status: 'ok', ciphertext });
        target('revealButton').click();
        await vi.waitFor(() => expect(target('output').textContent).toBe('manual'));
        expect(visible('error')).toBe(false);
    });

    it('does not auto-reveal when disabled', async () => {
        storeShareKey('share-1', 'key', 'REDACTED-TEST-KEY');

        await mount({ autoReveal: false });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(visible('keyPanel')).toBe(false);
    });

    it('shows the server status when the share cannot be consumed', async () => {
        storeShareKey('share-1', 'key', 'REDACTED-TEST-KEY');
        respondWith({ status: 'not_found' });

        await mount();
        await vi.waitFor(() => expect(visible('error')).toBe(true));

        expect(target('error').textContent).toBe('Share not found.');
        expect(visible('output')).toBe(false);
        expect(visible('revealButton')).toBe(true);
    });

    it('shows the raw status when ok but no ciphertext and no translation', async () => {
        storeShareKey('share-1', 'key', 'REDACTED-TEST-KEY');
        respondWith({ status: 'ok' });

        await mount();
        await vi.waitFor(() => expect(target('error').textContent).toBe('ok'));
    });

    it('reports INVALID_KEY for a wrong key or network failure', async () => {
        const { ciphertext } = await encryptText('x');
        const other = await encryptText('y');
        storeShareKey('share-1', 'key', other.key);
        respondWith({ status: 'ok', ciphertext });

        await mount();
        await vi.waitFor(() => expect(target('error').textContent).toBe('Wrong key.'));
        expect(target('output').textContent).toBe('');

        fetchMock.mockRejectedValueOnce(new Error('offline'));
        target('revealButton').click();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await vi.waitFor(() => expect(visible('error')).toBe(true));
        expect(target('error').textContent).toBe('Wrong key.');
    });

    it('offers decrypted files as a download link', async () => {
        const createObjectURL = vi.fn(() => 'blob:test-url');
        const originalCreateObjectURL = URL.createObjectURL;
        URL.createObjectURL = createObjectURL;
        onTestFinished(() => {
            URL.createObjectURL = originalCreateObjectURL;
        });
        const file = new File(['file body'], 'note.txt', { type: 'text/plain' });
        const encrypted = await encryptPayload(await readFileAsPayload(file), { generateKey: true });
        storeShareKey('share-1', 'key', encrypted.decryptionKey!);
        respondWith({ status: 'ok', ciphertext: encrypted.ciphertext });

        await mount();
        await vi.waitFor(() => expect(visible('downloadLink')).toBe(true));

        const link = target<HTMLAnchorElement>('downloadLink');
        expect(link.getAttribute('href')).toBe('blob:test-url');
        expect(link.download).toBe('note.txt');
        expect(link.textContent).toBe('note.txt');
        expect(visible('output')).toBe(false);
        const blob = (createObjectURL.mock.calls[0] as unknown as [Blob])[0];
        await expect(blob.text()).resolves.toBe('file body');
    });

    describe('password mode', () => {
        it('prefills the password from the link and hides the password panel', async () => {
            vi.mocked(decryptPayload).mockResolvedValueOnce({ kind: 'text', text: 'pw secret' });
            window.history.replaceState(null, '', '/s/share-1?decrypt_key=REDACTED-TEST-PASSWORD');
            respondWith({ status: 'ok', ciphertext: 'envelope' });

            await mount({ mode: 'password' });
            await vi.waitFor(() => expect(target('output').textContent).toBe('pw secret'));

            expect(decryptPayload).toHaveBeenLastCalledWith('envelope', 'REDACTED-TEST-PASSWORD');
            expect(target<HTMLInputElement>('password').value).toBe('REDACTED-TEST-PASSWORD');
            expect(visible('passwordPanel')).toBe(false);
            expect(visible('passwordFromLinkHint')).toBe(true);
            expect(loadShareKey('share-1')).toEqual({ mode: 'password', material: 'REDACTED-TEST-PASSWORD' });
            expect(window.location.search).toBe('');
        });

        it('asks for the password when none is provided', async () => {
            await mount({ mode: 'password' });

            expect(visible('passwordPanel')).toBe(true);
            target('revealButton').click();
            await vi.waitFor(() => expect(target('error').textContent).toBe('Enter the password.'));

            vi.mocked(decryptPayload).mockResolvedValueOnce({ kind: 'text', text: 'typed' });
            target<HTMLInputElement>('password').value = ' typed-pass ';
            respondWith({ status: 'ok', ciphertext: 'envelope' });
            target('revealButton').click();
            await vi.waitFor(() => expect(target('output').textContent).toBe('typed'));
            expect(decryptPayload).toHaveBeenLastCalledWith('envelope', 'typed-pass');
        });
    });

    it('does not persist link material when no share id is configured', async () => {
        window.history.replaceState(null, '', '/s/x?decrypt_key=REDACTED-TEST-KEY');
        respondWith({ status: 'gone' });

        await mount({ shareId: '' });
        await vi.waitFor(() => expect(target('error').textContent).toBe('gone'));

        expect(sessionStorage.length).toBe(0);
        expect(window.location.search).toBe('');
    });

    it('works without optional targets', async () => {
        const { ciphertext, key } = await encryptText('bare');
        storeShareKey('share-1', 'key', key);
        respondWith({ status: 'ok', ciphertext });
        const controller = await mount({ optionalTargets: false });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        const file = new File(['f'], 'f.bin');
        const encrypted = await encryptPayload(await readFileAsPayload(file), { generateKey: true });
        storeShareKey('share-1', 'key', encrypted.decryptionKey!);
        respondWith({ status: 'ok', ciphertext: encrypted.ciphertext });
        await expect(controller.reveal()).resolves.toBeUndefined();

        respondWith({ status: 'nope' });
        await expect(controller.reveal()).resolves.toBeUndefined();

        sessionStorage.clear();
        target<HTMLInputElement>('key').value = '';
        await expect(controller.reveal()).resolves.toBeUndefined();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });
});
