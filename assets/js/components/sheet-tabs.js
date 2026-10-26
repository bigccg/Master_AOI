/**
 * Zone selector + Sheet tabs + switching between Rack Map (single sheet), Defect Matrix (All Sheets in zone),
 * and Empty Zone state (when selected zone has no sheets configured).
 */

function syncShelfViewMode() {
    const hasSheets   = zoneHasSheetsJs(state.zone);
    const isAllSheets = state.sheet === 'ALL';

    const allZones      = isAllZonesJs(state.zone);
    const rackSection   = document.getElementById('rack-map');
    const matrixSection = document.getElementById('defect-matrix');
    const zonesSection  = document.getElementById('defect-matrix-zones');

    if (rackSection)   rackSection.classList.toggle('hidden', !hasSheets || isAllSheets);
    // Defect Matrix: shown for both single zone and All Zones when All Sheets is selected
    if (matrixSection) matrixSection.classList.toggle('hidden', !hasSheets || !isAllSheets);
    if (zonesSection)  zonesSection.classList.add('hidden');
    if (hasSheets && isAllSheets) requestAnimationFrame(syncMatrixStickyOffsets);

    // Update Defect Matrix header title with current zone
    const matrixTitle = document.getElementById('defect-matrix-title');
    if (matrixTitle) {
        matrixTitle.textContent = matrixTitleText(state.zone);
    }

    // Update header zone badge
    const headerZoneText = document.getElementById('header-zone-text');
    if (headerZoneText) {
        headerZoneText.textContent = `โซน: ${zoneMetaJs(state.zone).label}`;
    }

    // Disable New Board button wherever a new record would be refused (empty / disabled zone or sheet, All Size Magazine)
    const newBtn = document.getElementById('new-entry-btn');
    if (newBtn) {
        const blocked = insertBlockedReasonJs(state.zone, state.sheet);
        newBtn.disabled = blocked !== '';
        newBtn.setAttribute('aria-disabled', blocked ? 'true' : 'false');
        newBtn.title = blocked || `เพิ่มข้อมูลบอร์ดลงใน Shelf ${state.sheet} / Add board record to ${state.sheet}`;
    }
    return isAllSheets;
}
window.syncShelfViewMode = syncShelfViewMode;

function renderZoneTabs(zone = state.zone, activeSheet = state.sheet, tabCounts = {}) {
    const sel = document.getElementById('sheet-select');
    if (!sel) return;
    renderSheetTabNotes(zone, activeSheet, tabCounts);

    const allKeys   = getZoneSheetKeysJs(zone, true);   // disabled sheets have no option but count toward All Size
    const sheetKeys = getZoneSheetKeysJs(zone);

    if (allKeys.length === 0) {
        sel.disabled = true;
        sel.innerHTML = '<option value="ALL" id="tab-ALL">ไม่มี Shelf ในโซนนี้ / No shelves</option>';
        sel.value = 'ALL';
        return;
    }

    // All Zones: shelves belong to different zones, so there is no shelf to pick; the view is All Size Magazine of every zone
    if (isAllZonesJs(zone)) {
        sel.disabled = true;
        const total = tabCounts.ALL ?? currentSheetData?.tab_counts?.ALL ?? 0;
        sel.innerHTML = `<option value="ALL" id="tab-ALL" selected>ทุกขนาด (All Size Magazine) (${total})</option>`;
        sel.value = 'ALL';
        return;
    }

    sel.disabled = false;
    const allCount  = tabCounts.ALL ?? allKeys.reduce((acc, k) => acc + (Number(tabCounts[k]) || 0), 0);
    const allActive = activeSheet === 'ALL';

    let html = `
        <option value="ALL" id="tab-ALL"${allActive ? ' selected' : ''}>ทุกขนาด (All Size Magazine) (${allCount})</option>`;

    sheetKeys.forEach(k => {
        const meta   = sheetMetaJs(k) || { label: k, dot: '' };
        const label  = meta.label || k;
        const n      = Number(tabCounts[k] ?? 0);
        const active = k === activeSheet;
        html += `
        <option value="${h(k)}" id="tab-${h(k)}"${active ? ' selected' : ''}>${h(label)} (${n})</option>`;
    });

    if (activeSheet !== 'ALL' && !sheetKeys.includes(activeSheet)) {
        const meta  = sheetMetaJs(activeSheet) || { label: activeSheet };
        const label = meta.label || activeSheet;
        const n     = Number(tabCounts[activeSheet] ?? 0);
        html += `
        <option value="${h(activeSheet)}" id="tab-${h(activeSheet)}" selected>${h(label)} (ปิดใช้งาน / Disabled) (${n})</option>`;
    }

    sel.innerHTML = html;
    sel.value = activeSheet;
}
window.renderZoneTabs = renderZoneTabs;
window.renderSheetSelect = renderZoneTabs;

