import { Controller } from '@hotwired/stimulus';
import { decryptPayload, payloadToDisplayAsync } from './yopass-crypto';
import { loadShareKey } from './yopass-share-keys';
import { buildFullShareUrlWithKey, type ShareUrlKeyMode } from './yopass-share-url';

type PreviewResponse = {
    status: string;
    availability?: string;
    ciphertext?: string;
    mode?: string;
    payloadKind?: string;
    publicPath?: string;
    maxReads?: number;
    readsLeft?: number;
    expiresAt?: string;
    extendable?: boolean;
    accessLog?: AccessLogEntry[];
};

type AccessLogEntry = {
    accessedAt: string;
    readNumber: number;
    ipAddress: string | null;
    userAgent: string | null;
};

type ExtendResponse = {
    status: string;
    availability?: string;
    maxReads?: number;
    readsLeft?: number;
    expiresAt?: string;
    error?: string;
};

/**
 * Lets share creators preview encrypted content and extend limits without consuming a read.
 */
export default class extends Controller {
    static targets = [
        'modal',
        'output',
        'error',
        'passwordPanel',
        'password',
        'keyPanel',
        'key',
        'downloadLink',
        'publicLink',
        'availability',
        'decryptButton',
        'extendPanel',
        'extendSummary',
        'extendExpiresIn',
        'extendMaxReads',
        'extendSuccess',
        'accessLogPanel',
        'accessLogTable',
        'accessLogBody',
        'accessLogEmpty',
    ];

    static values = {
        previewPathTemplate: String,
        extendPathTemplate: { type: String, default: '' },
        csrfToken: { type: String, default: '' },
        errors: { type: Object, default: {} },
        availabilityLabels: { type: Object, default: {} },
        extendSuccessMessage: { type: String, default: 'Updated.' },
    };

    declare readonly modalTarget: HTMLElement;
    declare readonly outputTarget: HTMLElement;
    declare readonly errorTarget: HTMLElement;
    declare readonly passwordPanelTarget: HTMLElement;
    declare readonly passwordTarget: HTMLInputElement;
    declare readonly keyPanelTarget: HTMLElement;
    declare readonly keyTarget: HTMLInputElement;
    declare readonly downloadLinkTarget: HTMLAnchorElement;
    declare readonly publicLinkTarget: HTMLInputElement;
    declare readonly availabilityTarget: HTMLElement;
    declare readonly decryptButtonTarget: HTMLButtonElement;
    declare readonly extendPanelTarget: HTMLElement;
    declare readonly extendSummaryTarget: HTMLElement;
    declare readonly extendExpiresInTarget: HTMLSelectElement;
    declare readonly extendMaxReadsTarget: HTMLSelectElement;
    declare readonly extendSuccessTarget: HTMLElement;
    declare readonly accessLogPanelTarget: HTMLElement;
    declare readonly accessLogTableTarget: HTMLTableElement;
    declare readonly accessLogBodyTarget: HTMLTableSectionElement;
    declare readonly accessLogEmptyTarget: HTMLElement;
    declare readonly hasAccessLogPanelTarget: boolean;
    declare readonly hasAccessLogTableTarget: boolean;
    declare readonly hasAccessLogBodyTarget: boolean;
    declare readonly hasAccessLogEmptyTarget: boolean;
    declare readonly previewPathTemplateValue: string;
    declare readonly extendPathTemplateValue: string;
    declare readonly csrfTokenValue: string;
    declare readonly errorsValue: Record<string, string>;
    declare readonly availabilityLabelsValue: Record<string, string>;
    declare readonly extendSuccessMessageValue: string;
    declare readonly hasOutputTarget: boolean;
    declare readonly hasErrorTarget: boolean;
    declare readonly hasPasswordPanelTarget: boolean;
    declare readonly hasKeyPanelTarget: boolean;
    declare readonly hasDownloadLinkTarget: boolean;
    declare readonly hasPublicLinkTarget: boolean;
    declare readonly hasAvailabilityTarget: boolean;
    declare readonly hasDecryptButtonTarget: boolean;
    declare readonly hasExtendPanelTarget: boolean;
    declare readonly hasExtendSummaryTarget: boolean;
    declare readonly hasExtendSuccessTarget: boolean;

