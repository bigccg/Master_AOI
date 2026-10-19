/**
 * Data table: columns + table / row rendering (components/data-table.php).
 */

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

// Listeners: row click / keyboard opens Edit, Retry button
function setupTableEvents() {
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
}
