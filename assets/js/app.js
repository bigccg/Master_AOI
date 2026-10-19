/**
 * AOI Inspection & Shelf Management System
 * Full AJAX SPA Controller — Zero Page Refreshes
 * Hand-built table, dynamic KPI, live Rack Map, modal CRUD, and smooth sheet switching.
 */

// ──────────────────────────────────────────────
// State
// ──────────────────────────────────────────────

const state = {
    sheet: 'ALL',
    page: 1,
    pageSize: 10,
    search: '',
    shelf: 'ALL',
    board_type: 'ALL',
    status: 'ALL',
    other: 'ALL',
    sort: 'shelf_no',
    dir: 'asc'
};

const view = { status: 'loading', rows: [], total: 0 };
const matrixView = { status: 'loading', rows: [], total: 0 };

let tableWrapper  = null;
let pagerFooter   = null;
let matrixWrapper = null;
let reqSeq        = 0;
let inflight      = null;
let matrixReqSeq  = 0;
let matrixInflight = null;
let currentSheetData = null;

const RACK_PER_PAGE = 6;
const ALL_SIZE_INSERT_MSG = 'หน้า All Size Magazine ไม่สามารถเพิ่มข้อมูลได้ กรุณาเลือกชีตก่อน / Select a sheet before inserting a new record.';

// ──────────────────────────────────────────────
// Copy & Alerts
// ──────────────────────────────────────────────

const MSG = {
    loading:             'กำลังโหลด...',
    empty:               'ไม่พบข้อมูล',
    emptyFiltered:       'ไม่มีบอร์ดที่ตรงกับคำค้นหาหรือตัวกรองนี้ ลองใช้คำอื่น หรือล้างตัวกรอง',
    emptyNone:           'ยังไม่มีบอร์ดในชีตนี้ กด New Board Entry เพื่อเพิ่มรายการแรก',
    matrixEmptyTitle:    'ไม่พบบอร์ดที่มีข้อบกพร่อง / No Defect Boards Found',
    matrixEmptyStatusG:  'ตัวกรองเลือกเฉพาะสถานะ Master GOOD (G) จึงไม่มีรายการ Defect แสดง / Filtered by Master GOOD [G] only',
    matrixEmptyFiltered: 'ไม่มีบอร์ดสถานะ D หรือ G+D ที่ตรงกับคำค้นหาหรือตัวกรองนี้ / No D or G+D boards match the active filters',
    matrixEmptyNone:     'ยังไม่มีบอร์ดสถานะ Defect (D / G+D) ในชีตนี้ / No defect boards recorded in this sheet yet',
    error:               'โหลดข้อมูลไม่สำเร็จ',
    errorHint:           'ตรวจสอบการเชื่อมต่อ แล้วลองอีกครั้ง',
    retry:               'ลองอีกครั้ง',
    confirmDel:          (code) => `ลบรายการ ${code} ?\nการลบนี้ไม่สามารถย้อนกลับได้`,
    deleteFailed:        'ลบไม่สำเร็จ ลองอีกครั้ง',
    networkError:        'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ลองอีกครั้ง',
};

// ──────────────────────────────────────────────
// Utility Helpers
// ──────────────────────────────────────────────

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

// ──────────────────────────────────────────────
// Cell Renderers
// ──────────────────────────────────────────────

const cellText = (v) => `<span>${h(v)}</span>`;

const cellShelf = (v) =>
    `<span class="inline-block px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200 font-mono font-medium">${h(v)}</span>`;

function cellStatus(v) {
    const map = {
        G:  'bg-emerald-100 border-emerald-300 text-emerald-800 badge-glow-good',
        D:  'bg-red-100 border-red-300 text-red-700 badge-glow-defect',
        GD: 'bg-amber-100 border-amber-300 text-amber-800',
    };
    const cls = map[v];
    if (!cls) return '<span>-</span>';
    const label = v === 'GD' ? 'G+D' : v;
    return `<span class="inline-flex items-center justify-center min-w-[1.5rem] px-1.5 py-0.5 rounded-md border font-semibold ${cls}">${h(label)}</span>`;
}

function cellPcs(v) {
    if (v === null || v === undefined || v === '') return '<span>-</span>';
    if (String(v).toUpperCase() === 'OK') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 border border-emerald-300 font-medium">OK</span>';
    }
    return cellText(v);
}

// ──────────────────────────────────────────────
// Table Rendering
// ──────────────────────────────────────────────

const COLUMNS = [
    { id: 'code',      label: 'Number Code' },
    { id: 'shelf',     label: 'Shelf' },
    { id: 'product',   label: 'Product Model' },
    { id: 'side',      label: 'Side' },
    { id: 'boardType', label: 'Board Type' },
    { id: 'status',    label: 'G+D' },
    { id: 'pcs',       label: 'OTHER' },
    { id: 'magSlot',   label: 'Magazine / Slot' },
];

function rowHtml(row) {
    const [code, shelf, product, side, boardType, status, pcs, magSlot, id] = row;
    const cells = [
        cellText(code),
        cellShelf(shelf),
        cellText(product),
        cellText(side),
        cellText(boardType),
        cellStatus(status),
        cellPcs(pcs),
        cellText(magSlot),
    ];
    const colIds = ['code','shelf','product','side','boardType','status','pcs','magSlot'];
    return `<tr class="aoi-row" data-id="${h(id)}" tabindex="0">${cells.map((c, i) => `<td class="aoi-td" data-col="${colIds[i]}" title="${h(row[i] ?? '')}">${c}</td>`).join('')}</tr>`;
}

function stateRowHtml(inner) {
    return `<tr><td colspan="${COLUMNS.length}" class="px-4 py-16 text-center text-xs text-slate-500">${inner}</td></tr>`;
}

function hasActiveFilters() {
    return Boolean(state.search || (state.shelf && state.shelf !== 'ALL') || (state.board_type && state.board_type !== 'ALL') || (state.status && state.status !== 'ALL') || (state.other && state.other !== 'ALL'));
}

function bodyHtml() {
    if (view.status === 'loading') {
        return stateRowHtml(`
            <div class="flex flex-col items-center gap-2" role="status">
                <svg class="h-5 w-5 animate-spin text-emerald-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span>${h(MSG.loading)}</span>
            </div>`);
    }
    if (view.status === 'error') {
        return stateRowHtml(`
            <div class="flex flex-col items-center gap-2" role="alert">
                <span class="text-sm font-medium text-red-700">${h(MSG.error)}</span>
                <span>${h(MSG.errorHint)}</span>
                <button type="button" data-action="retry"
                        class="mt-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 cursor-pointer">
                    ${h(MSG.retry)}
                </button>
            </div>`);
    }
    if (view.rows.length === 0) {
        return stateRowHtml(`
            <div class="flex flex-col items-center gap-1">
                <span class="text-sm font-medium text-slate-700">${h(MSG.empty)}</span>
                <span>${h(hasActiveFilters() ? MSG.emptyFiltered : MSG.emptyNone)}</span>
            </div>`);
    }
    return view.rows.map(rowHtml).join('');
}

function renderTable() {
    if (!tableWrapper) return;
    const head = COLUMNS
        .map(c => `<th scope="col" class="aoi-th" data-col="${c.id}">${h(c.label)}</th>`)
        .join('');
    const hasRows = view.status === 'ready' && view.rows.length > 0;
    tableWrapper.innerHTML = `
        <div class="aoi-table-scroll" data-has-rows="${hasRows ? '1' : '0'}">
            <table class="aoi-table">
                <caption class="sr-only">Board records</caption>
                <thead><tr>${head}</tr></thead>
                <tbody class="aoi-tbody">${bodyHtml()}</tbody>
            </table>
        </div>`;
}

// ──────────────────────────────────────────────
// Pagination
// ──────────────────────────────────────────────

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

// ──────────────────────────────────────────────
// Data Loading (AJAX)
// ──────────────────────────────────────────────

function setBusy(on) {
    tableWrapper?.setAttribute('aria-busy', on ? 'true' : 'false');
}

async function loadTable(refreshMatrix = true) {
    if (refreshMatrix) {
        syncShelfViewMode();
        if (state.sheet === 'ALL') {
            loadDefectMatrix();
        }
    }

    const seq = ++reqSeq;
    inflight?.abort();
    const ctrl = inflight = new AbortController();

    setBusy(true);
    if (view.rows.length === 0) {
        view.status = 'loading';
        renderTable();
    }
    renderPagination();

    const url = buildApiUrl();

    let json;
    try {
        const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl.signal });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        json = await res.json();
        if (!json || !Array.isArray(json.data) || !Number.isFinite(Number(json.total))) {
            throw new Error('Unexpected response shape');
        }
    } catch (err) {
        if (err.name === 'AbortError' || seq !== reqSeq) return;
        console.error(err);
        view.status = 'error';
        view.rows = [];
        view.total = 0;
        setBusy(false);
        renderTable();
        renderPagination();
        return;
    }
    if (seq !== reqSeq) return;

    const total = Number(json.total);

    // Adjust page if current page exceeded
    if (state.pageSize !== 'all' && total > 0 && state.page > Math.ceil(total / state.pageSize)) {
        state.page = Math.ceil(total / state.pageSize);
        loadTable(false);
        return;
    }

    view.status = 'ready';
    view.rows = json.data;
    view.total = total;
    setBusy(false);
    renderTable();
    renderPagination();

    document.dispatchEvent(new CustomEvent('aoi:loaded', { detail: { total, search: state.search } }));

    if (json.truncated) {
        showFlash('warn', `Showing first ${json.data.length} of ${total} — refine your search`);
    }
}

function reloadTable() {
    return loadTable();
}
window.reloadTable = reloadTable;

// ──────────────────────────────────────────────
// Defect Matrix (shown when Shelf = ALL)
// ──────────────────────────────────────────────

function getDefectEntries() {
    const labels = currentSheetData?.defect_labels || window.DEFECT_LABELS || {};
    return Object.entries(labels)
        .map(([k, v]) => [Number(k), String(v)])
        .filter(([k]) => Number.isFinite(k))
        .sort((a, b) => a[0] - b[0]);
}

function syncShelfViewMode() {
    const isAllZones = state.sheet === 'ALL';
    const rackSection = document.getElementById('rack-map');
    const matrixSection = document.getElementById('defect-matrix');
    if (rackSection) rackSection.classList.toggle('hidden', isAllZones);
    if (matrixSection) matrixSection.classList.toggle('hidden', !isAllZones);
    if (isAllZones) requestAnimationFrame(syncMatrixStickyOffsets);

    // All Size Magazine is view-only for new records
    const newBtn = document.getElementById('new-entry-btn');
    if (newBtn) {
        newBtn.disabled = isAllZones;
        newBtn.title = isAllZones ? ALL_SIZE_INSERT_MSG : '';
    }
    return isAllZones;
}
window.syncShelfViewMode = syncShelfViewMode;

function buildMatrixApiUrl() {
    const url = new URL('api/boards.php', window.location.href);
    url.searchParams.set('sheet', state.sheet);
    url.searchParams.set('matrix', '1');
    if (state.search) url.searchParams.set('search', state.search);
    if (state.shelf && state.shelf !== 'ALL') url.searchParams.set('shelf', state.shelf);
    if (state.board_type && state.board_type !== 'ALL') url.searchParams.set('board_type', state.board_type);
    if (state.status && state.status !== 'ALL') url.searchParams.set('status', state.status);
    if (state.other && state.other !== 'ALL') url.searchParams.set('other', state.other);
    return url.toString();
}

function setMatrixBusy(on) {
    matrixWrapper?.setAttribute('aria-busy', on ? 'true' : 'false');
}