    private activeShareId: string | null = null;
    private previewData: PreviewResponse | null = null;
    private focusExtendOnOpen = false;

    async open(event: Event): Promise<void> {
        this.focusExtendOnOpen = false;
        await this.loadShare(event);
    }

    async openExtend(event: Event): Promise<void> {
        this.focusExtendOnOpen = true;
        await this.loadShare(event);
    }

    async decryptFromForm(event: Event): Promise<void> {
        event.preventDefault();
        await this.decrypt(this.resolveKeyMaterial());
    }

    async submitExtend(event: Event): Promise<void> {
        event.preventDefault();
        this.hideExtendSuccess();
        this.hideError();

        if (!this.activeShareId || this.extendPathTemplateValue === '') {
            return;
        }

        const body: Record<string, string | number> = {};
        const expiresIn = this.extendExpiresInTarget.value.trim();
        const maxReads = this.extendMaxReadsTarget.value.trim();

        if (expiresIn !== '') {
            body.expiresIn = expiresIn;
        }

        if (maxReads !== '') {
            body.maxReads = Number.parseInt(maxReads, 10);
        }

        try {
            const response = await fetch(this.buildExtendUrl(this.activeShareId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfTokenValue,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });

            const data = await response.json() as ExtendResponse;

            if (!response.ok) {
                this.showError(data.error ?? 'EXTEND_FAILED');

                return;
            }

            this.previewData = {
                ...this.previewData,
                availability: data.availability,
                maxReads: data.maxReads,
                readsLeft: data.readsLeft,
                expiresAt: data.expiresAt,
            };

            this.renderMetadata(this.previewData);
            this.renderExtendPanel(this.previewData);
            this.showExtendSuccess();

            window.setTimeout(() => {
                window.location.reload();
            }, 800);
        } catch {
            this.showError('EXTEND_FAILED');
        }
    }

    copyPublicLink(): void {
        if (this.hasPublicLinkTarget) {
            void navigator.clipboard?.writeText(this.publicLinkTarget.value);
        }
    }

    close(): void {
        this.hideModal();
        this.activeShareId = null;
        this.previewData = null;
        this.focusExtendOnOpen = false;
    }

