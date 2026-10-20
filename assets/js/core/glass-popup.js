/**
 * Glass popup show / hide helpers shared by the board modal and confirm popups.
 */

// ── Glass popup helpers (spring pop-in / quick pop-out, Hyprland-style) ──────────
const GM_REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function gmShow(el) {
    if (!el) return;
    clearTimeout(el._gmT);
    el.classList.remove('gm-closing', 'hidden');
}
function gmHide(el) {
    if (!el || el.classList.contains('hidden') || el.classList.contains('gm-closing')) return;
    if (GM_REDUCED) { el.classList.add('hidden'); return; }
    el.classList.add('gm-closing');
    el._gmT = setTimeout(() => { el.classList.add('hidden'); el.classList.remove('gm-closing'); }, 230);
}
const gmIsOpen = (el) => !!el && !el.classList.contains('hidden') && !el.classList.contains('gm-closing');

// Keep Tab / Shift+Tab inside an open popup (call from a keydown listener while it is open)
function gmTrapTab(e, root) {
    if (e.key !== 'Tab' || !root) return;
    const items = [...root.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')]
        .filter(el => !el.disabled && el.tabIndex >= 0 && el.type !== 'hidden' && el.getClientRects().length > 0);
    if (items.length === 0) return;
    const first = items[0];
    const last  = items[items.length - 1];
    if (!root.contains(document.activeElement)) {
        e.preventDefault();
        first.focus();
    } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}
