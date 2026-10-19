/**
 * Helpers: CSRF, HTML escaping, ordinals, API URL + browser URL sync.
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
    url.searchParams.set('sheet', state.sheet);
    if (state.search) url.searchParams.set('search', state.search);
    if (state.shelf && state.shelf !== 'ALL') url.searchParams.set('shelf', state.shelf);
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
    url.searchParams.set('sheet', state.sheet);
    if (state.search) url.searchParams.set('q', state.search); else url.searchParams.delete('q');
    if (state.shelf && state.shelf !== 'ALL') url.searchParams.set('shelf', state.shelf); else url.searchParams.delete('shelf');
    if (state.board_type && state.board_type !== 'ALL') url.searchParams.set('board_type', state.board_type); else url.searchParams.delete('board_type');
    if (state.status && state.status !== 'ALL') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
    if (state.other && state.other !== 'ALL') url.searchParams.set('other', state.other); else url.searchParams.delete('other');

    window.history.replaceState({ sheet: state.sheet }, '', url.toString());

    // Update Export button href
    const exportBtn = document.getElementById('export-btn');
    if (exportBtn) {
        const expUrl = new URL(url.toString());
        expUrl.searchParams.set('export', '1');
        exportBtn.href = expUrl.toString();
    }
}
