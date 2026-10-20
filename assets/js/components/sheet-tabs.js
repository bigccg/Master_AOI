/**
 * Zone selector + Sheet tabs + switching between Rack Map (single sheet), Defect Matrix (All Sheets in zone),
 * and Empty Zone state (when selected zone has no sheets configured).
 */

function syncShelfViewMode() {
    const hasSheets   = zoneHasSheetsJs(state.zone);
    const isAllSheets = state.sheet === 'ALL';

    const rackSection   = document.getElementById('rack-map');
    const matrixSection = document.getElementById('defect-matrix');

    if (rackSection)   rackSection.classList.toggle('hidden', !hasSheets || isAllSheets);
    if (matrixSection) matrixSection.classList.toggle('hidden', !hasSheets || !isAllSheets);
    if (hasSheets && isAllSheets) requestAnimationFrame(syncMatrixStickyOffsets);

    // Update Defect Matrix header title with current zone
    const matrixTitle = document.getElementById('defect-matrix-title');
    if (matrixTitle) {
        matrixTitle.textContent = `Defect Matrix: All Size Magazines — โซน ${state.zone} (ตารางข้อบกพร่องทุกชีตในโซน)`;
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
        newBtn.title = blocked || `เพิ่มข้อมูลบอร์ดลงในชีต ${state.sheet} / Add board record to ${state.sheet}`;
    }
    return isAllSheets;
}
window.syncShelfViewMode = syncShelfViewMode;

function renderZoneTabs(zone = state.zone, activeSheet = state.sheet, tabCounts = {}) {
    const listEl = document.getElementById('sheet-tabs-list');
    if (!listEl) return;
    renderSheetTabNotes(zone, activeSheet, tabCounts);

    const allKeys   = getZoneSheetKeysJs(zone, true);   // disabled sheets have no tab but count toward All Size
    const sheetKeys = getZoneSheetKeysJs(zone);

    if (allKeys.length === 0) {
        listEl.innerHTML = `
            <span id="zone-no-sheets-pill" role="status" class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5">
              <i class="fa-solid fa-circle-info text-amber-600" aria-hidden="true"></i>
              <span>โซน ${h(zone)} ยังไม่มีชีตในระบบ / No sheets configured in ${h(zone)} zone</span>
            </span>`;
        return;
    }

    const activeCls   = 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer';
    const inactiveCls = 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer';

    const allCount  = tabCounts.ALL ?? allKeys.reduce((acc, k) => acc + (Number(tabCounts[k]) || 0), 0);
    const allActive = activeSheet === 'ALL';
    const allHref   = `?zone=${encodeURIComponent(zone)}&sheet=ALL`;
    // A disabled sheet opened by link has no tab: keep the All tab in the Tab order then
    const allTabIndex = (allActive || !sheetKeys.includes(activeSheet)) ? '0' : '-1';

    let html = `
        <a href="${h(allHref)}" data-sheet="ALL" id="tab-ALL" role="tab"
           aria-selected="${allActive ? 'true' : 'false'}" tabindex="${allTabIndex}"
           title="ดูข้อมูลรวมทุกชีตภายในโซน ${h(zone)} / View all sheets within ${h(zone)} zone"
           class="${allActive ? activeCls : inactiveCls}">
          <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block bg-blue-600" aria-hidden="true"></span>
          <span class="tab-label">ทุกขนาด (All Size Magazine)</span>
          <span class="tab-count bg-emerald-100 text-emerald-800 text-[10px] px-1.5 rounded font-mono ${allCount === 0 ? 'opacity-50' : ''}">${allCount}</span>
        </a>`;

    sheetKeys.forEach(k => {
        const meta   = sheetMetaJs(k) || { label: k, dot: '' };
        const label  = meta.label || k;
        const dot    = meta.dot;
        const n      = Number(tabCounts[k] ?? 0);
        const active = k === activeSheet;
        const href   = `?zone=${encodeURIComponent(zone)}&sheet=${encodeURIComponent(k)}`;
        html += `
        <a href="${h(href)}" data-sheet="${h(k)}" id="tab-${h(k)}" role="tab"
           aria-selected="${active ? 'true' : 'false'}" tabindex="${active ? '0' : '-1'}"
           title="เลือกชีต ${h(label)} (โซน ${h(zone)}) / Select sheet ${h(label)}"
           class="${active ? activeCls : inactiveCls}">
          <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block ${h(dot)}" aria-hidden="true"></span>
          <span class="tab-label">${h(label)}</span>
          <span class="tab-count bg-emerald-100 text-emerald-800 text-[10px] px-1.5 rounded font-mono ${n === 0 ? 'opacity-50' : ''}">${n}</span>
        </a>`;
    });

    listEl.innerHTML = html;
}
window.renderZoneTabs = renderZoneTabs;

