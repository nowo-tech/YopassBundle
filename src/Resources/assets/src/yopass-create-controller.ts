import { Controller } from '@hotwired/stimulus';
import { setHidden, isHidden, syncHidden } from './visibility';
import {
    countSecretCharactersForSubmit,
    encryptPayload,
    isSecretWithinCharacterLimit,
    readFileAsPayload,
    type YopassPayload,
} from './yopass-crypto';
import { PENDING_KEY_STORAGE, storePendingShareKey } from './yopass-share-keys';
import { reloadPage } from './yopass-navigation';
import { clearShareLinks, copyToClipboard, renderShareLinks, type ShareLinkTargets } from './yopass-share-links';
import type { ShareUrlKeyMode } from './yopass-share-url';

/** Cancelable event dispatched (bubbling) when the create dialog closes after a successful create; detail `{id}`. */
export const CREATED_EVENT = 'nowo-yopass-create:created';

type CreatedResponse = { id: string; public_path: string };

/**
 * Encrypts share content in the browser, then either submits the Symfony form (page mode: POST + redirect to the
 * created page) or, in dialog mode (`async`), posts it with `fetch` (same origin, same CSRF token) and renders the
 * links in the dialog. In dialog mode the key / password only lives in the result fields of the dialog (never
 * sessionStorage, never sent to the server) and every close of the dialog wipes the form, the result fields and the key.
 */
export default class extends Controller {
    static targets = [
        'form',
        'ciphertext',
        'payloadKind',
        'textPanel',
        'filePanel',
        'secret',
        'secretCounter',
        'secretLimitHelp',
        'fileInput',
        'encryptionAuto',
        'encryptionPassword',
        'customPasswordPanel',
        'customPassword',
        'error',
        'submit',
        'result',
        'rememberNotice',
        'resultOneClickLink',
        'resultShortLink',
        'resultKey',
        'resultKeyRow',
        'passwordNote',
    ];

    static values = {
        fileEnabled: { type: Boolean, default: false },
        maxFileBytes: { type: Number, default: 0 },
        maxSecretChars: { type: Number, default: 512 * 1024 },
        counterFormat: { type: String, default: '%count% / %limit%' },
        counterClass: { type: String, default: '' },
        counterLimitClass: { type: String, default: '' },
        tabClass: { type: String, default: '' },
        tabActiveClass: { type: String, default: '' },
        errors: { type: Object, default: {} },
        async: { type: Boolean, default: false },
        allowEmbedInUrl: { type: Boolean, default: false },
        showRememberNotice: { type: Boolean, default: true },
    };

    declare readonly formTarget: HTMLFormElement;
    declare readonly ciphertextTarget: HTMLInputElement;
    declare readonly payloadKindTarget: HTMLInputElement;
    declare readonly textPanelTarget: HTMLElement;
    declare readonly filePanelTarget: HTMLElement;
    declare readonly secretTarget: HTMLTextAreaElement;
    declare readonly secretCounterTarget: HTMLElement;
    declare readonly secretLimitHelpTarget: HTMLElement;
    declare readonly fileInputTarget: HTMLInputElement;
    declare readonly encryptionAutoTarget: HTMLInputElement;
    declare readonly encryptionPasswordTarget: HTMLInputElement;
    declare readonly customPasswordPanelTarget: HTMLElement;
    declare readonly customPasswordTarget: HTMLInputElement;
    declare readonly errorTarget: HTMLElement;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly fileEnabledValue: boolean;
    declare readonly maxFileBytesValue: number;
    declare readonly maxSecretCharsValue: number;
    declare readonly counterFormatValue: string;
    declare readonly counterClassValue: string;
    declare readonly counterLimitClassValue: string;
    declare readonly tabClassValue: string;
    declare readonly tabActiveClassValue: string;
    declare readonly errorsValue: Record<string, string>;
    declare readonly hasTextPanelTarget: boolean;
    declare readonly hasFilePanelTarget: boolean;
    declare readonly hasSecretTarget: boolean;
    declare readonly hasSecretCounterTarget: boolean;
    declare readonly hasSecretLimitHelpTarget: boolean;
    declare readonly hasCustomPasswordPanelTarget: boolean;
    declare readonly hasErrorTarget: boolean;
    declare readonly hasSubmitTarget: boolean;
    declare readonly hasEncryptionPasswordTarget: boolean;
    declare readonly hasFileInputTarget: boolean;
    declare readonly asyncValue: boolean;
    declare readonly allowEmbedInUrlValue: boolean;
    declare readonly showRememberNoticeValue: boolean;
    declare readonly resultTarget: HTMLElement;
    declare readonly rememberNoticeTarget: HTMLElement;
    declare readonly resultOneClickLinkTarget: HTMLInputElement;
    declare readonly resultShortLinkTarget: HTMLInputElement;
    declare readonly resultKeyTarget: HTMLInputElement;
    declare readonly resultKeyRowTarget: HTMLElement;
    declare readonly passwordNoteTarget: HTMLElement;
    declare readonly hasRememberNoticeTarget: boolean;
    declare readonly hasResultOneClickLinkTarget: boolean;
    declare readonly hasCustomPasswordTarget: boolean;

