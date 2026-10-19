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