// Disabled zone / sheet notes next to the tabs (components/sheet-tabs.php #sheet-tabs-notes)
function renderSheetTabNotes(zone = state.zone, activeSheet = state.sheet, tabCounts = {}) {
    const box = document.getElementById('sheet-tabs-notes');
    if (!box) return;
    if (isAllZonesJs(zone)) {   // All Zones: no per-zone / per-shelf notes
        box.innerHTML = '';
        return;
    }
    const pill = (icon, text, title, extra = '') => `
        <span role="status" title="${h(title)}" class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5">
          <i class="fa-solid ${icon}" aria-hidden="true"></i><span>${h(text)}</span>${extra}
        </span>`;

    const hasSheets = getZoneSheetKeysJs(zone, true).length > 0;
    let html = '';
    if (hasSheets && !zoneMetaJs(zone).active) {
        html += pill('fa-eye', 'โซนนี้ถูกปิดใช้งาน · ดูได้อย่างเดียว / Zone disabled · view only',
            'ผู้ดูแลระบบปิดใช้งานโซนนี้ จึงไม่แสดงในตัวเลือกโซนและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังดูและส่งออกได้ / An administrator disabled this zone: it is hidden from the zone selector and takes no new records. Existing records can still be viewed and exported.');
    } else if (hasSheets && getZoneSheetKeysJs(zone).length === 0) {
        html += pill('fa-circle-info text-amber-600', 'ยังไม่มี Shelf ที่เปิดใช้งาน / No active shelves', NO_ACTIVE_SHEET_INSERT_MSG);
    }
    if (activeSheet !== 'ALL' && sheetMetaJs(activeSheet) && !sheetMetaJs(activeSheet).active) {
        const n = Number(tabCounts[activeSheet] ?? 0);
        html += pill('fa-eye', `Shelf ${activeSheet} ถูกปิดใช้งาน · ดูได้อย่างเดียว / Shelf ${activeSheet} disabled · view only`,
            'Shelf นี้ไม่แสดงในแท็บและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังดู แก้ไข และส่งออกได้ / This shelf is hidden from the tabs and takes no new records; its existing records can still be viewed, edited and exported.',
            `<span class="tab-count bg-amber-100 text-amber-800 text-[10px] px-1.5 rounded font-mono" data-sheet-count="${h(activeSheet)}">${n}</span>`);
    }
    box.innerHTML = html;
}

// Zone selector options: every enabled zone + the current one while a direct link shows a disabled zone
function renderZoneSelect() {
    const sel = document.getElementById('zone-select');
    if (!sel) return;
    const t = topo();
    const allZonesOpt = `<option value="${ALL_ZONES_KEY}"${isAllZonesJs(state.zone) ? ' selected' : ''}>${h(zoneOptionLabelJs(ALL_ZONES_KEY))}</option>`;
    sel.innerHTML = allZonesOpt + t.zoneOrder
        .filter(k => t.zones[k].active || k === state.zone)
        .map(k => `<option value="${h(k)}"${k === state.zone ? ' selected' : ''}>${h(zoneOptionLabelJs(k))}</option>`)
        .join('');
    sel.value = state.zone;
}
window.renderZoneSelect = renderZoneSelect;

function renderTabCounts(tabCounts) {
    const sel = document.getElementById('sheet-select');
    if (!sel) return;
    if (isAllZonesJs(state.zone)) {
        renderZoneTabs(state.zone, 'ALL', tabCounts || {});
        return;
    }
    const allKeys   = getZoneSheetKeysJs(state.zone, true);
    const sheetKeys = getZoneSheetKeysJs(state.zone);
    if (allKeys.length === 0) {
        renderZoneTabs(state.zone, state.sheet, tabCounts || {});
        return;
    }

    const counts = tabCounts || {};
    const allCount = counts.ALL ?? allKeys.reduce((acc, k) => acc + (Number(counts[k]) || 0), 0);

    const optAll = sel.querySelector('option[value="ALL"]');
    if (optAll) {
        optAll.textContent = `ทุกขนาด (All Size Magazine) (${allCount})`;
    }

    sheetKeys.forEach(k => {
        const opt = sel.querySelector(`option[value="${CSS.escape(k)}"]`);
        if (opt) {
            const meta  = sheetMetaJs(k) || { label: k };
            const label = meta.label || k;
            const n     = Number(counts[k] ?? 0);
            opt.textContent = `${label} (${n})`;
        }
    });

    if (state.sheet !== 'ALL' && !sheetKeys.includes(state.sheet)) {
        const opt = sel.querySelector(`option[value="${CSS.escape(state.sheet)}"]`);
        if (opt) {
            const meta  = sheetMetaJs(state.sheet) || { label: state.sheet };
            const label = meta.label || state.sheet;
            const n     = Number(counts[state.sheet] ?? 0);
            opt.textContent = `${label} (ปิดใช้งาน / Disabled) (${n})`;
        }
    }

    renderSheetTabNotes(state.zone, state.sheet, counts);
}

function updateTabActiveClasses(activeSheet) {
    const sel = document.getElementById('sheet-select');
    if (sel && sel.value !== activeSheet) {
        sel.value = activeSheet;
    }
}

