/**
 * Canonical parameter name for one-click decrypt links.
 *
 * The bundle now writes it in the URL **fragment** (`/share/{id}#decrypt_key=…`), which the browser never sends
 * to the server. The query-string form (`?decrypt_key=…`) of links created up to 1.8.0 is still read; the server
 * redirects it to the fragment form before routing (see `ShareKeyQueryRedirectListener`).
 */
export const SHARE_DECRYPT_KEY_QUERY_PARAM = 'decrypt_key';

/** @deprecated Legacy param names kept for backward compatibility when reading URLs. */
const LEGACY_KEY_QUERY_PARAM = 'key';
const LEGACY_PASSWORD_QUERY_PARAM = 'password';

export type ShareUrlKeyMode = 'key' | 'password';

/**
 * Returns the parameter name used when embedding decryption material in a share URL fragment.
 */
export function getShareKeyQueryParam(_mode: ShareUrlKeyMode = 'key'): string {
    return SHARE_DECRYPT_KEY_QUERY_PARAM;
}

/**
 * Reads decryption material from the current URL for the expected mode.
 *
 * Order: `#decrypt_key=` fragment, then the legacy query parameters (`?decrypt_key=`, `?key=` / `?password=`),
 * then a legacy raw `#material` fragment.
 */
export function readShareKeyFromUrl(mode: ShareUrlKeyMode): string {
    const fromFragment = readFragmentParam();

    if (fromFragment !== '') {
        return fromFragment;
    }

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
    const fromFragment = readFragmentParam();

    if (fromFragment !== '') {
        return { material: fromFragment, mode: 'key' };
    }

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
 * Builds a relative share URL with decryption material in the URL fragment (never sent to the server).
 */
export function buildShareUrlWithKey(
    baseUrl: string,
    keyMaterial: string,
    mode: ShareUrlKeyMode = 'key',
): string {
    const url = buildUrlWithFragmentKey(baseUrl, keyMaterial, mode);

    return `${url.pathname}${url.search}${url.hash}`;
}

/**
 * Builds an absolute share URL with decryption material in the URL fragment (never sent to the server).
 */
export function buildFullShareUrlWithKey(
    baseUrl: string,
    keyMaterial: string,
    mode: ShareUrlKeyMode = 'key',
): string {
    return buildUrlWithFragmentKey(baseUrl, keyMaterial, mode).toString();
}

/**
 * Removes decryption material (key query parameters and the whole fragment) from the address bar without reloading.
 */
export function stripShareKeyFromUrl(_mode?: ShareUrlKeyMode): void {
    const url = new URL(window.location.href);

    url.searchParams.delete(SHARE_DECRYPT_KEY_QUERY_PARAM);
    url.searchParams.delete(LEGACY_KEY_QUERY_PARAM);
    url.searchParams.delete(LEGACY_PASSWORD_QUERY_PARAM);

    window.history.replaceState(null, '', `${url.pathname}${url.search}`);
}

function buildUrlWithFragmentKey(baseUrl: string, keyMaterial: string, mode: ShareUrlKeyMode): URL {
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.delete(SHARE_DECRYPT_KEY_QUERY_PARAM);
    url.hash = new URLSearchParams({ [getShareKeyQueryParam(mode)]: keyMaterial }).toString();

    return url;
}

function readFragmentParam(): string {
    const raw = window.location.hash.replace(/^#/, '');

    if (!raw.startsWith(`${SHARE_DECRYPT_KEY_QUERY_PARAM}=`)) {
        return '';
    }

    return new URLSearchParams(raw).get(SHARE_DECRYPT_KEY_QUERY_PARAM)?.trim() ?? '';
}

function readLegacyHashMaterial(): string {
    const raw = window.location.hash.replace(/^#/, '').trim();

    if (raw === '' || raw.startsWith(`${SHARE_DECRYPT_KEY_QUERY_PARAM}=`)) {
        return '';
    }

    try {
        return decodeURIComponent(raw);
    } catch {
        return raw;
    }
}
