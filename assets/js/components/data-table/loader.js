/**
 * Data table: AJAX loading (api/boards.php).
 */

let reqSeq   = 0;
let inflight = null;

function setBusy(on) {
    tableWrapper?.setAttribute('aria-busy', on ? 'true' : 'false');
}

// silent (live sync): no loading state; unchanged data leaves the table alone, changed rows are patched in place
async function loadTable(refreshMatrix = true, { silent = false } = {}) {
    if (refreshMatrix) {
        syncShelfViewMode();
        if (zoneHasSheetsJs(state.zone) && state.sheet === 'ALL') {
            loadDefectMatrix();
        }
    }

    // Fast path when the selected zone has no sheets configured yet (e.g. a newly added zone)
    if (!zoneHasSheetsJs(state.zone)) {
        inflight?.abort();
        inflight = null;
        view.status = 'ready';
        view.rows = [];
        view.total = 0;
        setBusy(false);
        renderTable();
        renderPagination();
        document.dispatchEvent(new CustomEvent('aoi:loaded', { detail: { total: 0, search: state.search } }));
        return;
    }

    if (silent && inflight) return;   // a user-driven load is already on its way
    const seq = ++reqSeq;
    inflight?.abort();
    const ctrl = inflight = new AbortController();

    if (!silent) {
        setBusy(true);
        if (view.rows.length === 0) {
            view.status = 'loading';
            renderTable();
        }
        renderPagination();
    }

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
        inflight = null;
        if (silent) throw err;   // live sync shows Offline; the table keeps what it has
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
    inflight = null;

    const total = Number(json.total);

    // Adjust page if current page exceeded
    if (state.pageSize !== 'all' && total > 0 && state.page > Math.ceil(total / state.pageSize)) {
        state.page = Math.ceil(total / state.pageSize);
        loadTable(false);
        return;
    }

    if (silent && view.status === 'ready') {
        const tbody = tableWrapper?.querySelector('.aoi-tbody');
        if (total === view.total && JSON.stringify(json.data) === JSON.stringify(view.rows)) return;
        if (tbody && view.rows.length > 0 && json.data.length > 0) {
            const oldRows = view.rows;
            view.rows = json.data;
            view.total = total;
            livePatchRows(tbody, oldRows, json.data, r => r[8], rowHtml);
            renderPagination();
            document.dispatchEvent(new CustomEvent('aoi:loaded', { detail: { total, search: state.search } }));
            return;
        }
    }

    view.status = 'ready';
    view.rows = json.data;
    view.total = total;
    setBusy(false);
    renderTable();
    renderPagination();

    if (Array.isArray(json.period_options)) {
        renderPeriodFilterOptions(json.period_options);
    }

    document.dispatchEvent(new CustomEvent('aoi:loaded', { detail: { total, search: state.search } }));

    if (json.truncated) {
        showFlash('warn', `แสดง ${json.data.length} รายการแรกจากทั้งหมด ${total} รายการ กรุณาระบุคำค้นหาเพิ่มเติม / Showing first ${json.data.length} of ${total} — refine your search.`);
    }
}

function reloadTable() {
    return loadTable();
}
window.reloadTable = reloadTable;
