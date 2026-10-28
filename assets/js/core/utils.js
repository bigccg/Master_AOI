/**
 * Helpers: CSRF, HTML escaping, ordinals, API URL + browser URL sync (zone + sheet + filters).
 */

const getCsrf = () => window.csrfToken ?? '';

function h(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[c]);
}

function ordinal(n) {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

function buildApiUrl(overrides = {}) {
    const url = new URL('api/boards.php', window.location.href);
    url.searchParams.set('zone', state.zone);
    url.searchParams.set('sheet', state.sheet);
    if (state.search) url.searchParams.set('search', state.search);
    if (state.shelf && state.shelf !== 'ALL') url.searchParams.set('shelf', state.shelf);
    if (state.period && state.period !== 'ALL') url.searchParams.set('period', state.period);
    if (state.board_type && state.board_type !== 'ALL') url.searchParams.set('board_type', state.board_type);
    if (state.status && state.status !== 'ALL') url.searchParams.set('status', state.status);
    if (state.other && state.other !== 'ALL') url.searchParams.set('other', state.other);

    const all = state.pageSize === 'all';
    url.searchParams.set('limit', all ? 'all' : String(state.pageSize));
    if (!all) {
        url.searchParams.set('offset', String((state.page - 1) * state.pageSize));
    }

    Object.entries(overrides).forEach(([k, v]) => {
        if (v === undefined || v === null) return;
        if (v === '') url.searchParams.delete(k);
        else url.searchParams.set(k, String(v));
    });

    return url.toString();
}

function syncBrowserUrl() {
    const url = new URL(window.location.href);
    url.searchParams.set('zone', state.zone);
    url.searchParams.set('sheet', state.sheet);
    if (state.search) url.searchParams.set('q', state.search); else url.searchParams.delete('q');
    if (state.shelf && state.shelf !== 'ALL') url.searchParams.set('shelf', state.shelf); else url.searchParams.delete('shelf');
    if (state.period && state.period !== 'ALL') url.searchParams.set('period', state.period); else url.searchParams.delete('period');
    if (state.board_type && state.board_type !== 'ALL') url.searchParams.set('board_type', state.board_type); else url.searchParams.delete('board_type');
    if (state.status && state.status !== 'ALL') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
    if (state.other && state.other !== 'ALL') url.searchParams.set('other', state.other); else url.searchParams.delete('other');

    window.history.replaceState({ zone: state.zone, sheet: state.sheet }, '', url.toString());

    // Update Export button href (includes zone, sheet, and active filters)
    const exportBtn = document.getElementById('export-btn');
    if (exportBtn) {
        const expUrl = new URL(url.toString());
        expUrl.searchParams.set('export', '1');
        exportBtn.href = expUrl.toString();
    }
}

/**
 * Live sync: bring a rendered <tbody> up to date with newRows without re-rendering it, so unchanged rows stay put
 * (no entrance animation, scroll and hover kept). New or changed rows are swapped in and flash once (.is-live-flash).
 * keyOf(row) must match each <tr>'s data-id; htmlOf(row) returns one <tr>. Returns the number of rows swapped in.
 */
function livePatchRows(tbody, oldRows, newRows, keyOf, htmlOf) {
    const oldSig = new Map(oldRows.map(r => [String(keyOf(r)), JSON.stringify(r)]));
    const newKeys = new Set(newRows.map(r => String(keyOf(r))));
    const byKey  = new Map();
    for (const tr of [...tbody.children]) {   // drop rows that left the page first, so the rows below them stay put
        if (tr.dataset.id && newKeys.has(tr.dataset.id)) byKey.set(tr.dataset.id, tr);
        else tr.remove();
    }
    const tmp    = document.createElement('tbody');
    let cursor   = tbody.firstElementChild;   // everything before the cursor is already in its final place
    let changed  = 0;

    for (const row of newRows) {
        const key = String(keyOf(row));
        const existing = byKey.get(key);
        if (existing && oldSig.get(key) === JSON.stringify(row)) {
            if (existing === cursor) { cursor = cursor.nextElementSibling; continue; }
            existing.style.animation = 'none';   // re-inserting a node restarts its CSS animation
            tbody.insertBefore(existing, cursor);
            continue;
        }
        tmp.innerHTML = htmlOf(row);
        const tr = tmp.firstElementChild;
        if (!tr) continue;
        tr.style.animation = 'none';
        tr.classList.add('is-live-flash');
        tr.addEventListener('animationend', () => tr.classList.remove('is-live-flash'), { once: true });
        if (existing) {
            if (existing === cursor) cursor = cursor.nextElementSibling;
            existing.remove();
        }
        tbody.insertBefore(tr, cursor);
        changed++;
    }
    while (cursor) {   // leftovers (duplicate ids)
        const next = cursor.nextElementSibling;
        cursor.remove();
        cursor = next;
    }
    return changed;
}