async function loadDefectMatrix() {
    const isAll = syncShelfViewMode();
    if (!isAll) {
        matrixInflight?.abort();
        return;
    }

    const seq = ++matrixReqSeq;
    matrixInflight?.abort();
    const ctrl = matrixInflight = new AbortController();

    setMatrixBusy(true);
    if (matrixView.rows.length === 0) {
        matrixView.status = 'loading';
        renderDefectMatrix();
    }

    let json;
    try {
        const res = await fetch(buildMatrixApiUrl(), { headers: { Accept: 'application/json' }, signal: ctrl.signal });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        json = await res.json();
        if (!json || !Array.isArray(json.data)) {
            throw new Error('Unexpected matrix response shape');
        }
    } catch (err) {
        if (err.name === 'AbortError' || seq !== matrixReqSeq) return;
        console.error('Failed to load Defect Matrix:', err);
        matrixView.status = 'error';
        matrixView.rows = [];
        matrixView.total = 0;
        setMatrixBusy(false);
        renderDefectMatrix();
        return;
    }
    if (seq !== matrixReqSeq) return;

    if (json.defect_labels) {
        window.DEFECT_LABELS = json.defect_labels;
    }

    matrixView.status = 'ready';
    matrixView.rows = json.data;
    matrixView.total = Number(json.total ?? json.data.length);
    setMatrixBusy(false);
    renderDefectMatrix();
}
window.loadDefectMatrix = loadDefectMatrix;

function renderDefectMatrix() {
    if (!matrixWrapper) {
        matrixWrapper = document.getElementById('defect-matrix-wrapper');
    }
    if (!matrixWrapper) return;

    const defectEntries = getDefectEntries();
    const totalCols = 4 + defectEntries.length + 1; // Code, Shelf, Product, G+D + 13 defects + Other

    const counterEl = document.getElementById('defect-matrix-counter');
    if (counterEl && matrixView.status === 'ready') {
        counterEl.textContent = `${matrixView.rows.length} rows`;
    }

    const headHtml = [
        `<th scope="col" class="dm-th dm-sticky-col-1" data-col="code" title="Number Code">Number Code</th>`,
        `<th scope="col" class="dm-th dm-sticky-col-2" data-col="shelf" title="Shelf">Shelf</th>`,
        `<th scope="col" class="dm-th" data-col="product" title="Product Model">Product Model</th>`,
        `<th scope="col" class="dm-th" data-col="status" title="Board Classification (G+D)">G+D</th>`,
        ...defectEntries.map(([code, label]) =>
            `<th scope="col" class="dm-th" data-col="defect-${code}" data-defect-code="${code}" title="${h(`${code} · ${label}`)}">${h(code)}</th>`
        ),
        `<th scope="col" class="dm-th" data-col="defect-other" data-defect-code="OTHER" title="Other · ข้อบกพร่องอื่นๆ">Other</th>`
    ].join('');

    const counts = { OTHER: 0 };
    defectEntries.forEach(([code]) => { counts[code] = 0; });

    let tbodyInner = '';
    if (matrixView.status === 'loading') {
        tbodyInner = `<tr><td colspan="${totalCols}" class="px-4 py-14 text-center text-xs text-slate-500">
            <div class="flex flex-col items-center gap-2" role="status">
                <svg class="h-5 w-5 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span>${h(MSG.loading)}</span>
            </div>
        </td></tr>`;
    } else if (matrixView.status === 'error') {
        tbodyInner = `<tr><td colspan="${totalCols}" class="px-4 py-14 text-center text-xs text-slate-500">
            <div class="flex flex-col items-center gap-2" role="alert">
                <span class="text-sm font-medium text-red-700">${h(MSG.error)}</span>
                <span>${h(MSG.errorHint)}</span>
                <button type="button" data-action="retry-matrix"
                        class="no-print mt-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">
                    ${h(MSG.retry)}
                </button>
            </div>
        </td></tr>`;
    } else if (matrixView.rows.length === 0) {
        const subMsg = state.status === 'G'
            ? MSG.matrixEmptyStatusG
            : (hasActiveFilters() ? MSG.matrixEmptyFiltered : MSG.matrixEmptyNone);
        tbodyInner = `<tr><td colspan="${totalCols}" class="px-4 py-14 text-center text-xs text-slate-500">
            <div class="flex flex-col items-center gap-1">
                <span class="text-sm font-semibold text-slate-700">${h(MSG.matrixEmptyTitle)}</span>
                <span>${h(subMsg)}</span>
            </div>
        </td></tr>`;
    } else {
        tbodyInner = matrixView.rows.map(r => {
            const codeSet = new Set((Array.isArray(r.defects) ? r.defects : []).map(Number));
            const otherText = String(r.other_text ?? '').trim();
            const hasOther = Boolean(r.other || otherText !== '');

            const rowDefectKeys = [];
            defectEntries.forEach(([code]) => {
                if (codeSet.has(code)) {
                    counts[code] = (counts[code] || 0) + 1;
                    rowDefectKeys.push(String(code));
                }
            });
            if (hasOther) {
                counts.OTHER += 1;
                rowDefectKeys.push('OTHER');
            }

            const defectCells = defectEntries.map(([code, label]) => {
                if (codeSet.has(code)) {
                    const tip = `${code} · ${label} (${r.shelf} · ${r.code})`;
                    return `<td class="dm-td dm-td-defect is-hit" data-col="defect-${code}" data-defect-code="${code}" title="${h(tip)}">` +
                        `<span class="dm-hit-badge" aria-label="${h(`${code} ${label}`)}"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>` +
                        `</td>`;
                }
                return `<td class="dm-td dm-td-defect" data-col="defect-${code}" data-defect-code="${code}"><span class="dm-miss-dash" aria-hidden="true">-</span></td>`;
            }).join('');

            let otherCell = `<td class="dm-td dm-td-defect" data-col="defect-other" data-defect-code="OTHER"><span class="dm-miss-dash" aria-hidden="true">-</span></td>`;
            if (hasOther) {
                const otherTip = otherText !== '' ? otherText : 'OTHER (ไม่ได้ระบุรายละเอียด)';
                const iconCls = otherText !== '' ? 'fa-comment-dots' : 'fa-circle-check';
                otherCell = `<td class="dm-td dm-td-defect is-hit" data-col="defect-other" data-defect-code="OTHER" title="${h(otherTip)}">` +
                    `<span class="dm-hit-badge is-other" title="${h(otherTip)}" aria-label="${h(otherTip)}"><i class="fa-solid ${iconCls}" aria-hidden="true"></i></span>` +
                    `</td>`;
            }

            return `<tr class="dm-row" data-id="${h(r.id)}" data-defects="${h(rowDefectKeys.join(','))}" tabindex="0">` +
                `<td class="dm-td dm-sticky-col-1" data-col="code" title="${h(r.code)}">${cellText(r.code)}</td>` +
                `<td class="dm-td dm-sticky-col-2" data-col="shelf" title="${h(r.shelf)}">${cellShelf(r.shelf)}</td>` +
                `<td class="dm-td" data-col="product" title="${h(r.product)}">${cellText(r.product)}</td>` +
                `<td class="dm-td" data-col="status" title="${h(r.status === 'GD' ? 'G+D' : r.status)}">${cellStatus(r.status)}</td>` +
                defectCells +
                otherCell +
                `</tr>`;
        }).join('');
    }

    const tfootDefectCells = defectEntries.map(([code, label]) => {
        const c = counts[code] || 0;
        return `<td class="dm-tfoot-td font-mono ${c > 0 ? 'is-nonzero' : 'is-zero'}" data-col="defect-${code}" data-defect-code="${code}" title="${h(`${code} · ${label}: ${c}`)}">${c}</td>`;
    }).join('');
    const cOther = counts.OTHER || 0;
    const tfootOtherCell = `<td class="dm-tfoot-td font-mono ${cOther > 0 ? 'is-nonzero' : 'is-zero'}" data-col="defect-other" data-defect-code="OTHER" title="${h(`Other: ${cOther}`)}">${cOther}</td>`;

    const hasRows = matrixView.status === 'ready' && matrixView.rows.length > 0;
    matrixWrapper.innerHTML = `
        <div class="dm-table-scroll" data-has-rows="${hasRows ? '1' : '0'}">
            <table class="dm-table">
                <caption class="sr-only">Defect Matrix All Zones</caption>
                <thead><tr>${headHtml}</tr></thead>
                <tbody class="dm-tbody">${tbodyInner}</tbody>
                <tfoot>
                    <tr>
                        <td class="dm-tfoot-td dm-sticky-col-1 font-mono text-slate-800" title="Total (รวมจำนวนบอร์ด)">Total</td>
                        <td class="dm-tfoot-td dm-sticky-col-2 font-mono text-blue-700">${matrixView.rows.length}</td>
                        <td class="dm-tfoot-td text-right pr-2 text-[11px] font-semibold text-slate-600" title="รวมจำนวนบอร์ดต่อรหัส Defect">รวม Defect:</td>
                        <td class="dm-tfoot-td text-center text-[11px] font-semibold text-slate-500">-</td>
                        ${tfootDefectCells}
                        ${tfootOtherCell}
                    </tr>
                </tfoot>
            </table>
        </div>`;

    syncMatrixStickyOffsets();
}

function syncMatrixStickyOffsets() {
    if (!matrixWrapper) return;
    const table = matrixWrapper.querySelector('.dm-table');
    const col1Th = matrixWrapper.querySelector('thead .dm-sticky-col-1');
    if (table && col1Th) {
        const w = col1Th.getBoundingClientRect().width;
        if (w > 0) {
            table.style.setProperty('--dm-col1-w', `${Math.round(w)}px`);
        }
    }
}
window.addEventListener('resize', syncMatrixStickyOffsets);

function clearMatrixHighlights() {
    if (!matrixWrapper) return;
    matrixWrapper.querySelectorAll('.is-col-active, .is-col-cursor').forEach(el => {
        el.classList.remove('is-col-active', 'is-col-cursor');
    });
    document.querySelectorAll('#defect-matrix-legend .dm-legend-item.is-legend-active').forEach(el => {
        el.classList.remove('is-legend-active');
    });
}

function highlightMatrixRowAndCol(tr, td) {
    if (!matrixWrapper) return;
    const defectSet = new Set((tr?.dataset.defects || '').split(',').filter(Boolean));
    const cursorCol = td?.dataset.col || '';
    const cursorDefect = td?.dataset.defectCode || '';

    matrixWrapper.querySelectorAll('thead .dm-th').forEach(th => {
        const dCode = th.dataset.defectCode || '';
        const isDefectMatch = Boolean(dCode && (defectSet.has(dCode) || dCode === cursorDefect));
        const isCursorMatch = Boolean(cursorCol && th.dataset.col === cursorCol);
        th.classList.toggle('is-col-active', isDefectMatch);
        th.classList.toggle('is-col-cursor', isCursorMatch && !isDefectMatch);
    });

    matrixWrapper.querySelectorAll('tfoot .dm-tfoot-td[data-defect-code]').forEach(ft => {
        const dCode = ft.dataset.defectCode || '';
        ft.classList.toggle('is-col-active', Boolean(dCode && (defectSet.has(dCode) || dCode === cursorDefect)));
    });

    document.querySelectorAll('#defect-matrix-legend .dm-legend-item[data-legend-code]').forEach(chip => {
        const lCode = chip.dataset.legendCode || '';
        chip.classList.toggle('is-legend-active', Boolean(lCode && (defectSet.has(lCode) || lCode === cursorDefect)));
    });
}

// ──────────────────────────────────────────────
// Sheet Data & Live Stats (KPI, Tabs, Rack Map)
// ──────────────────────────────────────────────

