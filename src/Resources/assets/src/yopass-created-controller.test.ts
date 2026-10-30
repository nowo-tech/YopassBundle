// @vitest-environment happy-dom
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import YopassCreatedController from './yopass-created-controller';
import { PENDING_KEY_STORAGE, loadShareKey, storePendingShareKey } from './yopass-share-keys';

const MATERIAL = 'REDACTED-TEST-KEY';

let app: Application | null = null;

function markup(extraAttrs = '', withOptionalTargets = true): string {
    const optional = withOptionalTargets
        ? `<div data-created-target="rememberNotice" hidden>remember</div>
           <div data-created-target="error" hidden></div>`
        : '';

    return `
        <div data-controller="created"
             data-created-share-id-value="share-1"
             data-created-public-path-value="/s/share-1"
             ${extraAttrs}>
            ${optional}
            <div data-created-target="content">
                <form id="links-form" data-action="submit->created#preventSubmit"></form>
                <input data-created-target="resultOneClickLink">
                <button data-action="created#copyOneClickLink">c1</button>
                <input data-created-target="resultShortLink">
                <button data-action="created#copyShortLink">c2</button>
                <input data-created-target="resultKey">
                <button data-action="created#copyKey">c3</button>
            </div>
        </div>`;
}

async function mount(html: string): Promise<void> {
    document.body.innerHTML = html;
    app = new Application(document.documentElement);
    await app.start();
    app.register('created', YopassCreatedController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function input(target: string): HTMLInputElement {
    return document.querySelector(`[data-created-target="${target}"]`) as HTMLInputElement;
}

describe('yopass-created-controller', () => {
    let writeText: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        sessionStorage.clear();
        window.history.replaceState(null, '', '/created');
        writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    });

    afterEach(() => {
        app?.stop();
        app = null;
        document.body.innerHTML = '';
    });

    it('renders a fragment one-click link when embedding is allowed and removes the key from the address bar', async () => {
        window.history.replaceState(null, '', `/created?decrypt_key=${MATERIAL}&x=1`);

        await mount(markup('data-created-allow-embed-in-url-value="true"'));

        const origin = window.location.origin;
        expect(input('resultShortLink').value).toBe(`${origin}/s/share-1`);
        expect(input('resultOneClickLink').value).toBe(`${origin}/s/share-1#decrypt_key=${MATERIAL}`);
        expect(input('resultOneClickLink').value).not.toContain('?');
        expect(input('resultKey').value).toBe(MATERIAL);
        expect(window.location.search).toBe('?x=1');
        expect(document.querySelector<HTMLElement>('[data-created-target="rememberNotice"]')!.hidden).toBe(false);
    });

    it('never builds a key-bearing link when embedding is not allowed (default)', async () => {
        storePendingShareKey('key', MATERIAL);

        await mount(markup());

        expect(input('resultOneClickLink').value).toBe('');
        expect(input('resultShortLink').value).toBe(`${window.location.origin}/s/share-1`);
        expect(input('resultKey').value).toBe(MATERIAL);
    });

    it('renders only the short link and the key when the one-click field is absent', async () => {
        storePendingShareKey('key', MATERIAL);
        document.body.innerHTML = '';

        await mount(markup('data-created-allow-embed-in-url-value="true"').replace(
            /<input data-created-target="resultOneClickLink">\s*<button data-action="created#copyOneClickLink">c1<\/button>/,
            '<button data-action="created#copyOneClickLink">c1</button>',
        ));

        expect(document.querySelector('[data-created-target="resultOneClickLink"]')).toBeNull();
        expect(input('resultKey').value).toBe(MATERIAL);
        (document.querySelector('button') as HTMLButtonElement).click();
        expect(writeText).not.toHaveBeenCalled();
    });

    it('binds pending sessionStorage material to the share id', async () => {
        storePendingShareKey('password', MATERIAL);

        await mount(markup('data-created-show-remember-notice-value="false"'));

        expect(input('resultKey').value).toBe(MATERIAL);
        expect(sessionStorage.getItem(PENDING_KEY_STORAGE)).toBeNull();
        expect(loadShareKey('share-1')).toEqual({ mode: 'password', material: MATERIAL });
        expect(document.querySelector<HTMLElement>('[data-created-target="rememberNotice"]')!.hidden).toBe(true);
    });

    it('never submits the read-only links form', async () => {
        await mount(markup());

        const event = new Event('submit', { cancelable: true, bubbles: true });
        document.getElementById('links-form')!.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it('copies each value to the clipboard', async () => {
        storePendingShareKey('key', MATERIAL);
        await mount(markup('data-created-allow-embed-in-url-value="true"'));

        for (const button of Array.from(document.querySelectorAll('button'))) {
            button.click();
        }

        expect(writeText.mock.calls.map((c) => c[0])).toEqual([
            input('resultOneClickLink').value,
            input('resultShortLink').value,
            MATERIAL,
        ]);
    });

    it('does not throw when the clipboard API is unavailable', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        storePendingShareKey('key', MATERIAL);
        await mount(markup());

        expect(() => (document.querySelector('button') as HTMLButtonElement).click()).not.toThrow();
    });

    it('hides the content and shows the configured message when no key is available', async () => {
        await mount(markup('data-created-key-unavailable-message-value="No key here"'));

        const error = document.querySelector<HTMLElement>('[data-created-target="error"]')!;
        expect(error.textContent).toBe('No key here');
        expect(error.hidden).toBe(false);
        expect(document.querySelector<HTMLElement>('[data-created-target="content"]')!.hidden).toBe(true);
        expect(input('resultKey').value).toBe('');
    });

    it('tolerates missing optional targets', async () => {
        await mount(markup('', false));
        expect(input('resultKey').value).toBe('');

        app?.stop();
        storePendingShareKey('key', MATERIAL);
        await mount(markup('', false));
        expect(input('resultKey').value).toBe(MATERIAL);
    });
});