    private async loadShare(event: Event): Promise<void> {
        const button = event.currentTarget as HTMLButtonElement;
        const shareId = button.dataset.shareId;

        if (!shareId) {
            return;
        }

        this.activeShareId = shareId;
        this.previewData = null;
        this.resetDisplay();
        this.showModal();

        try {
            const response = await fetch(this.buildPreviewUrl(shareId), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('PREVIEW_FAILED');
            }

            this.previewData = await response.json() as PreviewResponse;
            this.renderMetadata(this.previewData, shareId);
            this.renderAccessLog(this.previewData);
            this.renderExtendPanel(this.previewData);

            if (this.focusExtendOnOpen && this.hasExtendPanelTarget) {
                this.extendPanelTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            const storedKey = loadShareKey(shareId);
            const mode = this.previewData.mode ?? 'key';

            if (mode === 'password') {
                if (storedKey?.mode === 'password') {
                    this.passwordTarget.value = storedKey.material;
                }

                if (this.hasPasswordPanelTarget) {
                    this.passwordPanelTarget.classList.remove('d-none');
                }

                if (this.hasKeyPanelTarget) {
                    this.keyPanelTarget.classList.add('d-none');
                }
            } else if (storedKey?.mode === 'key') {
                if (this.hasKeyPanelTarget) {
                    this.keyTarget.value = storedKey.material;
                    this.keyPanelTarget.classList.add('d-none');
                }

                if (this.hasPasswordPanelTarget) {
                    this.passwordPanelTarget.classList.add('d-none');
                }

                await this.decrypt(storedKey.material);
            } else {
                if (this.hasKeyPanelTarget) {
                    this.keyPanelTarget.classList.remove('d-none');
                }

                if (this.hasPasswordPanelTarget) {
                    this.passwordPanelTarget.classList.add('d-none');
                }
            }
        } catch {
            this.showError('PREVIEW_FAILED');
        }
    }

    private async decrypt(keyMaterial: string): Promise<void> {
        this.hideError();

        if (!keyMaterial) {
            this.showError(this.previewData?.mode === 'password' ? 'PASSWORD_REQUIRED' : 'KEY_REQUIRED');

            return;
        }

        if (!this.previewData?.ciphertext) {
            this.showError('PREVIEW_FAILED');

            return;
        }

        try {
            const payload = await decryptPayload(this.previewData.ciphertext, keyMaterial);
            const display = await payloadToDisplayAsync(payload);

            if (display.type === 'text') {
                if (this.hasOutputTarget) {
                    this.outputTarget.textContent = display.text;
                    this.outputTarget.classList.remove('d-none');
                }

                if (this.hasDownloadLinkTarget) {
                    this.downloadLinkTarget.classList.add('d-none');
                }
            } else {
                if (this.hasOutputTarget) {
                    this.outputTarget.classList.add('d-none');
                }

                if (this.hasDownloadLinkTarget) {
                    this.downloadLinkTarget.href = URL.createObjectURL(display.blob);
                    this.downloadLinkTarget.download = display.filename;
                    this.downloadLinkTarget.textContent = display.filename;
                    this.downloadLinkTarget.classList.remove('d-none');
                }
            }

            this.hideDecryptControls();
        } catch {
            this.showError('INVALID_KEY');
        }
    }

    private resolveKeyMaterial(): string {
        if (this.previewData?.mode === 'password') {
            return this.passwordTarget.value;
        }

        return this.keyTarget.value.trim();
    }

    private renderMetadata(data: PreviewResponse, shareId: string): void {
        if (this.hasAvailabilityTarget && data.availability) {
            this.availabilityTarget.textContent = this.availabilityLabelsValue[data.availability] ?? data.availability;
        }

        if (this.hasPublicLinkTarget && data.publicPath) {
            const storedKey = loadShareKey(shareId);
            const baseLink = `${window.location.origin}${data.publicPath}`;
            const mode: ShareUrlKeyMode = (data.mode ?? storedKey?.mode ?? 'key') === 'password' ? 'password' : 'key';

            this.publicLinkTarget.value = storedKey?.material
                ? buildFullShareUrlWithKey(baseLink, storedKey.material, mode)
                : baseLink;
        }
    }

    private renderAccessLog(data: PreviewResponse): void {
        if (!this.hasAccessLogPanelTarget || data.accessLog === undefined) {
            if (this.hasAccessLogPanelTarget) {
                this.accessLogPanelTarget.classList.add('d-none');
            }

            return;
        }

        const entries = data.accessLog;

        if (entries.length === 0) {
            this.accessLogPanelTarget.classList.remove('d-none');

            if (this.hasAccessLogEmptyTarget) {
                this.accessLogEmptyTarget.classList.remove('d-none');
            }

            if (this.hasAccessLogTableTarget) {
                this.accessLogTableTarget.classList.add('d-none');
            }

            if (this.hasAccessLogBodyTarget) {
                this.accessLogBodyTarget.replaceChildren();
            }

            return;
        }

        this.accessLogPanelTarget.classList.remove('d-none');

        if (this.hasAccessLogEmptyTarget) {
            this.accessLogEmptyTarget.classList.add('d-none');
        }

        if (!this.hasAccessLogBodyTarget || !this.hasAccessLogTableTarget) {
            return;
        }

        this.accessLogBodyTarget.replaceChildren();

        for (const entry of entries) {
            const row = document.createElement('tr');

            const whenCell = document.createElement('td');
            whenCell.textContent = this.formatDateTime(entry.accessedAt);
            row.appendChild(whenCell);

            const readCell = document.createElement('td');
            readCell.textContent = String(entry.readNumber);
            row.appendChild(readCell);

            const ipCell = document.createElement('td');
            ipCell.textContent = entry.ipAddress ?? '—';
            row.appendChild(ipCell);

            const uaCell = document.createElement('td');
            uaCell.textContent = entry.userAgent ?? '—';
            uaCell.classList.add('text-break');
            row.appendChild(uaCell);

            this.accessLogBodyTarget.appendChild(row);
        }

        this.accessLogTableTarget.classList.remove('d-none');
    }

    private renderExtendPanel(data: PreviewResponse): void {
        if (!this.hasExtendPanelTarget) {
            return;
        }

        if (!data.extendable) {
            this.extendPanelTarget.classList.add('d-none');

            return;
        }

        this.extendPanelTarget.classList.remove('d-none');

        if (this.hasExtendSummaryTarget) {
            const expires = data.expiresAt ? this.formatDateTime(data.expiresAt) : '—';
            const reads = `${data.readsLeft ?? 0} / ${data.maxReads ?? 0}`;
            this.extendSummaryTarget.textContent = `${expires} · ${reads}`;
        }

        const currentMaxReads = data.maxReads ?? 0;

        for (const option of Array.from(this.extendMaxReadsTarget.options)) {
            if (option.value === '') {
                option.hidden = false;
                continue;
            }

            const value = Number.parseInt(option.value, 10);
            option.hidden = Number.isNaN(value) || value <= currentMaxReads;
        }

        this.extendExpiresInTarget.value = '';
        this.extendMaxReadsTarget.value = '';
    }

    private formatDateTime(value: string): string {
        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    }

    private buildPreviewUrl(shareId: string): string {
        return this.substituteShareId(this.previewPathTemplateValue, shareId);
    }

    private buildExtendUrl(shareId: string): string {
        return this.substituteShareId(this.extendPathTemplateValue, shareId);
    }

    /** Symfony URL-encodes `{id}` in generated paths as `%7Bid%7D`. */
    private substituteShareId(template: string, shareId: string): string {
        return template.replace(/%7Bid%7D|\{id\}/g, shareId);
    }

    private resetDisplay(): void {
        if (this.hasOutputTarget) {
            this.outputTarget.textContent = '';
            this.outputTarget.classList.add('d-none');
        }

        if (this.hasDownloadLinkTarget) {
            this.downloadLinkTarget.classList.add('d-none');
        }

        if (this.hasPasswordPanelTarget) {
            this.passwordPanelTarget.classList.add('d-none');
            this.passwordTarget.value = '';
        }

        if (this.hasKeyPanelTarget) {
            this.keyPanelTarget.classList.add('d-none');
            this.keyTarget.value = '';
        }

        if (this.hasPublicLinkTarget) {
            this.publicLinkTarget.value = '';
        }

        if (this.hasAvailabilityTarget) {
            this.availabilityTarget.textContent = '';
        }

        if (this.hasExtendPanelTarget) {
            this.extendPanelTarget.classList.add('d-none');
        }

        if (this.hasAccessLogPanelTarget) {
            this.accessLogPanelTarget.classList.add('d-none');
        }

        if (this.hasAccessLogTableTarget) {
            this.accessLogTableTarget.classList.add('d-none');
        }

        if (this.hasAccessLogBodyTarget) {
            this.accessLogBodyTarget.replaceChildren();
        }

        if (this.hasAccessLogEmptyTarget) {
            this.accessLogEmptyTarget.classList.add('d-none');
        }

        this.showDecryptControls();
        this.hideExtendSuccess();
        this.hideError();
    }

    private hideDecryptControls(): void {
        if (this.hasDecryptButtonTarget) {
            this.decryptButtonTarget.classList.add('d-none');
        }

        if (this.hasPasswordPanelTarget) {
            this.passwordPanelTarget.classList.add('d-none');
        }

        if (this.hasKeyPanelTarget) {
            this.keyPanelTarget.classList.add('d-none');
        }
    }

    private showDecryptControls(): void {
        if (this.hasDecryptButtonTarget) {
            this.decryptButtonTarget.classList.remove('d-none');
        }
    }

    private showModal(): void {
        this.modalTarget.classList.add('show');
        this.modalTarget.style.display = 'block';
        this.modalTarget.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
    }

    private hideModal(): void {
        this.modalTarget.classList.remove('show');
        this.modalTarget.style.display = 'none';
        this.modalTarget.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
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

    private showExtendSuccess(): void {
        if (!this.hasExtendSuccessTarget) {
            return;
        }

        this.extendSuccessTarget.textContent = this.extendSuccessMessageValue;
        this.extendSuccessTarget.classList.remove('d-none');
    }

    private hideExtendSuccess(): void {
        if (this.hasExtendSuccessTarget) {
            this.extendSuccessTarget.classList.add('d-none');
            this.extendSuccessTarget.textContent = '';
        }
    }
}
