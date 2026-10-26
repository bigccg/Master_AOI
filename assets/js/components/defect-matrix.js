/**
 * Defect Matrix shown on All Size Magazine within the selected zone (components/defect-matrix.php).
 */

let matrixReqSeq   = 0;
let matrixInflight = null;

function getDefectEntries() {
    const labels = currentSheetData?.defect_labels || window.DEFECT_LABELS || {};
    return Object.entries(labels)
        .map(([k, v]) => [Number(k), String(v)])
        .filter(([k]) => Number.isFinite(k))
        .sort((a, b) => a[0] - b[0]);
}

function buildMatrixApiUrl() {
    const url = new URL('api/boards.php', window.location.href);
    url.searchParams.set('zone', state.zone);
    url.searchParams.set('sheet', state.sheet);
    url.searchParams.set('matrix', '1');
    if (state.search) url.searchParams.set('search', state.search);
    if (state.shelf && state.shelf !== 'ALL') url.searchParams.set('shelf', state.shelf);
    if (state.period && state.period !== 'ALL') url.searchParams.set('period', state.period);
    if (state.board_type && state.board_type !== 'ALL') url.searchParams.set('board_type', state.board_type);
    if (state.status && state.status !== 'ALL') url.searchParams.set('status', state.status);
    if (state.other && state.other !== 'ALL') url.searchParams.set('other', state.other);
    return url.toString();
}

function setMatrixBusy(on) {
    const v = on ? 'true' : 'false';
    matrixWrapper?.setAttribute('aria-busy', v);
    document.querySelectorAll('#defect-matrix-zones [data-dm-wrapper]').forEach(w => w.setAttribute('aria-busy', v));
}

// Section / zone heading, same text for the single-zone matrix and for every zone of the All Zones view
function matrixTitleText(zoneKey) {
    if (isAllZonesJs(zoneKey)) {
        return 'Defect Matrix: All Size Magazines — รวมทุกโซน (ตารางข้อบกพร่องทุก Shelf ทุกโซน)';
    }
    return `Defect Matrix: All Size Magazines — โซน ${zoneKey} (ตารางข้อบกพร่องทุก Shelf ในโซน)`;
}

async function loadDefectMatrix() {
    const isAllSheets = syncShelfViewMode();
    if (!zoneHasSheetsJs(state.zone) || !isAllSheets) {
        matrixInflight?.abort();
        return;
    }
    const view = matrixView;

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
    if (Array.isArray(json.period_options)) {
        renderPeriodFilterOptions(json.period_options);
    }

    matrixView.status = 'ready';
    matrixView.rows = json.data;
    matrixView.total = Number(json.total ?? json.data.length);
    setMatrixBusy(false);
    renderDefectMatrix();
}
window.loadDefectMatrix = loadDefectMatrix;

/**
 * The scrolling matrix table for one set of rows (loading / error / empty / ready), shared by the single-zone matrix
 * and by every zone section of the All Zones view.
 */
