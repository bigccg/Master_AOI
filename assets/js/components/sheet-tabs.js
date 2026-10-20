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
        const meta = (window.ZONE_META && window.ZONE_META[state.zone]) || { label: state.zone };
        headerZoneText.textContent = `โซน: ${meta.label}`;
    }

    // Disable New Board button when in an empty zone or on All Size Magazine ("ALL")
    const newBtn = document.getElementById('new-entry-btn');
    if (newBtn) {
        const disabled = !hasSheets || isAllSheets;
        newBtn.disabled = disabled;
        newBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        newBtn.title = !hasSheets
            ? EMPTY_ZONE_INSERT_MSG
            : (isAllSheets ? ALL_SIZE_INSERT_MSG : `เพิ่มข้อมูลบอร์ดลงในชีต ${state.sheet} / Add board record to ${state.sheet}`);
    }
    return isAllSheets;
}
window.syncShelfViewMode = syncShelfViewMode;

function renderZoneTabs(zone = state.zone, activeSheet = state.sheet, tabCounts = {}) {
    const listEl = document.getElementById('sheet-tabs-list');
    if (!listEl) return;

    const sheetKeys = getZoneSheetKeysJs(zone);
    const sheetsCfg = window.SHEETS || {};

    if (sheetKeys.length === 0) {
        listEl.innerHTML = `
            <span id="zone-no-sheets-pill" role="status" class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5">
              <i class="fa-solid fa-circle-info text-amber-600" aria-hidden="true"></i>
              <span>โซน ${h(zone)} ยังไม่มีชีตในระบบ / No sheets configured in ${h(zone)} zone</span>
            </span>`;
        return;
    }

    const activeCls   = 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer';
    const inactiveCls = 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer';

    const allCount  = tabCounts.ALL ?? sheetKeys.reduce((acc, k) => acc + (Number(tabCounts[k]) || 0), 0);
    const allActive = activeSheet === 'ALL';
    const allHref   = `?zone=${encodeURIComponent(zone)}&sheet=ALL`;

    let html = `
        <a href="${h(allHref)}" data-sheet="ALL" id="tab-ALL" role="tab"
           aria-selected="${allActive ? 'true' : 'false'}" tabindex="${allActive ? '0' : '-1'}"
           title="ดูข้อมูลรวมทุกชีตภายในโซน ${h(zone)} / View all sheets within ${h(zone)} zone"
           class="${allActive ? activeCls : inactiveCls}">
          <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block bg-blue-600" aria-hidden="true"></span>
          <span class="tab-label">ทุกขนาด (All Size Magazine)</span>
          <span class="tab-count bg-emerald-100 text-emerald-800 text-[10px] px-1.5 rounded font-mono ${allCount === 0 ? 'opacity-50' : ''}">${allCount}</span>
        </a>`;

    sheetKeys.forEach(k => {
        const meta   = sheetsCfg[k] || [k, 'bg-emerald-500'];
        const label  = meta[0] || k;
        const dot    = meta[1] || 'bg-emerald-500';
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
}

function updateTabActiveClasses(activeSheet) {
    document.querySelectorAll('#sheet-tabs-list a[data-sheet]').forEach(a => {
        const isActive = a.dataset.sheet === activeSheet;
        a.setAttribute('aria-selected', isActive ? 'true' : 'false');
        a.setAttribute('tabindex', isActive ? '0' : '-1');
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

    const zoneSelect = document.getElementById('zone-select');
    if (zoneSelect && zoneSelect.value !== targetZone) {
        zoneSelect.value = targetZone;
    }
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
    syncShelfViewMode();
    syncBrowserUrl();

    await Promise.all([
        loadTable(),
        refreshSheetData(validSheet)
    ]);
}
window.switchSheet = switchSheet;

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
