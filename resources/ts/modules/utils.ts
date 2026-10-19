import { markFormDirty } from './dirtyForms';
import { siblingFocusTarget } from './dom';
import type { Slide, WPMediaAttachment } from './types';
import { removeElementWithUndo } from './undo';

/** Escape a string for safe insertion into an HTML attribute. */
export function escAttr(value: string | number): string {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function encodeSlideData(slide: Slide): string {
    const json = JSON.stringify(slide);
    const bytes = new TextEncoder().encode(json);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
}

export function previewSlideUrl(baseUrl: string, slide: Slide): string {
    const url = new URL(baseUrl, window.location.href);
    url.searchParams.set('data', encodeSlideData(slide));
    return url.href;
}

/** Preserve raw segments for the editor. */
export function splitPages(html: string, enabled = true): string[] {
    if (!enabled) return [html];
    return html.split(/<p[^>]*>\s*-{3,}\s*<\/p>|(?:^|\r?\n)[ \t]*-{3,}[ \t]*(?=\r?\n|$)/i);
}

/** Run now, then retry up to 50 times at 100ms intervals. */
export function retryUntil(task: () => boolean): void {
    if (task()) return;
    let attempts = 0;
    const timer = window.setInterval(() => {
        if (task() || ++attempts >= 50) window.clearInterval(timer);
    }, 100);
}

export function debounce(fn: () => void, ms: number): () => void {
    let timer: number | undefined;
    return () => {
        clearTimeout(timer);
        timer = window.setTimeout(fn, ms);
    };
}

/** Replace HTML tags with spaces (callers trim/collapse as needed). */
export function stripTags(html: string): string {
    return html.replace(/<[^>]+>/g, ' ');
}

export function removeImageItem(button: Element, onRemoved?: () => void, focusUndo = false): void {
    const item = button.closest('.teksttv-image-item');
    if (!(item instanceof HTMLElement)) return;
    const focusTarget = siblingFocusTarget(
        item,
        '.teksttv-remove-image',
        item.closest('.teksttv-campaign-slides-section')?.querySelector<HTMLElement>('.teksttv-campaign-add-slides') ??
            document.querySelector<HTMLElement>('#teksttv-add-images'),
    );

    // Mark dirty before detaching the item from its form.
    markFormDirty(item);
    removeElementWithUndo(item, {
        message: 'Afbeelding verwijderd.',
        focusAfterRemove: focusTarget,
        focusAfterRestore: (restored) => restored.querySelector('.teksttv-remove-image'),
        focusUndo,
        onChange: onRemoved,
    });
}

/** Defer focus until wp.media restores its opener. */
export function appendImageItems(list: Element, attachments: WPMediaAttachment[], inputName: string): void {
    const firstNewIndex = list.children.length;
    list.insertAdjacentHTML('beforeend', attachments.map((att) => imageItemHtml(att, inputName)).join(''));
    markFormDirty(list);
    window.setTimeout(() => {
        list.children[firstNewIndex]?.querySelector<HTMLButtonElement>('.teksttv-remove-image')?.focus();
    });
}

export function imageItemHtml(att: WPMediaAttachment, inputName: string): string {
    const thumbUrl = att.sizes?.thumbnail?.url ?? att.url;
    return (
        `<div class="teksttv-image-item" data-id="${escAttr(att.id)}">` +
        `<img src="${escAttr(thumbUrl)}" alt="" width="90" height="90" loading="lazy" />` +
        `<input type="hidden" name="${escAttr(inputName)}" value="${escAttr(att.id)}" />` +
        '<button type="button" class="button-link teksttv-remove-image" aria-label="Afbeelding verwijderen"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>' +
        '</div>'
    );
}
