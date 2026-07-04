import { Controller } from '@hotwired/stimulus';
import { consumePendingShareKey } from './yopass-share-keys';
import {
    buildShareUrlWithKey,
    readShareKeyFromUrlAnyMode,
    stripShareKeyFromUrl,
    type ShareUrlKeyMode,
} from './yopass-share-url';

/**
 * Shows one-click, short link and decryption key after a share is created.
 * Key material is read from the URL query string or sessionStorage (never from the server).
 */
export default class extends Controller {
    static targets = [
        'rememberNotice',
        'resultOneClickLink',
        'resultShortLink',
        'resultKey',
        'error',
        'content',
    ];

    static values = {
        shareId: String,
        publicPath: String,
        showRememberNotice: { type: Boolean, default: true },
        keyUnavailableMessage: { type: String, default: 'Decryption key is not available. Create a new share to see the links again.' },
    };

    declare readonly rememberNoticeTarget: HTMLElement;
    declare readonly resultOneClickLinkTarget: HTMLInputElement;
    declare readonly resultShortLinkTarget: HTMLInputElement;
    declare readonly resultKeyTarget: HTMLInputElement;
    declare readonly errorTarget: HTMLElement;
    declare readonly contentTarget: HTMLElement;
    declare readonly shareIdValue: string;
    declare readonly publicPathValue: string;
    declare readonly showRememberNoticeValue: boolean;
    declare readonly keyUnavailableMessageValue: string;
    declare readonly hasRememberNoticeTarget: boolean;
    declare readonly hasErrorTarget: boolean;
    declare readonly hasContentTarget: boolean;

    connect(): void {
        const resolved = this.resolveKeyMaterial();

        if (!resolved) {
            this.showKeyUnavailable();

            return;
        }

        this.renderLinks(resolved.material, resolved.mode);
        stripShareKeyFromUrl();
    }

    copyOneClickLink(): void {
        void navigator.clipboard?.writeText(this.resultOneClickLinkTarget.value);
    }

    copyShortLink(): void {
        void navigator.clipboard?.writeText(this.resultShortLinkTarget.value);
    }

    copyKey(): void {
        void navigator.clipboard?.writeText(this.resultKeyTarget.value);
    }

    private resolveKeyMaterial(): { material: string; mode: ShareUrlKeyMode } | null {
        const fromUrl = readShareKeyFromUrlAnyMode();

        if (fromUrl !== null) {
            return fromUrl;
        }

        const stored = consumePendingShareKey(this.shareIdValue);

        if (stored === null) {
            return null;
        }

        return { material: stored.material, mode: stored.mode };
    }

    private buildShortLink(): string {
        return `${window.location.origin}${this.publicPathValue}`;
    }

    private buildOneClickLink(shortLink: string, keyMaterial: string, mode: ShareUrlKeyMode): string {
        const pathWithKey = buildShareUrlWithKey(this.publicPathValue, keyMaterial, mode);

        return `${window.location.origin}${pathWithKey}`;
    }

    private renderLinks(keyMaterial: string, mode: ShareUrlKeyMode): void {
        const shortLink = this.buildShortLink();

        this.resultShortLinkTarget.value = shortLink;
        this.resultOneClickLinkTarget.value = this.buildOneClickLink(shortLink, keyMaterial, mode);
        this.resultKeyTarget.value = keyMaterial;

        if (this.hasRememberNoticeTarget) {
            this.rememberNoticeTarget.classList.toggle('d-none', !this.showRememberNoticeValue);
        }
    }

    private showKeyUnavailable(): void {
        if (this.hasContentTarget) {
            this.contentTarget.classList.add('d-none');
        }

        if (this.hasErrorTarget) {
            this.errorTarget.textContent = this.keyUnavailableMessageValue;
            this.errorTarget.classList.remove('d-none');
        }
    }
}
