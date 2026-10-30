import { Controller } from '@hotwired/stimulus';
import { setHidden, syncHidden } from './visibility';
import { decryptPayload, payloadToDisplayAsync } from './yopass-crypto';
import { loadShareKey, storeShareKey } from './yopass-share-keys';
import {
    readShareKeyFromUrl,
    stripShareKeyFromUrl,
    type ShareUrlKeyMode,
} from './yopass-share-url';

/**
 * Fetches ciphertext and decrypts locally (E2E — key never sent to server).
 */
export default class extends Controller {
    static targets = [
        'output',
        'error',
        'passwordPanel',
        'password',
        'passwordFromLinkHint',
        'keyPanel',
        'key',
        'keyFromLinkHint',
        'downloadLink',
        'revealButton',
    ];

    static values = {
        consumeUrl: String,
        shareId: { type: String, default: '' },
        mode: { type: String, default: 'key' },
        autoReveal: { type: Boolean, default: true },
        errors: { type: Object, default: {} },
    };

    declare readonly outputTarget: HTMLElement;
    declare readonly errorTarget: HTMLElement;
    declare readonly passwordPanelTarget: HTMLElement;
    declare readonly passwordTarget: HTMLInputElement;
    declare readonly passwordFromLinkHintTarget: HTMLElement;
    declare readonly keyPanelTarget: HTMLElement;
    declare readonly keyTarget: HTMLInputElement;
    declare readonly keyFromLinkHintTarget: HTMLElement;
    declare readonly downloadLinkTarget: HTMLAnchorElement;
    declare readonly revealButtonTarget: HTMLButtonElement;
    declare readonly consumeUrlValue: string;
    declare readonly shareIdValue: string;
    declare readonly modeValue: string;
    declare readonly autoRevealValue: boolean;
    declare readonly errorsValue: Record<string, string>;
    declare readonly hasOutputTarget: boolean;
    declare readonly hasErrorTarget: boolean;
    declare readonly hasPasswordPanelTarget: boolean;
    declare readonly hasPasswordFromLinkHintTarget: boolean;
    declare readonly hasKeyPanelTarget: boolean;
    declare readonly hasKeyFromLinkHintTarget: boolean;
    declare readonly hasDownloadLinkTarget: boolean;
    declare readonly hasRevealButtonTarget: boolean;
    declare readonly hasPasswordTarget: boolean;
    declare readonly hasKeyTarget: boolean;

    private capturedKeyMaterial = '';

    connect(): void {
        syncHidden(this.element as HTMLElement, this.identifier);
        this.captureKeyMaterialFromUrl();

        if (this.modeValue === 'password') {
            this.applyPasswordFromUrl();
        } else {
            this.syncKeyPanel();
        }

        if (this.autoRevealValue && this.resolveKeyMaterial() !== '') {
            void this.reveal();
        }
    }

