/**
 * Data table: AJAX loading (api/boards.php).
 */

let reqSeq        = 0;
let inflight      = null;

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
