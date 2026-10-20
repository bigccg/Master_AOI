/**
 * Event wiring: each component registers its own listeners (setupXxx); global Escape / Enter keys and Back / Forward live here.
 */

function setupDelegation() {
    setupSheetTabs();
    setupPagination();
    setupTableEvents();
    setupDefectMatrixEvents();
    setupBoardModalEvents();
    setupDefectStatusEvents();
    setupRackMapEvents();
    setupRackConfirmEvents();
    setupDeleteConfirmEvents();

    // Escape closes the top-most popup
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

    // Browser navigation (Back / Forward)
    window.addEventListener('popstate', () => {
        const params     = new URLSearchParams(window.location.search);
        const zn         = normalizeZoneJs(params.get('zone') || 'General');
        const sh         = resolveSheetInZoneJs(zn, params.get('sheet') || 'ALL');
        state.zone       = zn;
        state.sheet      = sh;
        state.search     = params.get('q') || '';
        state.shelf      = params.get('shelf') || 'ALL';
        state.board_type = params.get('board_type') || 'ALL';
        state.status     = params.get('status') || 'ALL';
        state.other      = params.get('other') || 'ALL';

        const zoneSelect = document.getElementById('zone-select');
        if (zoneSelect) zoneSelect.value = state.zone;
        const filterZone = document.getElementById('filter-zone');
        if (filterZone) filterZone.value = state.zone;
        const filterSheet = document.getElementById('filter-sheet');
        if (filterSheet) filterSheet.value = state.sheet;

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

        renderZoneTabs(zn, sh);
        syncShelfViewMode();
        loadTable();
        refreshSheetData(sh);
    });
}
