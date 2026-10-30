// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { isHidden, setHidden, syncHidden } from './visibility';

describe('visibility helpers', () => {
    it('hides with the hidden attribute and an inline display none, and shows again', () => {
        const element = document.createElement('pre');

        setHidden(element, true);
        expect(element.hidden).toBe(true);
        expect(isHidden(element)).toBe(true);
        expect(element.style.getPropertyValue('display')).toBe('none');
        expect(element.style.getPropertyPriority('display')).toBe('important');

        setHidden(element, false);
        expect(element.hidden).toBe(false);
        expect(isHidden(element)).toBe(false);
        expect(element.style.getPropertyValue('display')).toBe('');
    });

    it('syncs only the hidden targets of the given controller', () => {
        const root = document.createElement('div');
        root.innerHTML = `
            <pre hidden data-ctrl-target="output"></pre>
            <p data-ctrl-target="visible"></p>
            <div hidden data-other-target="state"></div>`;

        syncHidden(root, 'ctrl');

        expect(root.querySelector<HTMLElement>('[data-ctrl-target="output"]')!.style.getPropertyValue('display')).toBe('none');
        expect(root.querySelector<HTMLElement>('[data-ctrl-target="visible"]')!.style.getPropertyValue('display')).toBe('');
        expect(root.querySelector<HTMLElement>('[data-other-target="state"]')!.style.getPropertyValue('display')).toBe('');
    });
});
