// @vitest-environment happy-dom
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import YopassCreateController from './yopass-create-controller';
import { decryptPayload, encryptPayload } from './yopass-crypto';
import { PENDING_KEY_STORAGE } from './yopass-share-keys';

// Real crypto by default; individual tests can override encryptPayload once.
vi.mock('./yopass-crypto', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./yopass-crypto')>();

    return { ...actual, encryptPayload: vi.fn(actual.encryptPayload) };
});

let app: Application | null = null;
let submitSpy: ReturnType<typeof vi.fn>;

const ERRORS = JSON.stringify({
    SECRET_REQUIRED: 'Please enter a secret.',
    PASSWORD_REQUIRED: 'Please enter a password.',
    SECRET_TOO_LARGE: 'Secret is too large.',
});

type Options = {
    fileEnabled?: boolean;
    maxSecretChars?: number;
    counterFormat?: string;
};

function markup(options: Options = {}): string {
    return `
        <div data-controller="create"
             data-create-file-enabled-value="${options.fileEnabled ? 'true' : 'false'}"
             data-create-max-file-bytes-value="1024"
             data-create-max-secret-chars-value="${options.maxSecretChars ?? 10}"
             data-create-errors-value='${ERRORS}'>
            <form data-create-target="form" data-action="submit->create#submit">
                <input type="hidden" data-create-target="ciphertext">
                <input type="hidden" data-create-target="payloadKind">
                <button type="button" id="tab-text" data-yopass-tab="text" data-action="create#switchTab">Text</button>
                <button type="button" id="tab-file" data-yopass-tab="file" data-action="create#switchTab">File</button>
                <div data-create-target="textPanel">
                    <textarea data-create-target="secret"></textarea>
                    <span data-create-target="secretCounter" class="text-secondary"></span>
                    <span data-create-target="secretLimitHelp" class="d-none">limit</span>
                </div>
                <div data-create-target="filePanel" class="d-none">
                    <input type="file" data-create-target="fileInput">
                </div>
                <input type="radio" name="enc" data-create-target="encryptionAuto" data-action="create#syncKeyMode" checked>
                <input type="radio" name="enc" data-create-target="encryptionPassword" data-action="create#syncKeyMode">
                <div data-create-target="customPasswordPanel" class="d-none">
                    <input type="password" data-create-target="customPassword">
                </div>
                <div data-create-target="error" class="d-none"></div>
                <button type="submit" data-create-target="submit">Create</button>
            </form>
        </div>`;
}

