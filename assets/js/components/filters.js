/**
 * Filter bar: search, dropdowns, rows per page, clear / refresh (components/filters.php).
 */

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
