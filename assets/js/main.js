/**
 * AOI Inspection & Shelf Management System
 * Full AJAX SPA Controller — Zero Page Refreshes
 * Hand-built table, dynamic KPI, live Rack Map, modal CRUD, and smooth zone & sheet switching.
 *
 * Plain (non-module) scripts sharing one global scope, loaded in order by components/layout/scripts.php
 * (list: includes/assets.php). This file runs last and boots the page.
 */

document.addEventListener('DOMContentLoaded', () => {
    tableWrapper  = document.getElementById('table-wrapper');
    pagerFooter   = document.getElementById('pagination-footer');
    matrixWrapper = document.getElementById('defect-matrix-wrapper');

    // Initialize state from URL params (validated against window.TOPOLOGY: the zones + sheets tables)
    const params     = new URLSearchParams(window.location.search);
    state.zone       = normalizeZoneJs(params.get('zone') || window.initialZone || defaultZoneJs());
    state.sheet      = resolveSheetInZoneJs(state.zone, params.get('sheet') || window.initialSheet || 'ALL');
    state.search     = params.get('q') || '';
    state.shelf      = params.get('shelf') || 'ALL';
    state.period     = params.get('period') || 'ALL';
    state.board_type = params.get('board_type') || 'ALL';
    state.status     = params.get('status') || 'ALL';
    state.other      = params.get('other') || 'ALL';

    const zoneSelect = document.getElementById('zone-select');
    if (zoneSelect) zoneSelect.value = state.zone;

    const pSize = document.getElementById('page-size-select');
    if (pSize) {
        state.pageSize = pSize.value === 'all' ? 'all' : parseInt(pSize.value, 10) || 10;
    }

    setupSearch();
    setupFilters();
    setupDelegation();
    syncShelfViewMode();
    syncBrowserUrl();

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
        window.history.replaceState({ zone: state.zone, sheet: state.sheet }, '', cleanUrl.toString());
    }
});