async function mount(options: Options = {}, html?: string): Promise<void> {
    document.body.innerHTML = html ?? markup(options);

    if (options.counterFormat !== undefined) {
        el('[data-controller="create"]').setAttribute('data-create-counter-format-value', options.counterFormat);
    }

    app = new Application(document.documentElement);
    await app.start();
    app.register('create', YopassCreateController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function el<T extends HTMLElement = HTMLElement>(selector: string): T {
    return document.querySelector(selector) as T;
}

function target<T extends HTMLElement = HTMLElement>(name: string): T {
    return el<T>(`[data-create-target="${name}"]`);
}

function typeSecret(value: string): void {
    const secret = target<HTMLTextAreaElement>('secret');
    secret.value = value;
    secret.dispatchEvent(new Event('input'));
}

function submitForm(): void {
    target<HTMLFormElement>('form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
}

function errorText(): string | null {
    const error = target('error');

    return error.classList.contains('d-none') ? null : error.textContent;
}

describe('yopass-create-controller', () => {
    beforeEach(() => {
        sessionStorage.clear();
        submitSpy = vi.fn();
        HTMLFormElement.prototype.submit = submitSpy as unknown as () => void;
    });

    afterEach(() => {
        app?.stop();
        app = null;
        document.body.innerHTML = '';
    });

    describe('character counter', () => {
        it('shows the counter on connect and updates on input', async () => {
            await mount();

            expect(target('secretCounter').textContent).toBe('0 / 10');

            typeSecret('  abc  ');
            expect(target('secretCounter').textContent).toBe('3 / 10');
            expect(target('secretCounter').classList.contains('text-secondary')).toBe(true);
            expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
        });

        it('flags the limit and disables submit when over it', async () => {
            await mount();

            typeSecret('a'.repeat(10));
            expect(target('secretCounter').classList.contains('text-danger')).toBe(true);
            expect(target('secretLimitHelp').classList.contains('d-none')).toBe(false);
            expect(target<HTMLButtonElement>('submit').disabled).toBe(false);

            typeSecret('a'.repeat(11));
            expect(target<HTMLButtonElement>('submit').disabled).toBe(true);

            const secret = target<HTMLTextAreaElement>('secret');
            secret.value = 'ok';
            secret.dispatchEvent(new Event('paste'));
            expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
            expect(target('secretLimitHelp').classList.contains('d-none')).toBe(true);
        });

        it('counts code points, not UTF-16 units', async () => {
            await mount();

            typeSecret('\u{1F511}\u{1F511}');
            expect(target('secretCounter').textContent).toBe('2 / 10');
        });

        it('supports placeholder formats including HTML-entity encoded ones', async () => {
            await mount({ counterFormat: '&#64;&#64;COUNT&#64;&#64; of &#x40;&#x40;LIMIT&#x40;&#x40;' });
            typeSecret('abcd');
            expect(target('secretCounter').textContent).toBe('4 of 10');

            app?.stop();
            await mount({ counterFormat: '__COUNT__/__LIMIT__ &amp; more' });
            expect(target('secretCounter').textContent).toBe('0/10 & more');

            app?.stop();
            await mount({ counterFormat: '' });
            expect(target('secretCounter').textContent).toBe('0 / 10');
        });

        it('stops listening after disconnect', async () => {
            await mount();
            const secret = target<HTMLTextAreaElement>('secret');
            const counter = target('secretCounter');

            el('[data-controller="create"]').remove();
            await new Promise((resolve) => setTimeout(resolve, 0));
            secret.value = 'abc';
            secret.dispatchEvent(new Event('input'));

            expect(counter.textContent).toBe('0 / 10');
        });
    });

    describe('tabs and key mode', () => {
        it('ignores the file tab when files are disabled', async () => {
            await mount();

            el<HTMLButtonElement>('#tab-file').click();

            expect(target('textPanel').classList.contains('d-none')).toBe(false);
            expect(target('filePanel').classList.contains('d-none')).toBe(true);
        });

        it('switches between text and file panels when files are enabled', async () => {
            await mount({ fileEnabled: true });

            el<HTMLButtonElement>('#tab-file').click();
            expect(target('textPanel').classList.contains('d-none')).toBe(true);
            expect(target('filePanel').classList.contains('d-none')).toBe(false);

            // Counter is not re-synced while on the file tab.
            typeSecret('abc');
            expect(target('secretCounter').textContent).toBe('0 / 10');

            el<HTMLButtonElement>('#tab-text').click();
            expect(target('textPanel').classList.contains('d-none')).toBe(false);
            expect(target('filePanel').classList.contains('d-none')).toBe(true);
            expect(target('secretCounter').textContent).toBe('3 / 10');
        });

        it('defaults to the text tab when the button has no tab attribute', async () => {
            await mount({ fileEnabled: true });
            el<HTMLButtonElement>('#tab-file').click();

            const bare = document.createElement('button');
            bare.setAttribute('data-action', 'create#switchTab');
            target('form').appendChild(bare);
            await new Promise((resolve) => setTimeout(resolve, 0));
            bare.click();

            expect(target('textPanel').classList.contains('d-none')).toBe(false);
        });

        it('shows the custom password panel only in password mode', async () => {
            await mount();

            target<HTMLInputElement>('encryptionPassword').click();
            expect(target('customPasswordPanel').classList.contains('d-none')).toBe(false);

            target<HTMLInputElement>('encryptionAuto').click();
            expect(target('customPasswordPanel').classList.contains('d-none')).toBe(true);
        });
    });

    describe('submit', () => {
        it('encrypts text with a generated key and keeps the key client-side', async () => {
            await mount({ maxSecretChars: 100 });
            typeSecret('  top secret note  ');

            submitForm();
            await vi.waitFor(() => expect(submitSpy).toHaveBeenCalledTimes(1));

            const ciphertext = target<HTMLInputElement>('ciphertext').value;
            expect(target<HTMLInputElement>('payloadKind').value).toBe('text');
            expect(ciphertext).not.toContain('top secret note');

            const pending = JSON.parse(sessionStorage.getItem(PENDING_KEY_STORAGE)!);
            expect(pending.mode).toBe('key');
            expect(ciphertext).not.toContain(pending.material);
            await expect(decryptPayload(ciphertext, pending.material)).resolves.toEqual({ kind: 'text', text: 'top secret note' });
            expect(errorText()).toBeNull();
        });

        it('encrypts with the custom password in password mode', async () => {
            // The password path (Argon2id) is exercised through a stub here: this test covers the controller
            // wiring (options passed, key material persisted), not libsodium itself.
            vi.mocked(encryptPayload).mockResolvedValueOnce({ ciphertext: '{"v":1,"mode":"password","salt":"s","box":"b"}' });
            await mount({ maxSecretChars: 100 });
            typeSecret('password protected');
            target<HTMLInputElement>('encryptionPassword').click();
            const password = `pw-${crypto.randomUUID()}`; // ggignore: random per-run test value, not a credential
            target<HTMLInputElement>('customPassword').value = password;

            submitForm();
            await vi.waitFor(() => expect(submitSpy).toHaveBeenCalledTimes(1));

            expect(encryptPayload).toHaveBeenLastCalledWith(
                { kind: 'text', text: 'password protected' },
                { generateKey: false, password },
            );
            expect(target<HTMLInputElement>('ciphertext').value).toBe('{"v":1,"mode":"password","salt":"s","box":"b"}');
            expect(JSON.parse(sessionStorage.getItem(PENDING_KEY_STORAGE)!)).toEqual({ mode: 'password', material: password });
        });

        it('refuses to submit when encryption yields no key material', async () => {
            vi.mocked(encryptPayload).mockResolvedValueOnce({ ciphertext: 'c' });
            await mount();
            typeSecret('abc');

            submitForm();
            await vi.waitFor(() => expect(errorText()).toBe('CREATE_FAILED'));

            expect(submitSpy).not.toHaveBeenCalled();
            expect(target<HTMLInputElement>('ciphertext').value).toBe('');
            expect(sessionStorage.getItem(PENDING_KEY_STORAGE)).toBeNull();
        });

        it('reports UNKNOWN for non-Error failures', async () => {
            vi.mocked(encryptPayload).mockRejectedValueOnce('boom');
            await mount();
            typeSecret('abc');

            submitForm();
            await vi.waitFor(() => expect(errorText()).toBe('UNKNOWN'));
            expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
        });

        it('requires a password in password mode', async () => {
            await mount();
            typeSecret('abc');
            target<HTMLInputElement>('encryptionPassword').click();

            submitForm();
            await vi.waitFor(() => expect(errorText()).toBe('Please enter a password.'));

            expect(submitSpy).not.toHaveBeenCalled();
            expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
            expect(sessionStorage.getItem(PENDING_KEY_STORAGE)).toBeNull();
        });

        it('requires a non-empty secret', async () => {
            await mount();
            typeSecret('   ');

            submitForm();
            await vi.waitFor(() => expect(errorText()).toBe('Please enter a secret.'));
            expect(submitSpy).not.toHaveBeenCalled();
        });

        it('rejects secrets over the limit before encrypting', async () => {
            await mount();
            typeSecret('a'.repeat(11));

            submitForm();
            await vi.waitFor(() => expect(errorText()).toBe('Secret is too large.'));
            expect(submitSpy).not.toHaveBeenCalled();
            expect(target<HTMLInputElement>('ciphertext').value).toBe('');
        });

        it('falls back to the raw error code when no translation exists', async () => {
            await mount({ fileEnabled: true });
            el<HTMLButtonElement>('#tab-file').click();

            submitForm();
            await vi.waitFor(() => expect(errorText()).toBe('FILE_REQUIRED'));
            expect(target<HTMLButtonElement>('submit').disabled).toBe(false);
        });

        it('encrypts a selected file on the file tab', async () => {
            await mount({ fileEnabled: true });
            el<HTMLButtonElement>('#tab-file').click();
            const file = new File(['file body'], 'note.txt', { type: 'text/plain' });
            Object.defineProperty(target('fileInput'), 'files', { value: [file] });

            submitForm();
            await vi.waitFor(() => expect(submitSpy).toHaveBeenCalledTimes(1));

            expect(target<HTMLInputElement>('payloadKind').value).toBe('file');
            const pending = JSON.parse(sessionStorage.getItem(PENDING_KEY_STORAGE)!);
            const payload = await decryptPayload(target<HTMLInputElement>('ciphertext').value, pending.material);
            expect(payload).toMatchObject({ kind: 'file', filename: 'note.txt', mime: 'text/plain' });
        });

        it('reports files larger than the configured maximum', async () => {
            await mount({ fileEnabled: true });
            el<HTMLButtonElement>('#tab-file').click();
            const file = new File([new Uint8Array(2048)], 'big.bin');
            Object.defineProperty(target('fileInput'), 'files', { value: [file] });

            submitForm();
            await vi.waitFor(() => expect(errorText()).toBe('FILE_TOO_LARGE'));
            expect(submitSpy).not.toHaveBeenCalled();
        });

        it('works without the optional error target', async () => {
            const html = markup().replace('<div data-create-target="error" class="d-none"></div>', '');
            await mount({}, html);
            typeSecret('   ');

            submitForm();
            await vi.waitFor(() => expect(target<HTMLButtonElement>('submit').disabled).toBe(false));
            expect(submitSpy).not.toHaveBeenCalled();
        });
    });
});
