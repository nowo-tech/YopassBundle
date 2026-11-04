import { setHidden } from './visibility';
import { buildShareUrlWithKey, type ShareUrlKeyMode } from './yopass-share-url';

/** Read-only result fields shared by the created page and the create dialog. */
export type ShareLinkTargets = {
    shortLink: HTMLInputElement;
    key: HTMLInputElement;
    oneClickLink?: HTMLInputElement | null;
    rememberNotice?: HTMLElement | null;
};

export type ShareLinkOptions = {
    publicPath: string;
    keyMaterial: string;
    mode: ShareUrlKeyMode;
    allowEmbedInUrl: boolean;
    showRememberNotice: boolean;
};

/** Absolute short link (no key): `<origin><public path>`. */
export function buildShortLink(publicPath: string): string {
    return `${window.location.origin}${publicPath}`;
}

/** Absolute one-click link: the key travels in the URL fragment (`#decrypt_key=`), never sent to the server. */
export function buildOneClickLink(publicPath: string, keyMaterial: string, mode: ShareUrlKeyMode): string {
    return `${window.location.origin}${buildShareUrlWithKey(publicPath, keyMaterial, mode)}`;
}

/**
 * Fills the result fields (short link, key, one-click link only when the host allows embedding) and the remember
 * notice. Identical for the created page and the create dialog.
 */
export function renderShareLinks(targets: ShareLinkTargets, options: ShareLinkOptions): void {
    targets.shortLink.value = buildShortLink(options.publicPath);

    if (targets.oneClickLink) {
        // Never put the key in a URL when the host disabled embedding (the Twig view omits the field as well).
        targets.oneClickLink.value = options.allowEmbedInUrl
            ? buildOneClickLink(options.publicPath, options.keyMaterial, options.mode)
            : '';
    }

    targets.key.value = options.keyMaterial;

    if (targets.rememberNotice) {
        setHidden(targets.rememberNotice, !options.showRememberNotice);
    }
}

/** Empties every result field (the key and the links carrying it). */
export function clearShareLinks(targets: ShareLinkTargets): void {
    targets.shortLink.value = '';
    targets.key.value = '';

    if (targets.oneClickLink) {
        targets.oneClickLink.value = '';
    }
}

/** Copies a value to the clipboard when the API is available. */
export function copyToClipboard(value: string): void {
    void navigator.clipboard?.writeText(value);
}