    /** Id of the share created while the dialog was open (triggers the list refresh on close). */
    private createdId: string | null = null;

    private dialog: HTMLDialogElement | null = null;

    private readonly onDialogClose = (): void => {
        this.wipe();
    };

    private readonly onSecretInput = (): void => {
        this.syncSecretCounter();
    };

    connect(): void {
        syncHidden(this.element as HTMLElement, this.identifier);
        this.syncKeyMode();
        this.secretTarget.addEventListener('input', this.onSecretInput);
        this.secretTarget.addEventListener('paste', this.onSecretInput);
        this.syncSecretCounter();

        if (this.asyncValue) {
            this.dialog = this.element.closest('dialog');
            // Native close (Done, close button, Escape, backdrop) and the UiKit overlay event, when used.
            this.dialog?.addEventListener('close', this.onDialogClose);
            this.dialog?.addEventListener('nowo:overlay-close', this.onDialogClose);
        }
    }

    disconnect(): void {
        this.secretTarget.removeEventListener('input', this.onSecretInput);
        this.secretTarget.removeEventListener('paste', this.onSecretInput);

        if (this.dialog) {
            this.dialog.removeEventListener('close', this.onDialogClose);
            this.dialog.removeEventListener('nowo:overlay-close', this.onDialogClose);
            this.dialog = null;
        }

        if (this.asyncValue) {
            this.clearSecrets();
        }
    }

    switchTab(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        const tab = button.dataset.yopassTab ?? 'text';

        if (tab === 'file' && !this.fileEnabledValue) {
            return;
        }

        this.element.querySelectorAll<HTMLElement>('[data-yopass-tab]').forEach((tabButton) => {
            const active = (tabButton.dataset.yopassTab ?? 'text') === tab;
            tabButton.setAttribute('aria-selected', active ? 'true' : 'false');
            const className = active ? this.tabActiveClassValue : this.tabClassValue;
            if (className !== '') {
                tabButton.className = className;
            }
        });

        if (this.hasTextPanelTarget) {
            setHidden(this.textPanelTarget, tab !== 'text');
        }

        if (this.hasFilePanelTarget) {
            setHidden(this.filePanelTarget, tab !== 'file');
        }

        this.syncSecretCounter();
    }

    syncKeyMode(): void {
        const passwordMode = this.isPasswordMode();

        if (this.hasCustomPasswordPanelTarget) {
            setHidden(this.customPasswordPanelTarget, !passwordMode);
        }
    }

