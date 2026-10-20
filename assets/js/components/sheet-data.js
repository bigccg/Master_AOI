/**
 * Per-zone & per-sheet live data (api/sheet_data.php): feeds KPI cards, tab counts, shelf filter, rack map, modal.
 */

async function refreshSheetData(targetSheet = state.sheet) {
    try {
        const url = `api/sheet_data.php?zone=${encodeURIComponent(state.zone)}&sheet=${encodeURIComponent(targetSheet)}`;
        const res = await fetch(url);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (!json.success || !json.data) throw new Error('Invalid sheet data');

        currentSheetData = json.data;
        if (json.data.defect_labels) {
            window.DEFECT_LABELS = json.data.defect_labels;
        }
        renderKpi(json.data.kpi, json.data.shelf_prefix, json.data.has_sheets);
        renderTabCounts(json.data.tab_counts);
        renderShelfFilterOptions(json.data.mag_options);
        if (json.data.has_sheets) {
            renderRackMap(json.data.rack, json.data.shelf_prefix);
            updateModalFreeShelves(json.data.free_by_mag, json.data.free_shelves, json.data.rack.capacity, json.data.shelf_prefix, json.data.rack.is_max);
        }

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