    async reveal(): Promise<void> {
        this.hideError();

        const keyMaterial = this.resolveKeyMaterial();

        if (!keyMaterial) {
            this.showError(this.modeValue === 'password' ? 'PASSWORD_REQUIRED' : 'KEY_REQUIRED');

            return;
        }

        try {
            const response = await fetch(this.consumeUrlValue, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const data = await response.json() as {
                status: string;
                ciphertext?: string;
            };

            if (data.status !== 'ok' || !data.ciphertext) {
                this.showError(data.status);

                return;
            }

            const payload = await decryptPayload(data.ciphertext, keyMaterial);
            const display = await payloadToDisplayAsync(payload);

            if (display.type === 'text') {
                if (this.hasOutputTarget) {
                    this.outputTarget.textContent = display.text;
                    setHidden(this.outputTarget, false);
                }

                if (this.hasDownloadLinkTarget) {
                    setHidden(this.downloadLinkTarget, true);
                }
            } else {
                if (this.hasOutputTarget) {
                    setHidden(this.outputTarget, true);
                }

                if (this.hasDownloadLinkTarget) {
                    this.downloadLinkTarget.href = URL.createObjectURL(display.blob);
                    this.downloadLinkTarget.download = display.filename;
                    this.downloadLinkTarget.textContent = display.filename;
                    setHidden(this.downloadLinkTarget, false);
                }
            }

            this.hideRevealControls();
        } catch {
            this.showError('INVALID_KEY');
        }
    }

    private getUrlKeyMode(): ShareUrlKeyMode {
        return this.modeValue === 'password' ? 'password' : 'key';
    }

    private captureKeyMaterialFromUrl(): void {
        const urlMaterial = readShareKeyFromUrl(this.getUrlKeyMode());

        if (urlMaterial === '') {
            return;
        }

        this.capturedKeyMaterial = urlMaterial;

        if (this.shareIdValue !== '') {
            storeShareKey(this.shareIdValue, this.getUrlKeyMode(), urlMaterial);
        }

        stripShareKeyFromUrl(this.getUrlKeyMode());
    }

    private applyPasswordFromUrl(): void {
        const urlMaterial = this.capturedKeyMaterial;

        if (urlMaterial === '' || !this.hasPasswordTarget) {
            return;
        }

        this.passwordTarget.value = urlMaterial;

        if (this.hasPasswordPanelTarget) {
            setHidden(this.passwordPanelTarget, true);
        }

        if (this.hasPasswordFromLinkHintTarget) {
            setHidden(this.passwordFromLinkHintTarget, false);
        }
    }

    private syncKeyPanel(): void {
        const embeddedKey = this.resolveEmbeddedKeyMaterial();

        if (embeddedKey !== '') {
            if (this.hasKeyTarget) {
                this.keyTarget.value = embeddedKey;
            }

            if (this.hasKeyPanelTarget) {
                setHidden(this.keyPanelTarget, true);
            }

            if (this.hasKeyFromLinkHintTarget) {
                setHidden(this.keyFromLinkHintTarget, false);
            }

            return;
        }

        if (this.hasKeyPanelTarget) {
            setHidden(this.keyPanelTarget, false);
        }

        if (this.hasKeyFromLinkHintTarget) {
            setHidden(this.keyFromLinkHintTarget, true);
        }
    }

    private resolveEmbeddedKeyMaterial(): string {
        const urlMaterial = readShareKeyFromUrl(this.getUrlKeyMode());

        if (urlMaterial !== '') {
            return urlMaterial;
        }

        if (this.capturedKeyMaterial !== '') {
            return this.capturedKeyMaterial;
        }

        if (this.shareIdValue !== '') {
            return loadShareKey(this.shareIdValue)?.material ?? '';
        }

        return '';
    }

    private resolveKeyMaterial(): string {
        const embeddedKey = this.resolveEmbeddedKeyMaterial();

        if (embeddedKey !== '') {
            return embeddedKey;
        }

        if (this.modeValue === 'password' && this.hasPasswordTarget) {
            return this.passwordTarget.value.trim();
        }

        if (this.modeValue !== 'password' && this.hasKeyTarget) {
            return this.keyTarget.value.trim();
        }

        return '';
    }

    private showError(status: string): void {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = this.errorsValue[status] ?? status;
            setHidden(this.errorTarget, false);
        }

        if (this.hasOutputTarget) {
            setHidden(this.outputTarget, true);
        }
    }

    private hideError(): void {
        if (this.hasErrorTarget) {
            setHidden(this.errorTarget, true);
        }
    }

    private hideRevealControls(): void {
        if (this.hasRevealButtonTarget) {
            setHidden(this.revealButtonTarget, true);
        }

        if (this.hasPasswordPanelTarget) {
            setHidden(this.passwordPanelTarget, true);
        }

        if (this.hasKeyPanelTarget) {
            setHidden(this.keyPanelTarget, true);
        }
    }
}