function matrixTableHtml(status, rows) {
    const defectEntries = getDefectEntries();
    const isAllZones = isAllZonesJs(state.zone);
    const totalCols = (isAllZones ? 5 : 4) + defectEntries.length + 1; // Code, Shelf, (Zone), Product, G+D + 13 defects + Other

    const headHtml = [
        `<th scope="col" class="dm-th dm-sticky-col-1" data-col="code" title="Number Code">Number Code</th>`,
        `<th scope="col" class="dm-th dm-sticky-col-2" data-col="shelf" title="Shelf">Shelf</th>`,
        ...(isAllZones ? [`<th scope="col" class="dm-th" data-col="zone" title="Zone">Zone</th>`] : []),
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
    if (status === 'loading') {
        tbodyInner = `<tr><td colspan="${totalCols}" class="px-4 py-14 text-center text-xs text-slate-500">
            <div class="flex flex-col items-center gap-2" role="status">
                <svg class="h-5 w-5 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span>${h(MSG.loading)}</span>
            </div>
        </td></tr>`;
    } else if (status === 'error') {
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
    } else if (rows.length === 0) {
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
        tbodyInner = rows.map(r => {
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

            const zoneCell = isAllZones
                ? `<td class="dm-td" data-col="zone" title="${h(zoneMetaJs(r.zone).label)}"><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">${h(zoneMetaJs(r.zone).short || r.zone)}</span></td>`
                : '';

            return `<tr class="dm-row" data-id="${h(r.id)}" data-defects="${h(rowDefectKeys.join(','))}" tabindex="0">` +
                `<td class="dm-td dm-sticky-col-1" data-col="code" title="${h(r.code)}">${cellText(r.code)}</td>` +
                `<td class="dm-td dm-sticky-col-2" data-col="shelf" title="${h(r.shelf)}${isAllZones ? ` (${r.zone})` : ''}">${cellShelf(r.shelf)}</td>` +
                zoneCell +
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

    const hasRows = status === 'ready' && rows.length > 0;
    return `
        <div class="dm-table-scroll" data-has-rows="${hasRows ? '1' : '0'}">
            <table class="dm-table">
                <caption class="sr-only">Defect Matrix All Sheets</caption>
                <thead><tr>${headHtml}</tr></thead>
                <tbody class="dm-tbody">${tbodyInner}</tbody>
                <tfoot>
                    <tr>
                        <td class="dm-tfoot-td dm-sticky-col-1 font-mono text-slate-800" title="Total (รวมจำนวนบอร์ด)">Total</td>
                        <td class="dm-tfoot-td dm-sticky-col-2 font-mono text-blue-700">${rows.length}</td>
                        ${isAllZones ? '<td class="dm-tfoot-td text-center text-[11px] text-slate-400 font-mono">-</td>' : ''}
                        <td class="dm-tfoot-td text-right pr-2 text-[11px] font-semibold text-slate-600" title="รวมจำนวนบอร์ดต่อรหัส Defect">รวม Defect:</td>
                        <td class="dm-tfoot-td text-center text-[11px] font-semibold text-slate-500">-</td>
                        ${tfootDefectCells}
                        ${tfootOtherCell}
                    </tr>
                </tfoot>
            </table>
        </div>`;
}

function renderDefectMatrix() {
    if (!matrixWrapper) {
        matrixWrapper = document.getElementById('defect-matrix-wrapper');
    }
    if (!matrixWrapper) return;

    const counterEl = document.getElementById('defect-matrix-counter');
    if (counterEl && matrixView.status === 'ready') {
        counterEl.textContent = `${matrixView.rows.length} rows`;
    }

    const titleEl = document.getElementById('defect-matrix-title');
    if (titleEl) {
        titleEl.textContent = matrixTitleText(state.zone);
    }

    matrixWrapper.innerHTML = matrixTableHtml(matrixView.status, matrixView.rows);
    syncMatrixStickyOffsets();
}

function renderDefectMatrixZones() {
    renderDefectMatrix();
}

function syncMatrixStickyOffsets() {
    document.querySelectorAll('[data-dm-wrapper]').forEach(wrap => {
        const table = wrap.querySelector('.dm-table');
        const col1Th = wrap.querySelector('thead .dm-sticky-col-1');
        if (table && col1Th) {
            const w = col1Th.getBoundingClientRect().width;
            if (w > 0) {
                table.style.setProperty('--dm-col1-w', `${Math.round(w)}px`);
            }
        }
    });
}
window.addEventListener('resize', syncMatrixStickyOffsets);

// Every matrix wrapper inside a listener root: the single #defect-matrix-wrapper, or each zone of #defect-matrix-zones
function matrixWrappersIn(root) {
    if (root.matches?.('[data-dm-wrapper]')) return [root];
    return Array.from(root.querySelectorAll('[data-dm-wrapper]'));
}

// Legend chips that belong to a wrapper: the same section's legend
function matrixLegendOf(wrap) {
    return wrap.closest('section')?.querySelector('.dm-legend') ?? null;
}

function clearMatrixHighlights(wrap) {
    if (!wrap) return;
    wrap.querySelectorAll('.is-col-active, .is-col-cursor').forEach(el => {
        el.classList.remove('is-col-active', 'is-col-cursor');
    });
    matrixLegendOf(wrap)?.querySelectorAll('.dm-legend-item.is-legend-active').forEach(el => {
        el.classList.remove('is-legend-active');
    });
}

function highlightMatrixRowAndCol(wrap, tr, td) {
    if (!wrap) return;
    const defectSet = new Set((tr?.dataset.defects || '').split(',').filter(Boolean));
    const cursorCol = td?.dataset.col || '';
    const cursorDefect = td?.dataset.defectCode || '';

    wrap.querySelectorAll('thead .dm-th').forEach(th => {
        const dCode = th.dataset.defectCode || '';
        const isDefectMatch = Boolean(dCode && (defectSet.has(dCode) || dCode === cursorDefect));
        const isCursorMatch = Boolean(cursorCol && th.dataset.col === cursorCol);
        th.classList.toggle('is-col-active', isDefectMatch);
        th.classList.toggle('is-col-cursor', isCursorMatch && !isDefectMatch);
    });

    wrap.querySelectorAll('tfoot .dm-tfoot-td[data-defect-code]').forEach(ft => {
        const dCode = ft.dataset.defectCode || '';
        ft.classList.toggle('is-col-active', Boolean(dCode && (defectSet.has(dCode) || dCode === cursorDefect)));
    });

    matrixLegendOf(wrap)?.querySelectorAll('.dm-legend-item[data-legend-code]').forEach(chip => {
        const lCode = chip.dataset.legendCode || '';
        chip.classList.toggle('is-legend-active', Boolean(lCode && (defectSet.has(lCode) || lCode === cursorDefect)));
    });
}

// Listeners (one set per root, so a zone section built later by JS needs none of its own):
// row click / keyboard opens Edit, Retry, row + column highlight
function bindDefectMatrixRoot(root) {
    if (!root) return;
    const wrapOf = (el) => el.closest?.('[data-dm-wrapper]') ?? null;

    root.addEventListener('click', (e) => {
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

    root.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target.closest('button, a, input, select, textarea')) return;
        const tr = e.target.closest('tr.dm-row[data-id]');
        if (!tr || !tr.dataset.id) return;
        e.preventDefault();
        if (gmIsOpen(modal) || gmIsOpen(rackConfirm)) return;
        openEditModal(tr.dataset.id);
    });

    root.addEventListener('pointerover', (e) => {
        matrixWrappersIn(root).forEach(clearMatrixHighlights);
        const tr = e.target.closest('tr.dm-row[data-id]');
        if (!tr) return;
        const td = e.target.closest('td.dm-td');
        highlightMatrixRowAndCol(wrapOf(tr), tr, td);
    });

    root.addEventListener('pointerleave', () => {
        matrixWrappersIn(root).forEach(clearMatrixHighlights);
    });

    root.addEventListener('focusin', (e) => {
        const tr = e.target.closest('tr.dm-row[data-id]');
        if (tr) highlightMatrixRowAndCol(wrapOf(tr), tr, null);
    });

    root.addEventListener('focusout', (e) => {
        const wrap = wrapOf(e.target);
        if (wrap && !wrap.contains(e.relatedTarget)) {
            clearMatrixHighlights(wrap);
        }
    });
}

function setupDefectMatrixEvents() {
    bindDefectMatrixRoot(matrixWrapper);
    bindDefectMatrixRoot(document.getElementById('defect-matrix-zones'));
}
