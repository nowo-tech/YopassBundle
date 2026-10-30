import { Controller } from '@hotwired/stimulus';
import { setHidden, syncHidden } from './visibility';
import { consumePendingShareKey } from './yopass-share-keys';
import {
    buildShareUrlWithKey,
    readShareKeyFromUrlAnyMode,
    stripShareKeyFromUrl,
    type ShareUrlKeyMode,
} from './yopass-share-url';

/**
 * Shows the short link, the decryption key and (only when `sharing.allow_embed_in_url` is true) the one-click link
 * after a share is created. The one-click link carries the key in the URL fragment (`#decrypt_key=`), which the
 * browser never sends to the server. Key material is read from sessionStorage (or a legacy URL), never from the server.
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
        allowEmbedInUrl: { type: Boolean, default: false },
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
    declare readonly allowEmbedInUrlValue: boolean;
    declare readonly hasResultOneClickLinkTarget: boolean;
    declare readonly keyUnavailableMessageValue: string;
    declare readonly hasRememberNoticeTarget: boolean;
    declare readonly hasErrorTarget: boolean;
    declare readonly hasContentTarget: boolean;

    connect(): void {
        syncHidden(this.element as HTMLElement, this.identifier);
        const resolved = this.resolveKeyMaterial();

        if (!resolved) {
            this.showKeyUnavailable();

            return;
        }

        this.renderLinks(resolved.material, resolved.mode);
        stripShareKeyFromUrl();
    }

    /** The links form only holds read-only fields: never submit it (Enter in a field). */
    preventSubmit(event: Event): void {
        event.preventDefault();
    }

    copyOneClickLink(): void {
        if (!this.hasResultOneClickLinkTarget) {
            return;
        }

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

    private buildOneClickLink(keyMaterial: string, mode: ShareUrlKeyMode): string {
        const pathWithKey = buildShareUrlWithKey(this.publicPathValue, keyMaterial, mode);

        return `${window.location.origin}${pathWithKey}`;
    }

    private renderLinks(keyMaterial: string, mode: ShareUrlKeyMode): void {
        const shortLink = this.buildShortLink();

        this.resultShortLinkTarget.value = shortLink;

        if (this.hasResultOneClickLinkTarget) {
            // Never put the key in a URL when the host disabled embedding (the Twig view omits the field as well).
            this.resultOneClickLinkTarget.value = this.allowEmbedInUrlValue ? this.buildOneClickLink(keyMaterial, mode) : '';
        }

        this.resultKeyTarget.value = keyMaterial;

        if (this.hasRememberNoticeTarget) {
            setHidden(this.rememberNoticeTarget, !this.showRememberNoticeValue);
        }
    }

    private showKeyUnavailable(): void {
        if (this.hasContentTarget) {
            setHidden(this.contentTarget, true);
        }

        if (this.hasErrorTarget) {
            this.errorTarget.textContent = this.keyUnavailableMessageValue;
            setHidden(this.errorTarget, false);
        }
    }
}