async function switchZone(newZone) {
    const targetZone = normalizeZoneJs(newZone);
    if (targetZone === state.zone) return;

    state.zone  = targetZone;
    // If current sheet is not in the newly selected zone, fall back to 'ALL' of that zone
    state.sheet = resolveSheetInZoneJs(targetZone, state.sheet);
    state.page  = 1;
    state.shelf = 'ALL';

    renderZoneSelect();   // drops a disabled zone that was only listed while it was current
    const filterZoneHidden = document.getElementById('filter-zone');
    if (filterZoneHidden) filterZoneHidden.value = targetZone;
    const filterSheetHidden = document.getElementById('filter-sheet');
    if (filterSheetHidden) filterSheetHidden.value = state.sheet;
    const modalZoneHidden = document.getElementById('modal-zone');
    if (modalZoneHidden) modalZoneHidden.value = targetZone;

    const filterShelf = document.getElementById('filter-shelf');
    if (filterShelf) filterShelf.value = 'ALL';

    renderZoneTabs(state.zone, state.sheet);
    syncShelfViewMode();
    syncBrowserUrl();

    await Promise.all([
        loadTable(),
        refreshSheetData(state.sheet)
    ]);
}
window.switchZone = switchZone;

async function switchSheet(newSheet) {
    const validSheet = resolveSheetInZoneJs(state.zone, newSheet);
    if (!validSheet || validSheet === state.sheet) return;

    state.sheet = validSheet;
    state.page  = 1;
    state.shelf = 'ALL';

    const filterShelf = document.getElementById('filter-shelf');
    if (filterShelf) filterShelf.value = 'ALL';

    const filterSheetHidden = document.getElementById('filter-sheet');
    if (filterSheetHidden) filterSheetHidden.value = validSheet;

    updateTabActiveClasses(validSheet);
    renderSheetTabNotes(state.zone, validSheet);
    syncShelfViewMode();
    syncBrowserUrl();

    await Promise.all([
        loadTable(),
        refreshSheetData(validSheet)
    ]);
}
window.switchSheet = switchSheet;

/**
 * Take a new topology (api/topology.php after an administrator saved) and bring the page in line without a reload:
 * zone selector, sheet tabs, header badge, Add Board button, table, KPI, rack map. A zone / sheet that no longer
 * exists falls back like a stale link (default zone / All Size Magazine); a disabled one stays open read-only.
 */
async function applyTopology(newTopology) {
    if (!newTopology || !newTopology.zones) return;
    window.TOPOLOGY = newTopology;

    const zone  = normalizeZoneJs(state.zone);
    const sheet = resolveSheetInZoneJs(zone, state.sheet);
    if (zone !== state.zone || sheet !== state.sheet) {
        state.page  = 1;
        state.shelf = 'ALL';
        const filterShelf = document.getElementById('filter-shelf');
        if (filterShelf) filterShelf.value = 'ALL';
    }
    state.zone  = zone;
    state.sheet = sheet;
    ['filter-zone', 'modal-zone'].forEach(id => { const el = document.getElementById(id); if (el) el.value = zone; });
    const filterSheet = document.getElementById('filter-sheet');
    if (filterSheet) filterSheet.value = sheet;

    renderZoneSelect();
    renderZoneTabs(zone, sheet);
    syncShelfViewMode();
    syncBrowserUrl();
    await Promise.all([loadTable(), refreshSheetData(sheet)]);
}
window.applyTopology = applyTopology;


async function saveTabOrder(zoneKey, newOrderKeys) {
    if (!window.isAdmin) return;
    try {
        const res = await fetch('api/topology.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'sheet_reorder',
                zone_key: zoneKey,
                order: newOrderKeys,
                csrf: window.csrfToken
            })
        });
        const json = await res.json();
        if (json.success && json.topology) {
            await applyTopology(json.topology);
            showFlash('ok', json.message || 'จัดลำดับ Shelf เรียบร้อยแล้ว / Shelf order saved.');
        } else {
            renderZoneTabs(state.zone, state.sheet);
            const err = (json.errors && json.errors[0]) || 'บันทึกการจัดลำดับไม่สำเร็จ / Could not save shelf order.';
            showFlash('err', err);
        }
    } catch (err) {
        console.error('saveTabOrder error:', err);
        renderZoneTabs(state.zone, state.sheet);
        showFlash('err', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์ / Network error.');
    }
}

// Listeners: Zone selector + Shelf selector
function setupSheetTabs() {
    const zoneSelect = document.getElementById('zone-select');
    if (zoneSelect) {
        zoneSelect.addEventListener('change', (e) => {
            switchZone(e.target.value);
        });
        zoneSelect.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                zoneSelect.blur();
            }
        });
    }

    const sheetSelect = document.getElementById('sheet-select');
    if (sheetSelect) {
        sheetSelect.addEventListener('change', (e) => {
            switchSheet(e.target.value);
        });
        sheetSelect.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                sheetSelect.blur();
            }
        });
    }
}


