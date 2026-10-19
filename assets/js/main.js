/**
 * AOI Inspection & Shelf Management System
 * Full AJAX SPA Controller — Zero Page Refreshes
 * Hand-built table, dynamic KPI, live Rack Map, modal CRUD, and smooth sheet switching.
 *
 * Plain (non-module) scripts sharing one global scope, loaded in order by components/layout/scripts.php
 * (list: includes/assets.php). This file runs last and boots the page.
 */

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