    syncSecretCounter(): void {
        if (!this.hasSecretCounterTarget || !this.hasSecretTarget || !this.isTextTabActive()) {
            return;
        }

        const count = countSecretCharactersForSubmit(this.secretTarget.value);
        const atLimit = count >= this.maxSecretCharsValue;
        const overLimit = count > this.maxSecretCharsValue;

        this.secretCounterTarget.textContent = this.formatCounterLabel(count);
        // Theme slot classes (muted / form_error) from the template; data-limit-reached is the styling hook.
        const counterClass = atLimit ? this.counterLimitClassValue : this.counterClassValue;
        if (counterClass !== '') {
            this.secretCounterTarget.className = counterClass;
        }
        this.secretCounterTarget.toggleAttribute('data-limit-reached', atLimit);

        if (this.hasSecretLimitHelpTarget) {
            setHidden(this.secretLimitHelpTarget, !atLimit);
        }

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = overLimit;
        }
    }

    async submit(event: Event): Promise<void> {
        event.preventDefault();
        this.hideError();

        if (this.isTextTabActive() && !this.isSecretWithinLimit()) {
            this.showError('SECRET_TOO_LARGE');

            return;
        }

        this.submitTarget.disabled = true;

        try {
            const payload = await this.buildPayload();
            const passwordMode = this.isPasswordMode();
            const password = passwordMode ? this.customPasswordTarget.value : undefined;

            if (passwordMode && !password) {
                throw new Error('PASSWORD_REQUIRED');
            }

            const encrypted = await encryptPayload(payload, {
                generateKey: !passwordMode,
                password,
            });

            const keyMaterial = passwordMode ? password! : (encrypted.decryptionKey ?? '');

            if (keyMaterial === '') {
                throw new Error('CREATE_FAILED');
            }

            this.ciphertextTarget.value = encrypted.ciphertext;
            this.payloadKindTarget.value = payload.kind;

            if (this.asyncValue) {
                await this.submitAsync(keyMaterial, passwordMode ? 'password' : 'key');

                return;
            }

            storePendingShareKey(passwordMode ? 'password' : 'key', keyMaterial);

            this.formTarget.submit();
        } catch (error) {
            // Only the bundle's own error codes are shown; raw library messages map to CREATE_FAILED.
            const message = error instanceof Error
                ? (/^[A-Z][A-Z_]*$/.test(error.message) ? error.message : 'CREATE_FAILED')
                : 'UNKNOWN';
            this.showError(message);

            if (this.isTextTabActive() && !this.isSecretWithinLimit()) {
                this.submitTarget.disabled = true;
            } else {
                this.submitTarget.disabled = false;
            }
        }
    }

    /** Done button of the dialog result: closes the dialog (the native close event wipes everything). */
    done(): void {
        if (this.dialog?.open) {
            this.dialog.close();
        }
    }

    /** The result fields form is read-only: never submit it (Enter in a field). */
    preventSubmit(event: Event): void {
        event.preventDefault();
    }

    copyOneClickLink(): void {
        if (this.hasResultOneClickLinkTarget) {
            copyToClipboard(this.resultOneClickLinkTarget.value);
        }
    }

    copyShortLink(): void {
        copyToClipboard(this.resultShortLinkTarget.value);
    }

    copyKey(): void {
        copyToClipboard(this.resultKeyTarget.value);
    }

    /**
     * Dialog mode: posts the form (ciphertext + CSRF token only; the browser-only controls have no name) with fetch and
     * renders the links. The response never carries plaintext or key material; the key stays in this controller.
     */
    private async submitAsync(keyMaterial: string, mode: ShareUrlKeyMode): Promise<void> {
        const body = new FormData(this.formTarget);
        // The ciphertext is in the request body now; do not keep it in the DOM.
        this.ciphertextTarget.value = '';

        let response: Response;
        try {
            response = await fetch(this.formTarget.action, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                mode: 'same-origin',
                redirect: 'error',
                cache: 'no-store',
            });
        } catch {
            throw new Error('CREATE_FAILED');
        }

        const data: unknown = await response.json().catch(() => null);

        if (response.status !== 201 || !isCreatedResponse(data)) {
            const code = (data as { error?: unknown } | null)?.error;
            throw new Error(typeof code === 'string' && /^[A-Z][A-Z_]*$/.test(code) ? code : 'CREATE_FAILED');
        }

        this.createdId = data.id;
        this.showResult(data.public_path, keyMaterial, mode);
    }

    private showResult(publicPath: string, keyMaterial: string, mode: ShareUrlKeyMode): void {
        // The plaintext is no longer needed once the share exists.
        this.secretTarget.value = '';
        if (this.hasFileInputTarget) {
            this.fileInputTarget.value = '';
        }

        const targets = this.resultLinkTargets();
        renderShareLinks(targets, {
            publicPath,
            keyMaterial,
            mode,
            allowEmbedInUrl: this.allowEmbedInUrlValue,
            showRememberNotice: this.showRememberNoticeValue,
        });

        if (mode === 'password') {
            // The creator chose the password: it is not repeated here (the recipient gets it by another channel).
            targets.key.value = '';
            this.customPasswordTarget.value = '';
        }

        setHidden(this.resultKeyRowTarget, mode === 'password');
        setHidden(this.passwordNoteTarget, mode !== 'password');
        setHidden(this.formTarget, true);
        setHidden(this.resultTarget, false);
    }

    /**
     * Wipes everything secret on every dialog close: inputs, hidden ciphertext, result fields, the in-memory key and any
     * sessionStorage leftover, then restores the empty form. After a create, dispatches the cancelable
     * `nowo-yopass-create:created` event and reloads the page unless a listener prevented it.
     */
    private wipe(): void {
        this.clearSecrets();
        this.hideError();
        setHidden(this.formTarget, false);
        setHidden(this.resultTarget, true);
        this.submitTarget.disabled = false;

        this.syncKeyMode();
        this.syncSecretCounter();

        const createdId = this.createdId;
        this.createdId = null;

        if (createdId !== null) {
            const event = new CustomEvent(CREATED_EVENT, { bubbles: true, cancelable: true, detail: { id: createdId } });

            if (this.element.dispatchEvent(event)) {
                reloadPage();
            }
        }
    }

    private clearSecrets(): void {
        this.formTarget.reset();
        this.secretTarget.value = '';

        if (this.hasCustomPasswordTarget) {
            this.customPasswordTarget.value = '';
        }

        this.ciphertextTarget.value = '';

        if (this.hasFileInputTarget) {
            this.fileInputTarget.value = '';
        }

        clearShareLinks(this.resultLinkTargets());

        try {
            sessionStorage.removeItem(PENDING_KEY_STORAGE);
        } catch {
            // Storage unavailable: nothing was written there either.
        }
    }

    /** Result fields of the dialog (always rendered by `_create_form.html.twig` in async mode). */
    private resultLinkTargets(): ShareLinkTargets {
        return {
            shortLink: this.resultShortLinkTarget,
            key: this.resultKeyTarget,
            oneClickLink: this.hasResultOneClickLinkTarget ? this.resultOneClickLinkTarget : null,
            rememberNotice: this.hasRememberNoticeTarget ? this.rememberNoticeTarget : null,
        };
    }

    private async buildPayload(): Promise<YopassPayload> {
        const activeFile = this.fileEnabledValue
            && this.hasFilePanelTarget
            && !isHidden(this.filePanelTarget);

        if (activeFile) {
            const file = this.fileInputTarget.files?.[0];

            if (!file) {
                throw new Error('FILE_REQUIRED');
            }

            return readFileAsPayload(file, this.maxFileBytesValue);
        }

        const text = this.secretTarget.value.trim();

        if (!text) {
            throw new Error('SECRET_REQUIRED');
        }

        if (!isSecretWithinCharacterLimit(text, this.maxSecretCharsValue)) {
            throw new Error('SECRET_TOO_LARGE');
        }

        return { kind: 'text', text };
    }

    private isPasswordMode(): boolean {
        return this.hasEncryptionPasswordTarget && this.encryptionPasswordTarget.checked;
    }

    private isTextTabActive(): boolean {
        return !this.fileEnabledValue
            || !this.hasFilePanelTarget
            || isHidden(this.filePanelTarget);
    }

    private isSecretWithinLimit(): boolean {
        return isSecretWithinCharacterLimit(this.secretTarget.value.trim(), this.maxSecretCharsValue);
    }

    private formatCounterLabel(count: number): string {
        const template = this.normalizeCounterTemplate(this.counterFormatValue || '__COUNT__ / __LIMIT__');

        return template
            .replaceAll('__COUNT__', String(count))
            .replaceAll('__LIMIT__', String(this.maxSecretCharsValue))
            .replaceAll('@@COUNT@@', String(count))
            .replaceAll('@@LIMIT@@', String(this.maxSecretCharsValue))
            .replaceAll('%count%', String(count))
            .replaceAll('%limit%', String(this.maxSecretCharsValue));
    }

    private normalizeCounterTemplate(template: string): string {
        return this.decodeHtmlEntities(template)
            .replaceAll('&#x40;&#x40;COUNT&#x40;&#x40;', '@@COUNT@@')
            .replaceAll('&#x40;&#x40;LIMIT&#x40;&#x40;', '@@LIMIT@@')
            .replaceAll('&#64;&#64;COUNT&#64;&#64;', '@@COUNT@@')
            .replaceAll('&#64;&#64;LIMIT&#64;&#64;', '@@LIMIT@@');
    }

    /**
     * Decode a limited set of HTML entities without using innerHTML (REQ-UX-001).
     *
     * @param value Possibly entity-encoded counter template from Twig attributes.
     * @returns Decoded plain text safe for display templates.
     */
    private decodeHtmlEntities(value: string): string {
        if (!value.includes('&')) {
            return value;
        }

        const doc = new DOMParser().parseFromString(`<body>${value}</body>`, 'text/html');

        return doc.body.textContent ?? value;
    }

    private showError(code: string): void {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = this.errorsValue[code] ?? code;
        setHidden(this.errorTarget, false);
    }

    private hideError(): void {
        if (this.hasErrorTarget) {
            setHidden(this.errorTarget, true);
        }
    }
}

/** 201 body of the JSON create endpoint: an id and a same-origin absolute path (never a URL to another origin). */
function isCreatedResponse(data: unknown): data is CreatedResponse {
    if (typeof data !== 'object' || data === null) {
        return false;
    }

    const { id, public_path: publicPath } = data as Record<string, unknown>;

    return typeof id === 'string'
        && /^[A-Za-z0-9-]{1,64}$/.test(id)
        && typeof publicPath === 'string'
        && publicPath.startsWith('/')
        && !publicPath.startsWith('//')
        && !publicPath.includes('\\');
}
