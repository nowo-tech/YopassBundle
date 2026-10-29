// @vitest-environment happy-dom
import { Application } from '@hotwired/stimulus';
import { afterEach, describe, expect, it, vi } from 'vitest';
import YopassConfirmController from './yopass-confirm-controller';

let app: Application | null = null;

async function mount(message: string | null): Promise<HTMLButtonElement> {
    const attr = message === null ? '' : ` data-confirm-message-value="${message}"`;
    document.body.innerHTML = `
        <form data-controller="confirm"${attr}>
            <button type="submit" data-action="click->confirm#confirm">Delete</button>
        </form>`;
    app = new Application(document.documentElement);
    await app.start();
    app.register('confirm', YopassConfirmController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    return document.querySelector('button')!;
}

function stubConfirm(answer: boolean): ReturnType<typeof vi.fn> {
    const fn = vi.fn(() => answer);
    vi.stubGlobal('confirm', fn);
    window.confirm = fn;

    return fn;
}

function click(button: HTMLButtonElement): MouseEvent {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    // Avoid happy-dom performing the actual form submission.
    button.form!.addEventListener('submit', (e) => e.preventDefault(), { once: true });
    button.dispatchEvent(event);

    return event;
}

describe('yopass-confirm-controller', () => {
    afterEach(() => {
        app?.stop();
        app = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('does not ask anything when no message is configured', async () => {
        const confirmSpy = stubConfirm(true);
        const button = await mount(null);

        const event = click(button);

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('cancels the submit when the user declines', async () => {
        const confirmSpy = stubConfirm(false);
        const button = await mount('Delete this share?');

        const event = click(button);

        expect(confirmSpy).toHaveBeenCalledWith('Delete this share?');
        expect(event.defaultPrevented).toBe(true);
    });

    it('lets the submit through when the user accepts', async () => {
        stubConfirm(true);
        const button = await mount('Delete this share?');

        const event = click(button);

        expect(event.defaultPrevented).toBe(false);
    });
});
