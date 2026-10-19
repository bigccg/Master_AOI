/**
 * Sheet tabs + switching between Rack Map (one sheet) and Defect Matrix (All Size Magazine).
 */

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

// Listeners: tab clicks
function setupSheetTabs() {
    // Sheet tabs navigation
    document.getElementById('sheet-tabs')?.addEventListener('click', (e) => {
        const a = e.target.closest('a[data-sheet]');
        if (!a) return;
        e.preventDefault();
        switchSheet(a.dataset.sheet);
    });
}
