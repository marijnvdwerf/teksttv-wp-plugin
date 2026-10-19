import type { WPMediaAttachment, WPMediaFrame, WPMediaOptions } from './types';

/** wp.media needs Underscore even when another plugin replaces `_`. */
function ensureUnderscore(): void {
    const saved = window.wpUnderscore;
    if (!saved || typeof saved.defaults !== 'function') {
        return;
    }
    const current = window._ as { defaults?: unknown } | undefined;
    if (!current || typeof current.defaults !== 'function') {
        window._ = saved;
    }
}

function wpMedia(options: WPMediaOptions): WPMediaFrame | null {
    if (typeof wp === 'undefined' || typeof wp.media !== 'function') {
        console.error('TekstTV: wp.media is niet beschikbaar; kan de mediabibliotheek niet openen.');
        return null;
    }
    ensureUnderscore();
    return wp.media(options);
}

export function pickSingleImage(onSelect: (att: WPMediaAttachment) => void): WPMediaFrame | null {
    const frame = wpMedia({ multiple: false, library: { type: 'image' } });
    if (!frame) return null;
    frame.on('select', () => {
        const att = frame.state().get('selection').first()?.toJSON();
        if (att) onSelect(att);
    });
    frame.open();
    return frame;
}

export function pickImages(
    onSelect: (atts: WPMediaAttachment[]) => void,
    options: Omit<WPMediaOptions, 'multiple' | 'library'> = {},
): WPMediaFrame | null {
    const frame = wpMedia({ ...options, multiple: true, library: { type: 'image' } });
    if (!frame) return null;
    frame.on('select', () => {
        onSelect(frame.state().get('selection').toJSON());
    });
    frame.open();
    return frame;
}

/** Cover media interactions outside this wrapper. */
export function guardUnderscoreForMedia(): void {
    document.addEventListener('click', ensureUnderscore, true);
    document.addEventListener('focusin', ensureUnderscore, true);
    window.addEventListener('load', ensureUnderscore);
}
