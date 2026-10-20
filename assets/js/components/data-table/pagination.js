/**
 * Data table: pagination footer.
 */

const ICON_CHEVRON = {
    left:  'M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z',
    right: 'M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z',
};

const chevron = (dir) =>
    `<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="${ICON_CHEVRON[dir]}" clip-rule="evenodd"/></svg>`;

function pageItems(current, total) {
    const keep = new Set();
    for (let i = 1; i <= Math.min(3, total); i++) keep.add(i);
    for (let i = Math.max(1, total - 2); i <= total; i++) keep.add(i);
    for (let i = current - 1; i <= current + 1; i++) if (i >= 1 && i <= total) keep.add(i);

    const out = [];
    let prev = 0;
    [...keep].sort((a, b) => a - b).forEach(p => {
        if (p - prev === 2) out.push(prev + 1);
        else if (p - prev > 2) out.push('…');
        out.push(p);
        prev = p;
    });
    return out;
}

const FOCUS_RING = 'focus-visible:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600';
const DISABLED = 'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent';

function renderPagination() {
    if (!pagerFooter) return;

    const show = state.pageSize !== 'all' && view.status !== 'error' && view.total > 0;
    pagerFooter.classList.toggle('hidden', !show);
    if (!show) { pagerFooter.innerHTML = ''; return; }

    const { page, pageSize } = state;
    const total = view.total;
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    const from = (page - 1) * pageSize + 1;
    const to = Math.min(page * pageSize, total);
    const atFirst = page <= 1;
    const atLast = page >= totalPages;

    const active = document.activeElement;
    const focusKey = active && pagerFooter.contains(active) ? active.dataset.nav : null;

    const mobileBtn = 'relative inline-flex items-center rounded-md bg-white px-4 py-2 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 sm:hidden cursor-pointer';
    const arrowBtn = 'relative inline-flex items-center px-2 py-2 text-slate-500 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 cursor-pointer';

    const pages = pageItems(page, totalPages).map(p => {
        if (p === '…') {
            return '<span class="relative inline-flex items-center px-3.5 py-2 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-300" aria-hidden="true">…</span>';
        }
        const current = p === page;
        const cls = current
            ? 'z-10 bg-emerald-600 text-white ring-emerald-600'
            : 'text-slate-900 hover:bg-slate-50 ring-slate-300 cursor-pointer';
        return `<button type="button" data-page="${p}" data-nav="p${p}"
                    aria-label="Page ${p}" ${current ? 'aria-current="page"' : ''}
                    class="relative inline-flex items-center px-3.5 py-2 text-xs font-semibold ring-1 ring-inset ${cls} ${FOCUS_RING}">${p}</button>`;
    }).join('');

    pagerFooter.innerHTML = `
        <div class="flex items-center justify-between px-4 py-3 sm:px-6">
            <p class="hidden text-xs text-slate-700 sm:block">
                Showing <b class="font-semibold">${from}</b> to <b class="font-semibold">${to}</b> of <b class="font-semibold">${total}</b> results
            </p>
            <nav aria-label="Pagination" class="flex flex-1 justify-between sm:flex-none">
                <button type="button" data-page="${page - 1}" data-nav="prev" ${atFirst ? 'disabled' : ''}
                        class="${mobileBtn} ${FOCUS_RING} ${DISABLED}">Previous</button>
                <button type="button" data-page="${page + 1}" data-nav="next" ${atLast ? 'disabled' : ''}
                        class="${mobileBtn} ${FOCUS_RING} ${DISABLED}">Next</button>
                <div class="isolate hidden -space-x-px rounded-md shadow-sm sm:inline-flex">
                    <button type="button" data-page="${page - 1}" data-nav="prev" ${atFirst ? 'disabled' : ''}
                            aria-label="Previous page"
                            class="${arrowBtn} rounded-l-md ${FOCUS_RING} ${DISABLED}">${chevron('left')}</button>
                    ${pages}
                    <button type="button" data-page="${page + 1}" data-nav="next" ${atLast ? 'disabled' : ''}
                            aria-label="Next page"
                            class="${arrowBtn} rounded-r-md ${FOCUS_RING} ${DISABLED}">${chevron('right')}</button>
                </div>
            </nav>
        </div>`;

    if (focusKey) {
        const visible = (el) => el.offsetParent !== null && !el.disabled;
        const target =
            [...pagerFooter.querySelectorAll(`[data-nav="${focusKey}"]`)].find(visible) ||
            [...pagerFooter.querySelectorAll('[aria-current="page"]')].find(visible);
        target?.focus({ preventScroll: true });
    }
}

function goToPage(p) {
    if (state.pageSize === 'all') return;
    const totalPages = Math.max(1, Math.ceil(view.total / state.pageSize));
    const next = Math.min(Math.max(1, p), totalPages);
    if (next === state.page) return;
    state.page = next;
    loadTable(false);
}

// Listeners: page buttons
function setupPagination() {
    // Pagination clicks
    pagerFooter?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-page]');
        if (!btn || btn.disabled) return;
        goToPage(Number(btn.dataset.page));
    });
}