async function refreshSheetData(targetSheet = state.sheet) {
    try {
        const res = await fetch(`api/sheet_data.php?sheet=${encodeURIComponent(targetSheet)}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (!json.success || !json.data) throw new Error('Invalid sheet data');

        currentSheetData = json.data;
        if (json.data.defect_labels) {
            window.DEFECT_LABELS = json.data.defect_labels;
        }
        renderKpi(json.data.kpi, json.data.shelf_prefix);
        renderTabCounts(json.data.tab_counts);
        renderShelfFilterOptions(json.data.mag_options);
        renderRackMap(json.data.rack, json.data.shelf_prefix);
        updateModalFreeShelves(json.data.free_by_mag, json.data.free_shelves, json.data.rack.capacity, json.data.shelf_prefix, json.data.rack.is_max);

        window.currentNextCode = json.data.next_code;
        const codeHidden = document.getElementById('modal-code');
        if (codeHidden && document.getElementById('modal-id')?.value === '0') {
            codeHidden.value = json.data.next_code || '';
        }
    } catch (err) {
        console.error('Failed to refresh sheet data:', err);
    }
}
window.refreshSheetData = refreshSheetData;

function renderKpi(kpi, sp) {
    const elTotal = document.getElementById('kpi-total');
    const elRange = document.getElementById('kpi-shelf-range');
    const elGood = document.getElementById('kpi-good');
    const elPctGood = document.getElementById('kpi-pct-good');
    const elDefect = document.getElementById('kpi-defect');
    const elMixed = document.getElementById('kpi-mixed');
    const elUtilTitle = document.getElementById('kpi-util-title');
    const elUtilText = document.getElementById('kpi-util-text');
    const elUtilBar = document.getElementById('kpi-util-bar');

    if (elTotal) elTotal.textContent = kpi.total;
    if (elRange) elRange.textContent = kpi.shelf_range;
    if (elGood) elGood.textContent = kpi.g;
    if (elPctGood) elPctGood.textContent = `${kpi.pct_g}% of section`;
    if (elDefect) elDefect.textContent = kpi.d;
    if (elMixed) elMixed.textContent = kpi.gd;
    if (elUtilTitle) {
        const utilPrefix = (sp === 'ALL' || state.sheet === 'ALL') ? 'All Zones' : `Rack ${h(sp)}`;
        elUtilTitle.innerHTML = `${utilPrefix} Utilization <span class="text-slate-400">(${kpi.used}/${kpi.capacity})</span>`;
    }
    if (elUtilText) elUtilText.textContent = `${kpi.util}%`;
    if (elUtilBar) elUtilBar.style.width = `${kpi.util}%`;
}

function renderTabCounts(tabCounts) {
    document.querySelectorAll('#sheet-tabs a[data-sheet]').forEach(a => {
        const sh = a.dataset.sheet;
        const countSpan = a.querySelector('.tab-count');
        const n = tabCounts[sh] ?? 0;
        if (countSpan) {
            countSpan.textContent = n;
            countSpan.classList.toggle('opacity-50', n === 0);
        }
    });
}

function updateTabActiveClasses(activeSheet) {
    document.querySelectorAll('#sheet-tabs a[data-sheet]').forEach(a => {
        const isActive = a.dataset.sheet === activeSheet;
        if (isActive) {
            a.className = 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5';
        } else {
            a.className = 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5';
        }
    });
}

function renderShelfFilterOptions(magOptions) {
    const sel = document.getElementById('filter-shelf');
    if (!sel) return;
    const validValues = new Set(['ALL', ...magOptions.map(o => o.value)]);
    if (state.shelf !== 'ALL' && !validValues.has(state.shelf)) {
        state.shelf = 'ALL';
        syncBrowserUrl();
        if (state.sheet === 'ALL') {
            loadDefectMatrix();
        }
    }
    const curVal = state.shelf;
    let html = '<option value="ALL">ทุกชั้นวาง</option>';
    magOptions.forEach(opt => {
        html += `<option value="${h(opt.value)}" ${curVal === opt.value ? 'selected' : ''}>${h(opt.label)}</option>`;
    });
    sel.innerHTML = html;
    syncShelfViewMode();
}

// ──────────────────────────────────────────────
// Rack Map Dynamic Rendering
// ──────────────────────────────────────────────

let currentRackPage = 1;

function renderRackMap(rack, sp) {
    const rackSection = document.getElementById('rack-map');
    if (!rackSection) return;

    // Header counter
    const counter = rackSection.querySelector('#rack-header-counter');
    if (counter) counter.textContent = `${rack.used_shelves}/${rack.capacity}`;

    const titleEl = rackSection.querySelector('#rack-header-title');
    if (titleEl) titleEl.textContent = `Shelf Rack Physical Map: Rack ${sp} (ตารางชั้นวางจริง)`;

    // Max Magazine banner
    let maxBanner = document.getElementById('rack-max-banner');
    if (rack.is_max) {
        if (!maxBanner) {
            maxBanner = document.createElement('div');
            maxBanner.id = 'rack-max-banner';
            rackSection.insertBefore(maxBanner, document.getElementById('rack-cards-container'));
        }
        maxBanner.className = 'no-print mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 flex flex-wrap items-center justify-between gap-2 text-xs';
        maxBanner.innerHTML = `
            <span class="font-bold text-red-700 flex items-center gap-1.5">
              <i class="fa-solid fa-triangle-exclamation"></i> Magazine is Max
              <span class="font-normal text-red-600">— Shelf ${h(sp)}1-${h(sp + rack.capacity)} เต็มทั้งหมด ไม่สามารถเลือก Shelf ใหม่ได้</span>
            </span>
            ${rack.can_add_mag ? `
              <div class="flex items-center gap-2">
                <button type="button" id="add-mag-btn" onclick="toggleAddMag(true)"
                        class="px-3 py-1 rounded-md bg-white border border-red-300 text-red-700 font-semibold hover:bg-red-100 cursor-pointer">
                  <i class="fa-solid fa-plus mr-1"></i>Add Magazine
                </button>
                <form id="add-mag-confirm" onsubmit="handleAddMagazine(event)" class="hidden items-center gap-2">
                  <input type="hidden" name="csrf" value="${h(getCsrf())}"/>
                  <input type="hidden" name="action" value="add_magazine"/>
                  <input type="hidden" name="magazines" value="${rack.mag_count}"/>
                  <span class="text-slate-700">เพิ่ม <b>Magazine ${ordinal(rack.mag_count + 1)}</b> (Shelf ${rack.next_range[0]}-${rack.next_range[1]}) อีก 50 Shelf ?</span>
                  <button type="submit" class="px-3 py-1 rounded-md bg-red-600 text-white font-semibold hover:bg-red-700 cursor-pointer">Confirm</button>
                  <button type="button" onclick="toggleAddMag(false)" class="px-3 py-1 rounded-md bg-white border border-slate-300 text-slate-600 hover:bg-slate-100 cursor-pointer">Cancel</button>
                </form>
              </div>` : `<span class="text-slate-600">ครบจำนวนสูงสุดแล้ว</span>`}
        `;
    } else if (maxBanner) {
        maxBanner.remove();
    }

    // Render Magazines Cards
    const cardsContainer = document.getElementById('rack-cards-container');
    if (!cardsContainer) return;

    // Other = Bare / Bad Mark has no G / D / G+D, so it gets its own color (keyed by pcs_status)
    const cellCls = {
        G:  'bg-emerald-600 text-white',
        D:  'bg-red-600 text-white',
        GD: 'bg-amber-400 text-amber-950',
        'Bare':     'bg-sky-500 text-white',
        'Bad Mark': 'bg-violet-600 text-white'
    };
    const statusLabel = { G: 'G', D: 'D', GD: 'G+D' };

    let cardsHtml = '';
    rack.magazines.forEach(m => {
        let gridHtml = '';
        m.shelves.forEach(s => {
            if (s.occupied) {
                const d = s.data;
                const key = (window.OTHER_NO_CLASS || []).includes(d.pcs_status) ? d.pcs_status : s.status;
                const tip = `${s.shelf} · ${d.code} · ${d.product} · ${statusLabel[key] || key}`;
                gridHtml += `
                    <button type="button" data-action="edit" data-id="${d.id}" title="${h(tip)}"
                            class="h-7 flex items-center justify-center rounded text-[11px] font-semibold ${cellCls[key] || 'bg-slate-500 text-white'} hover:ring-2 hover:ring-blue-400 hover:ring-offset-1 cursor-pointer transition">${h(s.shelf)}</button>
                `;
            } else {
                gridHtml += `
                    <button type="button" data-shelf="${h(s.shelf)}" title="${h(s.shelf)} · Empty"
                            class="h-7 flex items-center justify-center rounded text-[11px] bg-slate-100 text-slate-400 border border-slate-200 hover:border-blue-300 hover:text-blue-500 cursor-pointer transition">${h(s.shelf)}</button>
                `;
            }
        });

        cardsHtml += `
            <div data-mag="${m.mag}" class="rack-card bg-white rounded-md border ${m.full ? 'border-red-200' : 'border-slate-200'} p-2.5 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-[11px] font-bold text-slate-700">${h(m.title)}</h3>
                    <span class="text-[10px] font-mono ${m.full ? 'text-red-600 font-bold' : 'text-slate-500'}">${m.used}/50${m.full ? ' · Full' : ''}</span>
                </div>
                <div class="grid grid-cols-10 gap-1 font-mono text-center">
                    ${gridHtml}
                </div>
            </div>
        `;
    });

    cardsContainer.innerHTML = cardsHtml;
    renderRackPager(rack.mag_count);
}

function renderRackPager(totalMagazines) {
    const box = document.getElementById('rack-pager');
    if (!box) return;

    const per = RACK_PER_PAGE;
    const pages = Math.ceil(totalMagazines / per);

    if (pages <= 1) {
        box.classList.add('hidden');
        document.querySelectorAll('#rack-cards-container .rack-card').forEach(c => c.classList.remove('hidden'));
        return;
    }

    if (currentRackPage > pages) currentRackPage = pages;
    const page = currentRackPage;
    const a = (page - 1) * per + 1;
    const b = Math.min(page * per, totalMagazines);

    document.querySelectorAll('#rack-cards-container .rack-card').forEach(c => {
        const magNo = +c.dataset.mag;
        c.classList.toggle('hidden', magNo < a || magNo > b);
    });

    const btn = 'relative inline-flex items-center px-3.5 py-2 text-xs font-semibold ring-1 ring-inset focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer';
    const arrow = (d) => `<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="${d ? 'M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z' : 'M12.79 5.23a.75.75 0 0 1-.02 1.06L8.83 10l3.94 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z'}" clip-rule="evenodd"/></svg>`;

    let nums = '';
    for (let i = 1; i <= pages; i++) {
        const cur = i === page;
        nums += `<button type="button" data-p="${i}" ${cur ? 'aria-current="page"' : ''} aria-label="Magazine page ${i}" class="${btn} ${cur ? 'z-10 bg-emerald-600 text-white ring-emerald-600' : 'text-slate-900 hover:bg-slate-50 ring-slate-300'}">${i}</button>`;
    }

    box.innerHTML = `
        <div class="flex items-center justify-between border-t border-slate-200 pt-3">
            <p class="text-xs text-slate-700">Showing Magazine <b class="font-semibold">${a}</b> to <b class="font-semibold">${b}</b> of <b class="font-semibold">${totalMagazines}</b></p>
            <nav aria-label="Rack map pagination" class="isolate inline-flex -space-x-px rounded-md shadow-sm">
                <button type="button" data-p="${page - 1}" ${page <= 1 ? 'disabled' : ''} aria-label="Previous page" class="${btn} rounded-l-md text-slate-500 ring-slate-300 hover:bg-slate-50">${arrow(0)}</button>${nums}
                <button type="button" data-p="${page + 1}" ${page >= pages ? 'disabled' : ''} aria-label="Next page" class="${btn} rounded-r-md text-slate-500 ring-slate-300 hover:bg-slate-50">${arrow(1)}</button>
            </nav>
        </div>
    `;
    box.classList.remove('hidden');
}

// ──────────────────────────────────────────────
// Sheet Switching (100% AJAX, Zero Reload)
// ──────────────────────────────────────────────

async function switchSheet(newSheet) {
    if (!newSheet || newSheet === state.sheet) return;

    state.sheet = newSheet;
    state.page = 1;
    state.shelf = 'ALL';

    // Update filter dropdowns
    const filterShelf = document.getElementById('filter-shelf');
    if (filterShelf) filterShelf.value = 'ALL';

    const filterSheetHidden = document.getElementById('filter-sheet');
    if (filterSheetHidden) filterSheetHidden.value = newSheet;

    updateTabActiveClasses(newSheet);
    syncBrowserUrl();

    // Parallel fetch for table and sheet data
    await Promise.all([
        loadTable(),
        refreshSheetData(newSheet)
    ]);
}
window.switchSheet = switchSheet;

// ──────────────────────────────────────────────
// Modal Controls (Add / Edit)
// ──────────────────────────────────────────────

const modal = document.getElementById('add-entry-modal');

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

// INSERT / EDIT mode pill + breadcrumb + accent colour
function gmSetMode(mode, code) {
    const win = document.getElementById('gm-window');
    win?.classList.remove('gm-ok');
    win?.classList.toggle('is-edit', mode === 'edit');
    const set = (id, t) => { const n = document.getElementById(id); if (n) n.textContent = t; };
    set('gm-mode-text', mode === 'edit' ? 'EDIT' : 'INSERT');
    set('gm-path-tail', mode === 'edit' ? '/edit/' + (code || '') : '/new');
    set('gm-path-sheet', state.sheet);
    set('gm-st-sheet', state.sheet);
}

// required-fields progress in the statusline (shelf, product, side, board type, board classification unless locked)
function gmSyncProgress() {
    const ids = ['modal-shelf', 'modal-product', 'modal-side', 'modal-board-type'];
    const statusChecked = !!document.querySelector('#board-form input[name="board_status"]:checked');
    const statusLocked = !!document.querySelector('#board-form input[name="board_status"]:disabled');
    const total = ids.length + (statusLocked ? 0 : 1);
    const done = ids.filter(id => (document.getElementById(id)?.value || '').trim() !== '').length + (statusChecked ? 1 : 0);
    const bar = document.getElementById('gm-prog-bar');
    const txt = document.getElementById('gm-prog-text');
    if (bar) bar.style.width = (done / total * 100) + '%';
    if (txt) txt.textContent = done + '/' + total;
}

// Other = Bare / Bad Mark (window.OTHER_NO_CLASS) -> Board Classification is cleared and locked, defects dropped with it
function syncClassificationLock() {
    const locked = (window.OTHER_NO_CLASS || []).includes(document.getElementById('modal-pcs')?.value);
    document.querySelectorAll('#board-form input[name="board_status"]').forEach(r => {
        r.disabled = locked;
        if (locked) r.checked = false;
    });
    if (locked) {
        clearDefects();
        closeDefectPanel(false);
    }
    return locked;
}

function gmSyncDefectChip() {
    // Chip removed per UX request
}
window.gmSyncDefectChip = gmSyncDefectChip;

// cursor spotlight + ripple + live progress (wired once)
(function gmInit() {
    document.querySelectorAll('.gm-window').forEach(win => {
        win.addEventListener('pointermove', e => {
            const r = win.getBoundingClientRect();
            win.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            win.style.setProperty('--my', (e.clientY - r.top) + 'px');
        });
    });
    document.addEventListener('pointerdown', e => {
        const btn = e.target.closest?.('.gm-btn');
        if (!btn || btn.disabled) return;
        const r = btn.getBoundingClientRect();
        const d = Math.max(r.width, r.height) * 2;
        const rip = document.createElement('span');
        rip.className = 'gm-ripple';
        rip.style.cssText = `width:${d}px;height:${d}px;left:${e.clientX - r.left - d / 2}px;top:${e.clientY - r.top - d / 2}px`;
        btn.appendChild(rip);
        setTimeout(() => rip.remove(), 650);
    });
    const form = document.getElementById('board-form');
    form?.addEventListener('input', gmSyncProgress);
    form?.addEventListener('change', (e) => {
        if (e.target.id === 'modal-pcs') syncClassificationLock();
        gmSyncProgress();
        if (e.target.name === 'board_status') gmSyncDefectChip();
    });
})();

function updateModalFreeShelves(freeByMag, freeShelves, capacity, sp, isMax, selectedShelf = null) {
    const sel = document.getElementById('modal-shelf');
    const info = document.getElementById('modal-shelf-info');
    if (!sel) return;

    if (isMax && !selectedShelf) {
        sel.disabled = true;
        sel.className = 'gm-input mono is-max';
        sel.innerHTML = '<option value="">Magazine is Max</option>';
        if (info) info.innerHTML = '<span class="font-semibold text-red-600">Magazine is Max — เพิ่ม Magazine ได้ที่ Rack Map (กด Confirm)</span>';
        return;
    }

    sel.disabled = false;
    sel.className = 'gm-input mono';

    let html = '<option value="" disabled ' + (selectedShelf ? '' : 'selected') + '>เลือก Shelf...</option>';

    // Grouping
    for (const [mg, list] of Object.entries(freeByMag)) {
        html += `<optgroup label="Magazine ${ordinal(Number(mg))} = ${(Number(mg) - 1) * 50 + 1}-${Number(mg) * 50}">`;
        let includedSelected = false;
        list.forEach(item => {
            const isSel = selectedShelf === item.shelf;
            if (isSel) includedSelected = true;
            html += `<option value="${h(item.shelf)}" ${isSel ? 'selected' : ''}>${h(item.shelf)}</option>`;
        });
        // If editing and selected shelf belongs to this magazine but was occupied
        if (selectedShelf && !includedSelected) {
            const match = selectedShelf.match(/\d+$/);
            if (match) {
                const sNo = parseInt(match[0], 10);
                if (Math.floor((sNo - 1) / 50) + 1 === Number(mg)) {
                    html += `<option value="${h(selectedShelf)}" selected>${h(selectedShelf)} (Current)</option>`;
                }
            }
        }
        html += `</optgroup>`;
    }

    sel.innerHTML = html;
    if (info) {
        info.innerHTML = `แสดงเฉพาะ Shelf ว่าง (${freeShelves.length}/${capacity})`;
    }
}

function clearModalErrors() {
    const errBox = document.getElementById('modal-error-box');
    if (errBox) {
        errBox.classList.add('hidden');
        errBox.innerHTML = '';
    }
}

function showModalErrors(errors) {
    const errBox = document.getElementById('modal-error-box');
    if (!errBox) return;
    errBox.innerHTML = errors.map(e => `<div><i class="fa-solid fa-circle-exclamation mr-1"></i>${h(e)}</div>`).join('');
    errBox.classList.remove('hidden');
}

function setModalDeleteVisible(visible) {
    const delBtn = document.getElementById('modal-delete-btn');
    if (!delBtn) return;
    delBtn.hidden = !visible;
    delBtn.classList.toggle('hidden', !visible);
}

let activeModalSheet = null;

function openAddModal() {
    if (state.sheet === 'ALL') {
        showFlash('err', ALL_SIZE_INSERT_MSG);
        return;
    }
    clearModalErrors();
    setModalDeleteVisible(false);

    const form = document.getElementById('board-form');
    if (form) form.reset();
    document.querySelectorAll('#board-form input[name="board_status"]').forEach(r => { r.checked = false; });

    activeModalSheet = state.sheet;

    document.getElementById('modal-id').value = '0';
    document.getElementById('modal-title-text').textContent = 'Add New AOI Board Record / บันทึกข้อมูลบอร์ดใหม่';
    document.getElementById('modal-title-icon').className = 'fa-solid fa-plus';
    document.getElementById('modal-submit-label').textContent = `Save to ${activeModalSheet}`;
    gmSetMode('insert');

    const codeHidden = document.getElementById('modal-code');
    if (codeHidden) {
        codeHidden.value = window.currentNextCode || '';
    }

    document.getElementById('defects-input').value = '';
    syncDefectCheckboxesFromHidden();
    closeDefectPanel(true);

    if (currentSheetData) {
        updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, currentSheetData.rack.is_max);
    }

    syncClassificationLock();   // form.reset() cleared Other, but not the radios' disabled state
    gmSyncProgress();
    gmSyncDefectChip();
    gmShow(modal);
    setTimeout(() => document.getElementById('modal-product')?.focus({ preventScroll: true }), 120);
}
window.openAddModal = openAddModal;

async function openEditModal(id) {
    clearModalErrors();
    setModalDeleteVisible(false);

    try {
        const res = await fetch(`api/get.php?id=${id}&sheet=${encodeURIComponent(state.sheet)}`);
        const json = await res.json();
        if (!json.success || !json.data) throw new Error(json.message || 'Cannot load record');

        const d = json.data;
        activeModalSheet = d.sheet || (state.sheet === 'ALL' ? 'B200_Gen' : state.sheet);

        document.getElementById('modal-id').value = d.id;
        document.getElementById('modal-title-text').textContent = 'Edit AOI Board Record / แก้ไขข้อมูลบอร์ด';
        document.getElementById('modal-title-icon').className = 'fa-solid fa-pen';
        document.getElementById('modal-submit-label').textContent = 'Update record';
        gmSetMode('edit', d.code);
        setModalDeleteVisible(true);

        const codeHidden = document.getElementById('modal-code');
        if (codeHidden) {
            codeHidden.value = d.code || '';
        }

        if (state.sheet === 'ALL' && d.sheet) {
            try {
                const shRes = await fetch(`api/sheet_data.php?sheet=${encodeURIComponent(d.sheet)}`);
                const shJson = await shRes.json();
                if (shJson?.success && shJson.data) {
                    updateModalFreeShelves(shJson.data.free_by_mag, shJson.data.free_shelves, shJson.data.rack.capacity, shJson.data.shelf_prefix, false, d.shelf);
                } else if (currentSheetData) {
                    updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, false, d.shelf);
                }
            } catch (_) {
                if (currentSheetData) {
                    updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, false, d.shelf);
                }
            }
        } else if (currentSheetData) {
            updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, false, d.shelf);
        }

        const prod = document.getElementById('modal-product');
        if (prod) prod.value = d.product;

        const side = document.getElementById('modal-side');
        if (side) side.value = d.side;

        const bt = document.getElementById('modal-board-type');
        if (bt) bt.value = d.board_type;

        document.querySelectorAll('#board-form input[name="board_status"]').forEach(r => {
            r.checked = (r.value === d.status);
        });

        const pcs = document.getElementById('modal-pcs');
        if (pcs) pcs.value = d.pcs_status || '';

        document.getElementById('defects-input').value = d.defects || '';
        syncDefectCheckboxesFromHidden();
        closeDefectPanel(true);
        const classLocked = syncClassificationLock();

        gmSyncProgress();
        gmSyncDefectChip();
        gmShow(modal);
        if (!classLocked && (d.status === 'D' || d.status === 'GD')) {
            setTimeout(() => openDefectPanel(d.status), 90);
        }
        setTimeout(() => prod?.focus({ preventScroll: true }), 120);
    } catch (err) {
        showFlash('err', 'ไม่สามารถโหลดข้อมูลบอร์ดได้: ' + err.message);
    }
}
window.openEditModal = openEditModal;

function closeModal() {
    gmHide(modal);
}
window.closeModal = closeModal;

// ──────────────────────────────────────────────
// Form Submit via AJAX (Add / Edit)
// ──────────────────────────────────────────────

async function handleBoardFormSubmit(e) {
    e.preventDefault();
    clearModalErrors();

    const form = e.target;
    const submitBtn = document.getElementById('modal-submit-btn');
    const origHtml = submitBtn ? submitBtn.innerHTML : '';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
    }

    const formData = new FormData(form);
    const targetSheet = activeModalSheet || (state.sheet === 'ALL' ? 'B200_Gen' : state.sheet);
    formData.set('sheet', targetSheet);

    try {
        const res = await fetch('api/save.php', {
            method: 'POST',
            body: formData
        });

        const result = await res.json();

        if (result.success) {
            document.getElementById('gm-window')?.classList.add('gm-ok');
            closeModal();
            showFlash('ok', result.message);
            await Promise.all([
                loadTable(),
                refreshSheetData(state.sheet)
            ]);
        } else {
            showModalErrors(result.errors || ['บันทึกไม่สำเร็จ']);
        }
    } catch (err) {
        console.error(err);
        showModalErrors(['เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์']);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origHtml;
        }
    }
}
window.handleBoardFormSubmit = handleBoardFormSubmit;

// ──────────────────────────────────────────────
// Delete Board (AJAX)
// ──────────────────────────────────────────────

const deleteConfirm = document.getElementById('delete-board-confirm');
let pendingDeleteResolver = null;

function openDeleteConfirm(code) {
    if (!deleteConfirm) {
        return Promise.resolve(confirm(MSG.confirmDel(code)));
    }
    if (pendingDeleteResolver) {
        pendingDeleteResolver(false);
        pendingDeleteResolver = null;
    }
    const c1 = document.getElementById('delete-confirm-code');
    const c2 = document.getElementById('delete-confirm-code2');
    const displayCode = code || ('#' + (document.getElementById('modal-id')?.value || ''));
    if (c1) c1.textContent = displayCode;
    if (c2) c2.textContent = displayCode;
    gmShow(deleteConfirm);
    setTimeout(() => document.getElementById('delete-confirm-ok-btn')?.focus({ preventScroll: true }), 80);

    return new Promise((resolve) => {
        pendingDeleteResolver = resolve;
    });
}
window.openDeleteConfirm = openDeleteConfirm;

function closeDeleteConfirm() {
    gmHide(deleteConfirm);
    if (pendingDeleteResolver) {
        const resolve = pendingDeleteResolver;
        pendingDeleteResolver = null;
        resolve(false);
    }
}
window.closeDeleteConfirm = closeDeleteConfirm;

function confirmDeleteBoard() {
    gmHide(deleteConfirm);
    if (pendingDeleteResolver) {
        const resolve = pendingDeleteResolver;
        pendingDeleteResolver = null;
        resolve(true);
    }
}
window.confirmDeleteBoard = confirmDeleteBoard;

async function deleteBoard(id, code) {
    const confirmed = await openDeleteConfirm(code);
    if (!confirmed) return false;

    try {
        const res = await fetch('api/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, csrf: getCsrf() }),
        });

        const result = await res.json();

        if (result.success) {
            showFlash('ok', result.message);
            await Promise.all([
                reloadTable(),
                refreshSheetData(state.sheet)
            ]);
            return true;
        } else {
            showFlash('err', result.message || MSG.deleteFailed);
            return false;
        }
    } catch (e) {
        console.error(e);
        showFlash('err', MSG.networkError);
        return false;
    }
}
window.deleteBoard = deleteBoard;

// ──────────────────────────────────────────────
// Add Magazine (AJAX)
// ──────────────────────────────────────────────

function toggleAddMag(show) {
    const btn = document.getElementById('add-mag-btn');
    const box = document.getElementById('add-mag-confirm');
    if (!btn || !box) return;
    btn.classList.toggle('hidden', show);
    box.classList.toggle('hidden', !show);
    box.classList.toggle('flex', show);
}
window.toggleAddMag = toggleAddMag;

async function handleAddMagazine(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    const formData = new FormData(form);
    formData.set('sheet', state.sheet);

    try {
        const res = await fetch('api/magazine.php', {
            method: 'POST',
            body: formData
        });
        const result = await res.json();

        if (result.success) {
            showFlash('ok', result.message);
            toggleAddMag(false);
            await Promise.all([
                loadTable(),
                refreshSheetData(state.sheet)
            ]);
        } else {
            showFlash('err', result.message || 'ไม่สามารถเพิ่ม Magazine ได้');
        }
    } catch (err) {
        showFlash('err', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}
window.handleAddMagazine = handleAddMagazine;

// ──────────────────────────────────────────────
// Rack Confirm Insert Popup
// ──────────────────────────────────────────────

const rackConfirm = document.getElementById('rack-insert-confirm');
let pendingShelf = '';

function openRackConfirm(shelf) {
    pendingShelf = shelf;
    const s1 = document.getElementById('rack-confirm-shelf');
    const s2 = document.getElementById('rack-confirm-shelf2');
    if (s1) s1.textContent = shelf;
    if (s2) s2.textContent = shelf;
    gmShow(rackConfirm);
}
window.openRackConfirm = openRackConfirm;

function closeRackConfirm() {
    gmHide(rackConfirm);
    pendingShelf = '';
}
window.closeRackConfirm = closeRackConfirm;

function confirmRackInsert() {
    const shelf = pendingShelf;
    closeRackConfirm();
    openAddModal();

    const sel = document.getElementById('modal-shelf');
    if (sel) {
        sel.value = shelf;
        if (sel.value !== shelf) {
            // Option may need to be injected if not in list
            const opt = document.createElement('option');
            opt.value = shelf;
            opt.textContent = shelf;
            opt.selected = true;
            sel.appendChild(opt);
        }
    }
    gmSyncProgress();
    setTimeout(() => document.getElementById('modal-product')?.focus({ preventScroll: true }), 120);
}
window.confirmRackInsert = confirmRackInsert;

// ──────────────────────────────────────────────
// Defect Selection Panel
// ──────────────────────────────────────────────

const defectPanel = document.getElementById('defect-panel');
const gmStage = document.getElementById('gm-stage');

function syncDefectCheckboxesFromHidden() {
    const defectsVal = document.getElementById('defects-input')?.value || '';
    const codes = defectsVal ? defectsVal.split(',').filter(Boolean) : [];
    document.querySelectorAll('#defect-panel input[name="defect[]"]').forEach(cb => {
        cb.checked = codes.includes(cb.value);
    });
    const otherCode = codes.find(c => c === 'OTHER' || c.startsWith('OTHER:'));
    const otherCb = document.getElementById('defect-other-checkbox');
    const otherTxt = document.getElementById('defect-other-text');
    const otherWrap = otherTxt?.closest('.gm-other-note');
    if (otherCb && otherTxt) {
        if (otherCode) {
            otherCb.checked = true;
            otherTxt.value = otherCode.startsWith('OTHER:') ? otherCode.slice(6) : '';
            otherTxt.classList.remove('hidden');
            otherWrap?.classList.remove('hidden');
            otherTxt.disabled = false;
        } else {
            otherCb.checked = false;
            otherTxt.value = '';
            otherTxt.classList.add('hidden');
            otherWrap?.classList.add('hidden');
            otherTxt.disabled = true;
        }
    }
    gmSyncDefectCount();
}

// ── Dynamic Island Morph Controller (WAAPI State Machine: closed | opening | open | closing) ──
const gmWindow   = document.getElementById('gm-window');
const defectSlot = document.getElementById('defect-slot');
const islandSkin = document.getElementById('gm-island-skin');
const gooLayer   = document.getElementById('gm-goo-layer');
const gooBud     = document.getElementById('gm-goo-bud');
const edgeRipple = document.getElementById('gm-edge-ripple');

const islandCtrl = {
    state: 'closed',      // 'closed' | 'opening' | 'open' | 'closing'
    direction: null,      // 'open' | 'close' | null (active keyframe timeline orientation)
    status: null,         // 'D' | 'GD' | null
    anims: [],            // active synchronized WAAPI Animation instances
    pulseAnim: null,      // short WAAPI Animation for D <-> G+D pulse or settle bounce
    rippleAnim: null,     // standalone edge absorb ripple when reversing an open morph
    seq: 0                // monotonic token to ignore stale finish callbacks
};

const isReducedMotion = () =>
    Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

const isStackedStage = () =>
    Boolean(window.matchMedia && window.matchMedia('(max-width: 980px)').matches);

// Lock entrance gm-popin once completed so #gm-window / form fields never replay it
gmStage?.addEventListener('animationend', (e) => {
    if (e.target === gmStage && e.animationName === 'gm-popin') {
        gmStage.classList.add('is-entered');
    }
});

function applyDefectTint(st) {
    if (!defectPanel) return;
    const isMixed = st === 'GD';
    defectPanel.classList.toggle('is-mixed', isMixed);
    defectPanel.classList.toggle('is-danger', !isMixed);
    defectSlot?.classList.toggle('is-mixed', isMixed);
    islandCtrl.status = st;
}

function cancelIslandAnims() {
    while (islandCtrl.anims.length) {
        const a = islandCtrl.anims.pop();
        try {
            a.onfinish = null;
            a.oncancel = null;
            a.cancel();
        } catch (_) {}
    }
}

function playIslandPulse(isSettle = false) {
    if (!defectPanel || isReducedMotion()) return;
    islandCtrl.pulseAnim?.cancel();
    defectPanel.style.willChange = 'transform';
    const keyframes = isSettle
        ? [
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' },
            { transform: 'translate3d(0, 0, 0) scale3d(1.032, 1.015, 1)', offset: 0.42 },
            { transform: 'translate3d(0, 0, 0) scale3d(0.996, 0.998, 1)', offset: 0.78 },
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' }
        ]
        : [
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' },
            { transform: 'translate3d(0, 0, 0) scale3d(1.024, 0.98, 1)', offset: 0.36 },
            { transform: 'translate3d(0, 0, 0) scale3d(0.994, 1.007, 1)', offset: 0.72 },
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' }
        ];
    const anim = defectPanel.animate(keyframes, {
        duration: isSettle ? 180 : 210,
        easing: 'cubic-bezier(0.22, 1, 0.36, 1)'
    });
    islandCtrl.pulseAnim = anim;
    anim.onfinish = anim.oncancel = () => {
        if (islandCtrl.pulseAnim === anim) {
            islandCtrl.pulseAnim = null;
            if (islandCtrl.state === 'open') defectPanel.style.willChange = '';
        }
    };
}

function playEdgeAbsorbRipple(edgeX) {
    if (!edgeRipple || isReducedMotion() || isStackedStage()) return;
    islandCtrl.rippleAnim?.cancel();
    const rx = Math.round(edgeX - 6);
    const anim = edgeRipple.animate([
        { opacity: 0, transform: `translate3d(${rx}px, 0px, 0px) scale3d(0.35, 0.35, 1)` },
        { opacity: 0.95, transform: `translate3d(${rx}px, 0px, 0px) scale3d(1.15, 1.08, 1)`, offset: 0.42 },
        { opacity: 0, transform: `translate3d(${rx}px, 0px, 0px) scale3d(1.5, 1.55, 1)` }
    ], {
        duration: 190,
        easing: 'cubic-bezier(0.16, 1, 0.3, 1)'
    });
    islandCtrl.rippleAnim = anim;
    anim.onfinish = anim.oncancel = () => {
        if (islandCtrl.rippleAnim === anim) islandCtrl.rippleAnim = null;
    };
}

function measureStageMetrics() {
    const stacked = isStackedStage();
    const panelW  = (!stacked && defectPanel) ? (defectPanel.offsetWidth || 460) : 460;
    const gap     = 20;
    const shiftX  = stacked ? 0 : (panelW + gap) * 0.5;
    return { stacked, panelW, gap, shiftX };
}

function openDefectPanel(statusOverride) {
    if (!defectPanel || !gmStage) return;
    const st = statusOverride || document.querySelector('#board-form input[name="board_status"]:checked')?.value || 'D';
    applyDefectTint(st);
    gmSyncDefectCount();

    // 1. Already open: cross-fade tint (via CSS) + play tiny squash/stretch pulse (< 250ms)
    if (islandCtrl.state === 'open') {
        gmStage.classList.add('has-defects');
        defectPanel.classList.remove('hidden');
        playIslandPulse(false);
        return;
    }

    // 2. Reduced motion: instant cross-fade, no spring
    if (isReducedMotion()) {
        islandCtrl.seq++;
        islandCtrl.pulseAnim?.cancel();
        cancelIslandAnims();
        gmStage.classList.add('is-entered', 'has-defects');
        gmStage.classList.remove('is-morphing');
        defectPanel.classList.remove('hidden', 'is-morphing');
        islandCtrl.state = 'open';
        islandCtrl.direction = null;
        return;
    }

    // 3. Already opening: just let the running morph continue with the updated tint
    if (islandCtrl.state === 'opening' && islandCtrl.anims.length > 0) {
        return;
    }

    // 4. Interrupted while closing: reverse smoothly from exact current position/shape
    if (islandCtrl.state === 'closing' && islandCtrl.anims.length > 0) {
        islandCtrl.state = 'opening';
        gmStage.classList.add('has-defects', 'is-morphing');
        defectPanel.classList.remove('hidden');
        defectPanel.classList.add('is-morphing');
        const targetRate = islandCtrl.direction === 'open' ? 1 : -1.12;
        islandCtrl.anims.forEach(a => {
            a.updatePlaybackRate(targetRate);
            if (a.playState !== 'running') a.play();
        });
        return;
    }

    // 5. Fresh open from 'closed'
    const seq = ++islandCtrl.seq;
    islandCtrl.pulseAnim?.cancel();
    islandCtrl.rippleAnim?.cancel();
    cancelIslandAnims();

    islandCtrl.state = 'opening';
    islandCtrl.direction = 'open';

    gmStage.classList.add('has-defects', 'is-morphing');
    defectPanel.classList.remove('hidden');
    defectPanel.classList.add('is-morphing');

    // Measure once before starting animations (zero per-frame layout reads)
    const { stacked, gap, shiftX } = measureStageMetrics();
    const dur = 610; // < 650ms total budget including 4.8% spring overshoot settle
    const timing = { duration: dur, easing: 'linear', fill: 'both' };

    const surface = defectPanel.querySelector(':scope > .gm-surface');
    const head    = defectPanel.querySelector('.gm-head');
    const body    = defectPanel.querySelector('.gm-defect-body');
    const anims   = [];

    if (stacked) {
        // Vertical pill -> panel expand for narrow screens (<= 980px)
        const panelAnim = defectPanel.animate([
            { opacity: 0, transform: 'translate3d(0px, -16px, 0px) scale3d(0.9, 0.86, 1)', clipPath: 'inset(0% 34% 88% 34% round 26px)', offset: 0 },
            { opacity: 1, transform: 'translate3d(0px, -8px, 0px) scale3d(0.96, 0.94, 1)', clipPath: 'inset(0% 24% 76% 24% round 26px)', offset: 0.22 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1.016, 1.038, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 0.74 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(0.997, 0.995, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 0.88 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 1 }
        ], timing);
        anims.push(panelAnim);
    } else {
        // Desktop horizontal Dynamic Island pill morph + pure translate3d window glide
        const edge0  = Math.round(shiftX - gap);              // #gm-window right border at t=0
        const edge18 = Math.round(shiftX * 0.78 - gap);       // #gm-window right border at t=0.18
        const edge36 = Math.round(shiftX * 0.44 - gap);       // #gm-window right border at t=0.36

        if (gmWindow) {
            anims.push(gmWindow.animate([
                { transform: 'translate3d(0px, 0px, 0px)', offset: 0 },
                { transform: `translate3d(${-Math.round(shiftX * 0.22)}px, 0px, 0px)`, offset: 0.18 },
                { transform: `translate3d(${-Math.round(shiftX * 0.56)}px, 0px, 0px)`, offset: 0.36 },
                { transform: `translate3d(${-Math.round(shiftX * 0.88)}px, 0px, 0px)`, offset: 0.56 },
                { transform: `translate3d(${-Math.round(shiftX * 1.032)}px, 0px, 0px)`, offset: 0.74 },
                { transform: `translate3d(${-Math.round(shiftX * 0.996)}px, 0px, 0px)`, offset: 0.88 },
                { transform: `translate3d(${-shiftX}px, 0px, 0px)`, offset: 1 }
            ], timing));
        }

        const panelAnim = defectPanel.animate([
            {
                opacity: 0,
                transform: `translate3d(${edge0 - 20}px, 0px, 0px) scale3d(0.9, 0.9, 1)`,
                clipPath: 'inset(51.5% 84% 40.5% 0% round 28px)',
                offset: 0
            },
            {
                opacity: 1,
                transform: `translate3d(${edge0 - 12}px, 0px, 0px) scale3d(0.96, 0.96, 1)`,
                clipPath: 'inset(51% 78% 40% 0% round 28px)',
                offset: 0.07
            },
            {
                opacity: 1,
                transform: `translate3d(${edge18 - 4}px, 0px, 0px) scale3d(1, 0.98, 1)`,
                clipPath: 'inset(49.5% 58% 39.5% 0% round 28px)',
                offset: 0.20
            },
            {
                opacity: 1,
                transform: `translate3d(${Math.round(edge36 * 0.45)}px, 0px, 0px) scale3d(1.01, 1, 1)`,
                clipPath: 'inset(32% 16% 24% 0% round 25px)',
                offset: 0.36
            },
            {
                opacity: 1,
                transform: 'translate3d(2px, 0px, 0px) scale3d(1.032, 1.014, 1)',
                clipPath: 'inset(2% 1% 2% 0% round 22px)',
                offset: 0.56
            },
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(1.048, 1.024, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 0.74
            },
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(0.994, 0.997, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 0.88
            },
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 1
            }
        ], timing);
        anims.push(panelAnim);

        if (gooLayer && gooBud) {
            anims.push(gooLayer.animate([
                { opacity: 0, transform: `translate3d(${edge0 - 22}px, 0px, 0px)`, offset: 0 },
                { opacity: 0.92, transform: `translate3d(${edge0 - 22}px, 0px, 0px)`, offset: 0.06 },
                { opacity: 0.88, transform: `translate3d(${edge18 - 22}px, 0px, 0px)`, offset: 0.19 },
                { opacity: 0, transform: `translate3d(${edge36 - 18}px, 0px, 0px)`, offset: 0.30 },
                { opacity: 0, transform: 'translate3d(0px, 0px, 0px)', offset: 1 }
            ], timing));
            anims.push(gooBud.animate([
                { transform: 'translate3d(-4px, 0px, 0px) scale3d(0.65, 0.85, 1)', offset: 0 },
                { transform: 'translate3d(20px, 0px, 0px) scale3d(1.16, 0.62, 1)', offset: 0.18 },
                { transform: 'translate3d(34px, 0px, 0px) scale3d(0.38, 0.3, 1)', offset: 0.30 },
                { transform: 'translate3d(0px, 0px, 0px) scale3d(0.2, 0.2, 1)', offset: 1 }
            ], timing));
        }
    }

    if (islandSkin) {
        anims.push(islandSkin.animate([
            { opacity: 1, offset: 0 },
            { opacity: 1, offset: 0.28 },
            { opacity: 0.18, offset: 0.54 },
            { opacity: 0, offset: 0.70 },
            { opacity: 0, offset: 1 }
        ], timing));
    }

    if (surface) {
        anims.push(surface.animate([
            { opacity: 0, transform: 'translate3d(-10px, 0px, 0px) scale3d(0.95, 0.95, 1)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-8px, 0px, 0px) scale3d(0.96, 0.96, 1)', offset: 0.20 },
            { opacity: 0.94, transform: 'translate3d(0px, 0px, 0px) scale3d(1.004, 1.004, 1)', offset: 0.56 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', offset: 0.76 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', offset: 1 }
        ], timing));
    }

    if (head) {
        anims.push(head.animate([
            { opacity: 0, transform: 'translate3d(-6px, -4px, 0px)', filter: 'blur(4px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-5px, -3px, 0px)', filter: 'blur(4px)', offset: 0.24 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', filter: 'blur(0px)', offset: 0.58 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', filter: 'blur(0px)', offset: 1 }
        ], timing));
    }

    if (body) {
        anims.push(body.animate([
            { opacity: 0, transform: 'translate3d(0px, 10px, 0px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(0px, 8px, 0px)', offset: 0.30 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', offset: 0.66 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', offset: 1 }
        ], timing));
    }

    islandCtrl.anims = anims;
    const master = anims[0];
    if (master) {
        master.onfinish = () => {
            if (seq !== islandCtrl.seq) return;
            if (islandCtrl.state === 'opening') {
                gmStage.classList.add('is-entered', 'has-defects');
                gmStage.classList.remove('is-morphing');
                defectPanel.classList.remove('hidden', 'is-morphing');
                cancelIslandAnims();
                islandCtrl.state = 'open';
                islandCtrl.direction = null;
            } else if (islandCtrl.state === 'closing') {
                const { gap: g, shiftX: sx } = measureStageMetrics();
                playEdgeAbsorbRipple(sx - g);
                defectPanel.classList.add('hidden');
                defectPanel.classList.remove('is-morphing');
                gmStage.classList.remove('has-defects', 'is-morphing');
                gmStage.classList.add('is-entered');
                cancelIslandAnims();
                islandCtrl.state = 'closed';
                islandCtrl.direction = null;
            }
        };
    }
}

function closeDefectPanel(immediate = false) {
    if (!defectPanel || !gmStage) return;

    // Reset modal entrance lock when modal is being prepared from hidden state
    if (immediate && modal?.classList.contains('hidden')) {
        gmStage.classList.remove('is-entered');
    }

    if (immediate || isReducedMotion() || (islandCtrl.state === 'closed' && defectPanel.classList.contains('hidden'))) {
        islandCtrl.seq++;
        islandCtrl.pulseAnim?.cancel();
        islandCtrl.rippleAnim?.cancel();
        cancelIslandAnims();
        defectPanel.classList.add('hidden');
        defectPanel.classList.remove('is-morphing', 'gm-closing');
        gmStage.classList.remove('has-defects', 'is-morphing');
        islandCtrl.state = 'closed';
        islandCtrl.direction = null;
        return;
    }

    // Already closing: let current closing morph complete
    if (islandCtrl.state === 'closing' && islandCtrl.anims.length > 0) {
        return;
    }

    // Interrupted while opening: reverse smoothly from exact current position/shape at faster close rate
    if (islandCtrl.state === 'opening' && islandCtrl.anims.length > 0) {
        islandCtrl.pulseAnim?.cancel();
        islandCtrl.state = 'closing';
        gmStage.classList.add('is-morphing');
        defectPanel.classList.add('is-morphing');
        const targetRate = islandCtrl.direction === 'open' ? -1.32 : 1;
        islandCtrl.anims.forEach(a => {
            a.updatePlaybackRate(targetRate);
            if (a.playState !== 'running') a.play();
        });
        return;
    }

    // Fresh close from 'open' (480ms budget: content blurs/fades first -> shrinks to pill -> edge absorb ripple)
    const seq = ++islandCtrl.seq;
    islandCtrl.pulseAnim?.cancel();
    islandCtrl.rippleAnim?.cancel();
    cancelIslandAnims();

    islandCtrl.state = 'closing';
    islandCtrl.direction = 'close';

    gmStage.classList.add('is-entered', 'is-morphing');
    defectPanel.classList.add('is-morphing');

    const { stacked, gap, shiftX } = measureStageMetrics();
    const dur = 480; // 450-520ms fast reverse budget
    const timing = { duration: dur, easing: 'linear', fill: 'both' };

    const surface = defectPanel.querySelector(':scope > .gm-surface');
    const head    = defectPanel.querySelector('.gm-head');
    const body    = defectPanel.querySelector('.gm-defect-body');
    const anims   = [];

    if (stacked) {
        const panelAnim = defectPanel.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 0 },
            { opacity: 1, transform: 'translate3d(0px, -6px, 0px) scale3d(0.96, 0.94, 1)', clipPath: 'inset(0% 26% 78% 26% round 26px)', offset: 0.65 },
            { opacity: 0, transform: 'translate3d(0px, -16px, 0px) scale3d(0.9, 0.85, 1)', clipPath: 'inset(0% 36% 90% 36% round 26px)', offset: 1 }
        ], timing);
        anims.push(panelAnim);
    } else {
        const cEdge48 = Math.round(shiftX * 0.52 - gap);
        const cEdge74 = Math.round(shiftX * 0.90 - gap);
        const cEdge88 = Math.round(shiftX - 3 - gap);
        const cEdge100 = Math.round(shiftX - gap);

        if (gmWindow) {
            anims.push(gmWindow.animate([
                { transform: `translate3d(${-shiftX}px, 0px, 0px)`, offset: 0 },
                { transform: `translate3d(${-Math.round(shiftX * 0.88)}px, 0px, 0px)`, offset: 0.16 },
                { transform: `translate3d(${-Math.round(shiftX * 0.48)}px, 0px, 0px)`, offset: 0.48 },
                { transform: `translate3d(${-Math.round(shiftX * 0.10)}px, 0px, 0px)`, offset: 0.74 },
                { transform: 'translate3d(-3px, 0px, 0px)', offset: 0.88 },
                { transform: 'translate3d(0px, 0px, 0px)', offset: 1 }
            ], timing));
        }

        const panelAnim = defectPanel.animate([
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 0
            },
            {
                opacity: 1,
                transform: 'translate3d(18px, 0px, 0px) scale3d(0.98, 0.98, 1)',
                clipPath: 'inset(26% 18% 20% 0% round 25px)',
                offset: 0.22
            },
            {
                opacity: 1,
                transform: `translate3d(${cEdge48}px, 0px, 0px) scale3d(0.98, 0.98, 1)`,
                clipPath: 'inset(49.5% 58% 39.5% 0% round 28px)',
                offset: 0.48
            },
            {
                opacity: 1,
                transform: `translate3d(${cEdge74 - 8}px, 0px, 0px) scale3d(0.94, 0.94, 1)`,
                clipPath: 'inset(51% 79% 40% 0% round 28px)',
                offset: 0.74
            },
            {
                opacity: 0.78,
                transform: `translate3d(${cEdge88 - 22}px, 0px, 0px) scale3d(0.85, 0.84, 1)`,
                clipPath: 'inset(52% 90% 41% 0% round 28px)',
                offset: 0.90
            },
            {
                opacity: 0,
                transform: `translate3d(${cEdge100 - 30}px, 0px, 0px) scale3d(0.76, 0.76, 1)`,
                clipPath: 'inset(52.5% 94% 41.5% 0% round 28px)',
                offset: 1
            }
        ], timing);
        anims.push(panelAnim);

        if (gooLayer && gooBud) {
            anims.push(gooLayer.animate([
                { opacity: 0, transform: `translate3d(${cEdge48 - 22}px, 0px, 0px)`, offset: 0 },
                { opacity: 0, transform: `translate3d(${cEdge74 - 22}px, 0px, 0px)`, offset: 0.64 },
                { opacity: 0.92, transform: `translate3d(${cEdge74 - 22}px, 0px, 0px)`, offset: 0.74 },
                { opacity: 0.86, transform: `translate3d(${cEdge88 - 22}px, 0px, 0px)`, offset: 0.90 },
                { opacity: 0, transform: `translate3d(${cEdge100 - 22}px, 0px, 0px)`, offset: 1 }
            ], timing));
            anims.push(gooBud.animate([
                { transform: 'translate3d(28px, 0px, 0px) scale3d(0.4, 0.4, 1)', offset: 0 },
                { transform: 'translate3d(24px, 0px, 0px) scale3d(1.12, 0.64, 1)', offset: 0.68 },
                { transform: 'translate3d(-4px, 0px, 0px) scale3d(0.75, 0.88, 1)', offset: 0.90 },
                { transform: 'translate3d(-12px, 0px, 0px) scale3d(0.3, 0.4, 1)', offset: 1 }
            ], timing));
        }

        if (edgeRipple) {
            anims.push(edgeRipple.animate([
                { opacity: 0, transform: `translate3d(${cEdge74 - 6}px, 0px, 0px) scale3d(0.3, 0.3, 1)`, offset: 0 },
                { opacity: 0, transform: `translate3d(${cEdge74 - 6}px, 0px, 0px) scale3d(0.4, 0.35, 1)`, offset: 0.72 },
                { opacity: 0.95, transform: `translate3d(${cEdge88 - 6}px, 0px, 0px) scale3d(1.15, 1.08, 1)`, offset: 0.86 },
                { opacity: 0, transform: `translate3d(${cEdge100 - 6}px, 0px, 0px) scale3d(1.5, 1.55, 1)`, offset: 1 }
            ], timing));
        }
    }

    if (islandSkin) {
        anims.push(islandSkin.animate([
            { opacity: 0, offset: 0 },
            { opacity: 0.94, offset: 0.24 },
            { opacity: 1, offset: 0.84 },
            { opacity: 0, offset: 1 }
        ], timing));
    }

    if (surface) {
        anims.push(surface.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-8px, 0px, 0px) scale3d(0.95, 0.95, 1)', offset: 0.26 },
            { opacity: 0, transform: 'translate3d(-8px, 0px, 0px) scale3d(0.95, 0.95, 1)', offset: 1 }
        ], timing));
    }

    if (head) {
        anims.push(head.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', filter: 'blur(0px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-6px, -3px, 0px)', filter: 'blur(4px)', offset: 0.22 },
            { opacity: 0, transform: 'translate3d(-6px, -3px, 0px)', filter: 'blur(4px)', offset: 1 }
        ], timing));
    }

    if (body) {
        anims.push(body.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(0px, 8px, 0px)', offset: 0.20 },
            { opacity: 0, transform: 'translate3d(0px, 8px, 0px)', offset: 1 }
        ], timing));
    }

    islandCtrl.anims = anims;
    const master = anims[0];
    if (master) {
        master.onfinish = () => {
            if (seq !== islandCtrl.seq) return;
            if (islandCtrl.state === 'closing') {
                defectPanel.classList.add('hidden');
                defectPanel.classList.remove('is-morphing');
                gmStage.classList.remove('has-defects', 'is-morphing');
                gmStage.classList.add('is-entered');
                cancelIslandAnims();
                islandCtrl.state = 'closed';
                islandCtrl.direction = null;
            } else if (islandCtrl.state === 'opening') {
                gmStage.classList.add('is-entered', 'has-defects');
                gmStage.classList.remove('is-morphing');
                defectPanel.classList.remove('hidden', 'is-morphing');
                cancelIslandAnims();
                islandCtrl.state = 'open';
                islandCtrl.direction = null;
                playIslandPulse(true);
            }
        };
    }
}

function gmSyncDefectCount() {
    const n = document.querySelectorAll('#defect-panel input[name="defect[]"]:checked').length
        + (document.getElementById('defect-other-checkbox')?.checked ? 1 : 0);
    const el = document.getElementById('gm-defect-count');
    if (el) el.textContent = n;
}
window.openDefectPanel = openDefectPanel;
window.closeDefectPanel = closeDefectPanel;

function toggleOtherDefectInput() {
    const checkbox = document.getElementById('defect-other-checkbox');
    const input = document.getElementById('defect-other-text');
    const wrap = input?.closest('.gm-other-note');
    if (!checkbox || !input) return;
    if (checkbox.checked) {
        wrap?.classList.remove('hidden');
        input.classList.remove('hidden');
        input.disabled = false;
        input.focus();
    } else {
        input.classList.add('hidden');
        wrap?.classList.add('hidden');
        input.disabled = true;
        input.value = '';
    }
    updateDefectsInput();
}
window.toggleOtherDefectInput = toggleOtherDefectInput;

function updateDefectsInput() {
    const checkboxes = document.querySelectorAll('#defect-panel input[name="defect[]"]:checked');
    const otherChecked = document.getElementById('defect-other-checkbox')?.checked;
    const rawOther = document.getElementById('defect-other-text')?.value || '';
    const otherText = rawOther.trim().replace(/\s*\r?\n\s*/g, ' ');
    const codes = Array.from(checkboxes).map(cb => cb.value);
    if (otherChecked) {
        codes.push(otherText ? 'OTHER:' + otherText : 'OTHER');
    }
    const hidden = document.getElementById('defects-input');
    if (hidden) hidden.value = codes.join(',');
    gmSyncDefectCount();
    gmSyncDefectChip();
}
window.updateDefectsInput = updateDefectsInput;

function clearDefects() {
    document.querySelectorAll('#defect-panel input[name="defect[]"]').forEach(cb => { cb.checked = false; });
    const otherCb = document.getElementById('defect-other-checkbox');
    const otherTxt = document.getElementById('defect-other-text');
    const otherWrap = otherTxt?.closest('.gm-other-note');
    if (otherCb) otherCb.checked = false;
    if (otherTxt) {
        otherTxt.value = '';
        otherTxt.classList.add('hidden');
        otherWrap?.classList.add('hidden');
        otherTxt.disabled = true;
    }
    updateDefectsInput();
}
window.clearDefects = clearDefects;

function switchToStatusG() {
    const gRadio = document.querySelector('#board-form input[name="board_status"][value="G"]');
    if (gRadio) {
        gRadio.checked = true;
        gRadio.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
        closeDefectPanel(false);
    }
}
window.switchToStatusG = switchToStatusG;

function confirmDefects() {
    updateDefectsInput();
}
window.confirmDefects = confirmDefects;

// ──────────────────────────────────────────────
// Flash Toast Helper
// ──────────────────────────────────────────────

function showFlash(type, message) {
    const container = document.getElementById('flash-container') ?? createFlashContainer();
    const styles = {
        ok:   ['border-emerald-300 bg-emerald-50 text-emerald-800', 'circle-check'],
        warn: ['border-amber-300 bg-amber-50 text-amber-800', 'triangle-exclamation'],
        err:  ['border-red-300 bg-red-50 text-red-800', 'circle-exclamation'],
    };
    const [cls, icon] = styles[type] ?? styles.err;
    const div = document.createElement('div');
    div.className = `no-print rounded-md border px-4 py-2.5 flex items-center gap-2 text-xs shadow-md transition-all duration-200 ${cls}`;
    div.setAttribute('role', type === 'err' ? 'alert' : 'status');
    div.innerHTML = `<i class="fa-solid fa-${icon}" aria-hidden="true"></i><span>${h(message)}</span>`;
    container.appendChild(div);
    setTimeout(() => {
        div.style.opacity = '0';
        setTimeout(() => div.remove(), 250);
    }, type === 'warn' ? 6000 : 4000);
}
window.showFlash = showFlash;

function createFlashContainer() {
    const div = document.createElement('div');
    div.id = 'flash-container';
    div.className = 'fixed top-20 right-4 z-50 flex flex-col gap-2 w-80 pointer-events-none [&>*]:pointer-events-auto';
    document.body.appendChild(div);
    return div;
}

// ──────────────────────────────────────────────
// Live Clock
// ──────────────────────────────────────────────

function updateClock() {
    const n = new Date();
    const p = v => String(v).padStart(2, '0');
    const hm = document.getElementById('clock-hm');
    const sc = document.getElementById('clock-s');
    const dt = document.getElementById('clock-date');
    if (!hm) return;
    hm.textContent = `${p(n.getHours())}:${p(n.getMinutes())}`;
    sc.textContent = `:${p(n.getSeconds())}`;
    let d = `${n.getFullYear()}-${p(n.getMonth()+1)}-${p(n.getDate())}`;
    try {
        d = n.toLocaleDateString('th-TH-u-ca-gregory', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    } catch (e) {}
    if (dt && dt.textContent !== d) dt.textContent = d;
}

// ──────────────────────────────────────────────
// Event Listeners Setup
// ──────────────────────────────────────────────

let searchDebounceTimer = null;

function setupSearch() {
    const input = document.getElementById('barcode-search-input');
    if (!input) return;

    state.search = input.value.trim();

    input.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            const query = input.value.trim();
            if (query === state.search) return;
            state.search = query;
            state.page = 1;
            syncBrowserUrl();
            loadTable();
        }, 300);
    });
}

function setupFilters() {
    // Dropdowns
    const mapping = {
        'filter-shelf':      'shelf',
        'filter-board-type': 'board_type',
        'filter-status':     'status',
        'filter-other':      'other',
    };

    Object.entries(mapping).forEach(([id, stateKey]) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', () => {
            state[stateKey] = el.value;
            state.page = 1;
            syncBrowserUrl();
            loadTable();
        });
    });

    // Page size
    const select = document.getElementById('page-size-select');
    if (select) {
        const read = () => (select.value === 'all' ? 'all' : parseInt(select.value, 10) || 10);
        state.pageSize = read();
        select.addEventListener('change', () => {
            state.pageSize = read();
            state.page = 1;
            syncBrowserUrl();
            loadTable();
        });
    }

    // Clear filters button
    document.getElementById('clear-filters-btn')?.addEventListener('click', (e) => {
        e.preventDefault();
        state.search = '';
        state.shelf = 'ALL';
        state.board_type = 'ALL';
        state.status = 'ALL';
        state.other = 'ALL';
        state.page = 1;

        const input = document.getElementById('barcode-search-input');
        if (input) {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        ['filter-shelf', 'filter-board-type', 'filter-status', 'filter-other'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = 'ALL';
        });

        syncBrowserUrl();
        loadTable();
    });

    // Refresh table button
    document.getElementById('refresh-table-btn')?.addEventListener('click', (e) => {
        e.preventDefault();
        loadTable();
        refreshSheetData(state.sheet);
    });
}

function setupDelegation() {
    // Sheet tabs navigation
    document.getElementById('sheet-tabs')?.addEventListener('click', (e) => {
        const a = e.target.closest('a[data-sheet]');
        if (!a) return;
        e.preventDefault();
        switchSheet(a.dataset.sheet);
    });

    // Pagination clicks
    pagerFooter?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-page]');
        if (!btn || btn.disabled) return;
        goToPage(Number(btn.dataset.page));
    });

    // Table action buttons (Retry) & Row click to open Edit Mode
    tableWrapper?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-action]');
        if (btn) {
            if (btn.dataset.action === 'delete') deleteBoard(btn.dataset.id, btn.dataset.code);
            else if (btn.dataset.action === 'edit') openEditModal(btn.dataset.id);
            else if (btn.dataset.action === 'retry') reloadTable();
            return;
        }

        const tr = e.target.closest('tr.aoi-row[data-id]');
        if (!tr || !tr.dataset.id) return;
        if (window.getSelection && window.getSelection().toString().length > 0) return;
        if (gmIsOpen(modal) || gmIsOpen(rackConfirm)) return;

        openEditModal(tr.dataset.id);
    });

    // Keyboard a11y on table rows (Enter / Space opens Edit Mode without scrolling page)
    tableWrapper?.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target.closest('button, a, input, select, textarea')) return;
        const tr = e.target.closest('tr.aoi-row[data-id]');
        if (!tr || !tr.dataset.id) return;
        e.preventDefault();
        if (gmIsOpen(modal) || gmIsOpen(rackConfirm)) return;
        openEditModal(tr.dataset.id);
    });

    // Defect Matrix interactions (click row to edit, retry button, keyboard Enter/Space, hover column highlight)
    matrixWrapper?.addEventListener('click', (e) => {
        const retryBtn = e.target.closest('button[data-action="retry-matrix"]');
        if (retryBtn) {
            loadDefectMatrix();
            return;
        }
        const tr = e.target.closest('tr.dm-row[data-id]');
        if (!tr || !tr.dataset.id) return;
        if (window.getSelection && window.getSelection().toString().length > 0) return;
        if (gmIsOpen(modal) || gmIsOpen(rackConfirm)) return;
        openEditModal(tr.dataset.id);
    });

    matrixWrapper?.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target.closest('button, a, input, select, textarea')) return;
        const tr = e.target.closest('tr.dm-row[data-id]');
        if (!tr || !tr.dataset.id) return;
        e.preventDefault();
        if (gmIsOpen(modal) || gmIsOpen(rackConfirm)) return;
        openEditModal(tr.dataset.id);
    });

    matrixWrapper?.addEventListener('pointerover', (e) => {
        const tr = e.target.closest('tr.dm-row[data-id]');
        if (!tr) {
            clearMatrixHighlights();
            return;
        }
        const td = e.target.closest('td.dm-td');
        highlightMatrixRowAndCol(tr, td);
    });

    matrixWrapper?.addEventListener('pointerleave', () => {
        clearMatrixHighlights();
    });

    matrixWrapper?.addEventListener('focusin', (e) => {
        const tr = e.target.closest('tr.dm-row[data-id]');
        if (tr) highlightMatrixRowAndCol(tr, null);
    });

    matrixWrapper?.addEventListener('focusout', (e) => {
        if (!matrixWrapper.contains(e.relatedTarget)) {
            clearMatrixHighlights();
        }
    });

    // Delete button inside Edit Modal
    document.getElementById('modal-delete-btn')?.addEventListener('click', async () => {
        const id = document.getElementById('modal-id')?.value;
        const code = document.getElementById('modal-code')?.value || '';
        if (!id || id === '0') return;
        const deleted = await deleteBoard(id, code);
        if (deleted && gmIsOpen(modal)) {
            closeModal();
        }
    });

    // Rack Map clicks (empty shelf opens confirm, occupied shelf opens edit)
    document.getElementById('rack-map')?.addEventListener('click', (e) => {
        const emptyBtn = e.target.closest('button[data-shelf]');
        if (emptyBtn) {
            openRackConfirm(emptyBtn.dataset.shelf);
            return;
        }
        const editBtn = e.target.closest('button[data-action="edit"]');
        if (editBtn) {
            openEditModal(editBtn.dataset.id);
            return;
        }
        const pagerBtn = e.target.closest('#rack-pager button[data-p]');
        if (pagerBtn && !pagerBtn.disabled) {
            currentRackPage = +pagerBtn.dataset.p;
            if (currentSheetData) {
                renderRackPager(currentSheetData.rack.mag_count);
            }
        }
    });

    // Defect status selection in modal (D / GD pops out water droplet to right; G morphs back into droplet and flows into main modal)
    document.addEventListener('change', (e) => {
        if (e.target.name === 'board_status') {
            if (e.target.value === 'D' || e.target.value === 'GD') {
                syncDefectCheckboxesFromHidden();
                openDefectPanel(e.target.value);
            } else if (e.target.value === 'G') {
                clearDefects();
                closeDefectPanel(false);
            }
        }
    });

    // Keyboard shortcuts & backdrop clicks
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (gmIsOpen(deleteConfirm)) {
            closeDeleteConfirm();
            return;
        }
        if (gmIsOpen(rackConfirm)) {
            closeRackConfirm();
            return;
        }
        if (gmIsOpen(modal)) {
            closeModal();
        }
    });

    // Enter = save (insert / edit)
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.isComposing || e.shiftKey || e.altKey || e.ctrlKey || e.metaKey) return;
        if (gmIsOpen(deleteConfirm)) {
            if (!e.target.closest('button')) {
                e.preventDefault();
                confirmDeleteBoard();
            }
            return;
        }
        if (!gmIsOpen(modal) || gmIsOpen(rackConfirm)) return;
        if (e.target.closest('button[type="button"], textarea')) return;
        e.preventDefault();
        document.getElementById('board-form')?.requestSubmit();
    });

    rackConfirm?.addEventListener('click', (e) => {
        if (e.target === rackConfirm) closeRackConfirm();
    });

    deleteConfirm?.addEventListener('click', (e) => {
        if (e.target === deleteConfirm) closeDeleteConfirm();
    });

    modal?.addEventListener('click', (e) => {
        if (e.target === modal || e.target.id === 'gm-stage') closeModal();
    });

    // Browser navigation (Back / Forward)
    window.addEventListener('popstate', (e) => {
        const params = new URLSearchParams(window.location.search);
        const sh = params.get('sheet') || 'ALL';
        state.sheet = sh;
        state.search = params.get('q') || '';
        state.shelf = params.get('shelf') || 'ALL';
        state.board_type = params.get('board_type') || 'ALL';
        state.status = params.get('status') || 'ALL';
        state.other = params.get('other') || 'ALL';

        const searchInput = document.getElementById('barcode-search-input');
        if (searchInput) searchInput.value = state.search;
        const filterShelf = document.getElementById('filter-shelf');
        if (filterShelf) filterShelf.value = state.shelf;
        const filterBt = document.getElementById('filter-board-type');
        if (filterBt) filterBt.value = state.board_type;
        const filterSt = document.getElementById('filter-status');
        if (filterSt) filterSt.value = state.status;
        const filterOth = document.getElementById('filter-other');
        if (filterOth) filterOth.value = state.other;

        updateTabActiveClasses(sh);
        syncShelfViewMode();
        loadTable();
        refreshSheetData(sh);
    });
}

// ──────────────────────────────────────────────
// Initialization
// ──────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    tableWrapper  = document.getElementById('table-wrapper');
    pagerFooter   = document.getElementById('pagination-footer');
    matrixWrapper = document.getElementById('defect-matrix-wrapper');

    // Initialize state from URL params
    const params = new URLSearchParams(window.location.search);
    state.sheet      = params.get('sheet') || window.initialSheet || 'ALL';
    state.search     = params.get('q') || '';
    state.shelf      = params.get('shelf') || 'ALL';
    state.board_type = params.get('board_type') || 'ALL';
    state.status     = params.get('status') || 'ALL';
    state.other      = params.get('other') || 'ALL';

    const pSize = document.getElementById('page-size-select');
    if (pSize) {
        state.pageSize = pSize.value === 'all' ? 'all' : parseInt(pSize.value, 10) || 10;
    }

    setupSearch();
    setupFilters();
    setupDelegation();
    syncShelfViewMode();

    // Start clock
    setInterval(updateClock, 1000);
    updateClock();

    // Initial AJAX loads
    loadTable();
    refreshSheetData(state.sheet);

    // If initial URL had ?edit=ID, open edit modal via AJAX
    const editId = params.get('edit');
    if (editId) {
        openEditModal(editId);
        // Clean edit param from URL without reload
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('edit');
        window.history.replaceState({ sheet: state.sheet }, '', cleanUrl.toString());
    }
});
