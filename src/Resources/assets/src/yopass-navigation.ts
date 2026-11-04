/** Reloads the current page (isolated so tests can replace it; happy-dom cannot reload). */
export function reloadPage(): void {
    window.location.reload();
}
