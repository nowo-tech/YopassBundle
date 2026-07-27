/** Canonical query parameter for one-click decrypt links. */
export const SHARE_DECRYPT_KEY_QUERY_PARAM = 'decrypt_key';

/** @deprecated Legacy param names kept for backward compatibility when reading URLs. */
const LEGACY_KEY_QUERY_PARAM = 'key';
const LEGACY_PASSWORD_QUERY_PARAM = 'password';

export type ShareUrlKeyMode = 'key' | 'password';

/**
 * Returns the query parameter name used when embedding decryption material in a share URL.
 */
export function getShareKeyQueryParam(_mode: ShareUrlKeyMode = 'key'): string {
    return SHARE_DECRYPT_KEY_QUERY_PARAM;
}

/**
 * Reads decryption material from the current URL for the expected mode (supports legacy params).
 */
export function readShareKeyFromUrl(mode: ShareUrlKeyMode): string {
    const params = new URLSearchParams(window.location.search);
    const decryptKey = params.get(SHARE_DECRYPT_KEY_QUERY_PARAM)?.trim() ?? '';

    if (decryptKey !== '') {
        return decryptKey;
    }

    if (mode === 'key') {
        const legacyKey = params.get(LEGACY_KEY_QUERY_PARAM)?.trim() ?? '';

        if (legacyKey !== '') {
            return legacyKey;
        }

        return readLegacyHashMaterial();
    }

    const legacyPassword = params.get(LEGACY_PASSWORD_QUERY_PARAM)?.trim() ?? '';

    if (legacyPassword !== '') {
        return legacyPassword;
    }

    return readLegacyHashMaterial();
}

/**
 * Reads decryption material from the URL and infers whether it is a key or password.
 */
export function readShareKeyFromUrlAnyMode(): { material: string; mode: ShareUrlKeyMode } | null {
    const params = new URLSearchParams(window.location.search);
    const decryptKey = params.get(SHARE_DECRYPT_KEY_QUERY_PARAM)?.trim() ?? '';

    if (decryptKey !== '') {
        return { material: decryptKey, mode: 'key' };
    }

    const legacyKey = params.get(LEGACY_KEY_QUERY_PARAM)?.trim() ?? '';

    if (legacyKey !== '') {
        return { material: legacyKey, mode: 'key' };
    }

    const legacyPassword = params.get(LEGACY_PASSWORD_QUERY_PARAM)?.trim() ?? '';

    if (legacyPassword !== '') {
        return { material: legacyPassword, mode: 'password' };
    }

    const legacyHash = readLegacyHashMaterial();

    if (legacyHash !== '') {
        return { material: legacyHash, mode: 'key' };
    }

    return null;
}

/**
 * Builds a relative share URL with decryption material in the query string.
 */
export function buildShareUrlWithKey(
    baseUrl: string,
    keyMaterial: string,
    mode: ShareUrlKeyMode = 'key',
): string {
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set(getShareKeyQueryParam(mode), keyMaterial);

    return `${url.pathname}${url.search}`;
}

/**
 * Builds an absolute share URL with decryption material in the query string.
 */
export function buildFullShareUrlWithKey(
    baseUrl: string,
    keyMaterial: string,
    mode: ShareUrlKeyMode = 'key',
): string {
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set(getShareKeyQueryParam(mode), keyMaterial);

    return url.toString();
}

/**
 * Removes decryption material from the address bar without reloading the page.
 */
export function stripShareKeyFromUrl(_mode?: ShareUrlKeyMode): void {
    const url = new URL(window.location.href);

    url.searchParams.delete(SHARE_DECRYPT_KEY_QUERY_PARAM);
    url.searchParams.delete(LEGACY_KEY_QUERY_PARAM);
    url.searchParams.delete(LEGACY_PASSWORD_QUERY_PARAM);

    window.history.replaceState(null, '', `${url.pathname}${url.search}`);
}

function readLegacyHashMaterial(): string {
    const raw = window.location.hash.replace(/^#/, '').trim();

    if (raw === '') {
        return '';
    }

    try {
        return decodeURIComponent(raw);
    } catch {
        return raw;
    }
}