// Disabled zone / sheet notes next to the tabs (components/sheet-tabs.php #sheet-tabs-notes)
function renderSheetTabNotes(zone = state.zone, activeSheet = state.sheet, tabCounts = {}) {
    const box = document.getElementById('sheet-tabs-notes');
    if (!box) return;
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
        html += pill('fa-circle-info text-amber-600', 'ยังไม่มีชีตที่เปิดใช้งาน / No active sheets', NO_ACTIVE_SHEET_INSERT_MSG);
    }
    if (activeSheet !== 'ALL' && sheetMetaJs(activeSheet) && !sheetMetaJs(activeSheet).active) {
        const n = Number(tabCounts[activeSheet] ?? 0);
        html += pill('fa-eye', `ชีต ${activeSheet} ถูกปิดใช้งาน · ดูได้อย่างเดียว / Sheet ${activeSheet} disabled · view only`,
            'ชีตนี้ไม่แสดงในแท็บและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังดู แก้ไข และส่งออกได้ / This sheet is hidden from the tabs and takes no new records; its existing records can still be viewed, edited and exported.',
            `<span class="tab-count bg-amber-100 text-amber-800 text-[10px] px-1.5 rounded font-mono" data-sheet-count="${h(activeSheet)}">${n}</span>`);
    }
    box.innerHTML = html;
}

// Zone selector options: every enabled zone + the current one while a direct link shows a disabled zone
function renderZoneSelect() {
    const sel = document.getElementById('zone-select');
    if (!sel) return;
    const t = topo();
    sel.innerHTML = t.zoneOrder
        .filter(k => t.zones[k].active || k === state.zone)
        .map(k => `<option value="${h(k)}"${k === state.zone ? ' selected' : ''}>${h(zoneOptionLabelJs(k))}</option>`)
        .join('');
    sel.value = state.zone;
}
window.renderZoneSelect = renderZoneSelect;

function renderTabCounts(tabCounts) {
    // Ensure tabs match current zone first
    const existingTabs = document.querySelectorAll('#sheet-tabs-list a[data-sheet]');
    const expectedKeys = getZoneSheetKeysJs(state.zone);
    if (expectedKeys.length === 0 || existingTabs.length !== expectedKeys.length + 1) {
        renderZoneTabs(state.zone, state.sheet, tabCounts || {});
        return;
    }
    existingTabs.forEach(a => {
        const sh = a.dataset.sheet;
        const countSpan = a.querySelector('.tab-count');
        const n = tabCounts[sh] ?? 0;
        if (countSpan) {
            countSpan.textContent = n;
            countSpan.classList.toggle('opacity-50', n === 0);
        }
    });
    renderSheetTabNotes(state.zone, state.sheet, tabCounts || {});
}

function updateTabActiveClasses(activeSheet) {
    const tabbable = getZoneSheetKeysJs(state.zone).includes(activeSheet) ? activeSheet : 'ALL';
    document.querySelectorAll('#sheet-tabs-list a[data-sheet]').forEach(a => {
        const isActive = a.dataset.sheet === activeSheet;
        a.setAttribute('aria-selected', isActive ? 'true' : 'false');
        a.setAttribute('tabindex', a.dataset.sheet === tabbable ? '0' : '-1');
        if (isActive) {
            a.className = 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer';
        } else {
            a.className = 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer';
        }
    });
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

// Listeners: Zone selector + Sheet tabs (click + full keyboard navigation)
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

    const nav = document.getElementById('sheet-tabs');
    nav?.addEventListener('click', (e) => {
        const a = e.target.closest('a[data-sheet]');
        if (!a) return;
        e.preventDefault();
        switchSheet(a.dataset.sheet);
    });

    // Keyboard navigation on sheet tabs (ArrowLeft / ArrowRight / Home / End / Enter / Space)
    nav?.addEventListener('keydown', (e) => {
        const currentTab = e.target.closest('a[data-sheet]');
        if (!currentTab) return;
        const tabs = Array.from(nav.querySelectorAll('a[data-sheet]'));
        if (tabs.length === 0) return;

        const idx = tabs.indexOf(currentTab);
        let nextIdx = -1;
        if (e.key === 'ArrowRight') {
            nextIdx = (idx + 1) % tabs.length;
        } else if (e.key === 'ArrowLeft') {
            nextIdx = (idx - 1 + tabs.length) % tabs.length;
        } else if (e.key === 'Home') {
            nextIdx = 0;
        } else if (e.key === 'End') {
            nextIdx = tabs.length - 1;
        } else if (e.key === ' ' || e.key === 'Enter') {
            e.preventDefault();
            switchSheet(currentTab.dataset.sheet);
            return;
        }

        if (nextIdx >= 0) {
            e.preventDefault();
            const target = tabs[nextIdx];
            target.focus();
            switchSheet(target.dataset.sheet);
        }
    });
}
