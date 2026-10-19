import { retryUntil } from '../../modules/utils';

const EDITOR_ID = 'teksttv_content';

/** Returns true once initialization starts; false lets the caller retry. */
export function initEditor(): boolean {
    if (typeof tinymce === 'undefined') return false;
    if (tinymce.get(EDITOR_ID)) return true;

    // wp_skip_init retains our toolbar/plugin config here; mceAddEditor would reuse another editor's settings.
    const config = typeof tinyMCEPreInit !== 'undefined' ? tinyMCEPreInit.mceInit[EDITOR_ID] : undefined;
    if (!config) return false;

    tinymce.init(config);
    return true;
}

/** TinyMCE needs a layout box, even when off-screen, to initialize correctly. */
export function initTeksttvEditorWhenDisplayed(): void {
    const wrap = document.getElementById(`wp-${EDITOR_ID}-wrap`);
    if (!wrap) return;

    const isDisplayed = (): boolean => wrap.getClientRects().length > 0;

    if (isDisplayed()) {
        // WordPress may expose TinyMCE asynchronously; retry briefly.
        retryUntil(initEditor);
        return;
    }

    // Observe layout changes, regardless of viewport visibility.
    const observer = new ResizeObserver(() => {
        if (!isDisplayed()) return;
        observer.disconnect();
        retryUntil(initEditor);
    });
    observer.observe(wrap);
}
