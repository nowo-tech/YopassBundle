import { Controller } from '@hotwired/stimulus';
import {
    countSecretCharactersForSubmit,
    encryptPayload,
    isSecretWithinCharacterLimit,
    readFileAsPayload,
    type YopassPayload,
} from './yopass-crypto';
import { storePendingShareKey } from './yopass-share-keys';

/**
 * Encrypts share content in the browser, then submits a standard Symfony form (POST + redirect).
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
    ];

    static values = {
        fileEnabled: { type: Boolean, default: false },
        maxFileBytes: { type: Number, default: 0 },
        maxSecretChars: { type: Number, default: 512 * 1024 },
        counterFormat: { type: String, default: '%count% / %limit%' },
        errors: { type: Object, default: {} },
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

    private readonly onSecretInput = (): void => {
        this.syncSecretCounter();
    };

    connect(): void {
        this.syncKeyMode();
        this.secretTarget.addEventListener('input', this.onSecretInput);
        this.secretTarget.addEventListener('paste', this.onSecretInput);
        this.syncSecretCounter();
    }

    disconnect(): void {
        this.secretTarget.removeEventListener('input', this.onSecretInput);
        this.secretTarget.removeEventListener('paste', this.onSecretInput);
    }

    switchTab(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        const tab = button.dataset.yopassTab ?? 'text';

        if (tab === 'file' && !this.fileEnabledValue) {
            return;
        }

        if (this.hasTextPanelTarget) {
            this.textPanelTarget.classList.toggle('d-none', tab !== 'text');
        }

        if (this.hasFilePanelTarget) {
            this.filePanelTarget.classList.toggle('d-none', tab !== 'file');
        }

        this.syncSecretCounter();
    }

    syncKeyMode(): void {
        const passwordMode = this.isPasswordMode();

        if (this.hasCustomPasswordPanelTarget) {
            this.customPasswordPanelTarget.classList.toggle('d-none', !passwordMode);
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
        this.secretCounterTarget.classList.toggle('text-danger', atLimit);
        this.secretCounterTarget.classList.toggle('text-secondary', !atLimit);

        if (this.hasSecretLimitHelpTarget) {
            this.secretLimitHelpTarget.classList.toggle('d-none', !atLimit);
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
            storePendingShareKey(passwordMode ? 'password' : 'key', keyMaterial);

            this.formTarget.submit();
        } catch (error) {
            const message = error instanceof Error ? error.message : 'UNKNOWN';
            this.showError(message);

            if (this.isTextTabActive() && !this.isSecretWithinLimit()) {
                this.submitTarget.disabled = true;
            } else {
                this.submitTarget.disabled = false;
            }
        }
    }

    private async buildPayload(): Promise<YopassPayload> {
        const activeFile = this.fileEnabledValue
            && this.hasFilePanelTarget
            && !this.filePanelTarget.classList.contains('d-none');

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
            || this.filePanelTarget.classList.contains('d-none');
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

    private decodeHtmlEntities(value: string): string {
        if (!value.includes('&')) {
            return value;
        }

        const textarea = document.createElement('textarea');
        textarea.innerHTML = value;

        return textarea.value;
    }

    private showError(code: string): void {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = this.errorsValue[code] ?? code;
        this.errorTarget.classList.remove('d-none');
    }

    private hideError(): void {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('d-none');
        }
    }
}
