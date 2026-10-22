/**
 * AOI Program Tracker - Command Bar & Filter Handlers
 * Search bar effects (clear button, Esc, /, count chip) are handled by shared assets/js/components/search-fx.js
 */

let progSearchDebounceTimer = null;

function initProgCommandBar() {
  const inputSearch = document.getElementById('barcode-search-input');
  const selMachine = document.getElementById('prog-filter-machine');
  const selOwner = document.getElementById('prog-filter-owner');
  const selStatus = document.getElementById('prog-filter-status');
  const selStage = document.getElementById('prog-filter-stage');
  const selPageSize = document.getElementById('prog-page-size');
  const btnClearAll = document.getElementById('prog-clear-filters-btn');
  const btnDensity = document.getElementById('prog-density-btn');

  // Search input debounce (200ms); search-fx.js dispatches 'input' on clear/Esc
  inputSearch?.addEventListener('input', (e) => {
    const val = e.target.value.trim();
    clearTimeout(progSearchDebounceTimer);
    progSearchDebounceTimer = setTimeout(() => {
      if (val === progState.search) return;
      progState.search = val;
      progState.page = 1;
      loadProgramsData();
    }, 200);
  });

  // Filter selects
  selMachine?.addEventListener('change', (e) => {
    progState.machine = e.target.value;
    progState.page = 1;
    loadProgramsData();
  });

  selOwner?.addEventListener('change', (e) => {
    progState.owner = e.target.value;
    progState.page = 1;
    loadProgramsData();
  });

  selStatus?.addEventListener('change', (e) => {
    progState.status = e.target.value;
    progState.page = 1;
    loadProgramsData();
  });

  selStage?.addEventListener('change', (e) => {
    progState.stage = e.target.value;
    progState.page = 1;
    loadProgramsData();
  });

  selPageSize?.addEventListener('change', (e) => {
    const val = e.target.value;
    progState.pageSize = val.toLowerCase() === 'all' ? 'all' : parseInt(val, 10);
    progState.page = 1;
    loadProgramsData();
  });

  btnClearAll?.addEventListener('click', () => {
    resetAllProgFilters();
  });

  // Saved Views Pills
  document.querySelectorAll('.prog-view-pill').forEach(pill => {
    pill.addEventListener('click', () => {
      const viewKey = pill.getAttribute('data-saved-view');
      applySavedView(viewKey);
    });
  });

  // Density Switcher
  applyProgDensity(progState.density);
  btnDensity?.addEventListener('click', () => {
    const densities = ['compact', 'standard', 'comfortable'];
    const nextIdx = (densities.indexOf(progState.density) + 1) % densities.length;
    progState.density = densities[nextIdx];
    setStoredItem('aoi_prog_density', progState.density);
    applyProgDensity(progState.density);
  });
}

function applyProgDensity(density) {
  const table = document.getElementById('prog-table');
  const label = document.getElementById('prog-density-label');
  if (!table) return;

  table.classList.remove('density-compact', 'density-standard', 'density-comfortable');
  table.classList.add(`density-${density}`);

  if (label) {
    const map = { compact: 'กะทัดรัด', standard: 'มาตรฐาน', comfortable: 'สบายตา' };
    label.textContent = map[density] || 'มาตรฐาน';
  }
}

function syncSavedViewsPillState() {
  let activeView = '';
  if (progState.status === 'ALL' && progState.owner === 'ALL' && progState.machine === 'ALL' && progState.stage === 'ALL') {
    activeView = 'all';
  } else if (progState.status === 'overdue' && progState.owner === 'ALL' && progState.machine === 'ALL' && progState.stage === 'ALL') {
    activeView = 'overdue';
  } else if (progState.owner === 'unassigned' && progState.status === 'ALL' && progState.machine === 'ALL' && progState.stage === 'ALL') {
    activeView = 'unassigned';
  } else if (progState.status === 'complete' && progState.owner === 'ALL' && progState.machine === 'ALL' && progState.stage === 'ALL') {
    activeView = 'complete';
  }

  document.querySelectorAll('.prog-view-pill').forEach(p => {
    p.classList.toggle('is-active', p.getAttribute('data-saved-view') === activeView);
  });
}

function applySavedView(viewKey) {
  if (viewKey === 'all') {
    progState.status = 'ALL';
    progState.owner = 'ALL';
    progState.machine = 'ALL';
    progState.stage = 'ALL';
  } else if (viewKey === 'overdue') {
    progState.status = 'overdue';
    progState.owner = 'ALL';
  } else if (viewKey === 'unassigned') {
    progState.owner = 'unassigned';
    progState.status = 'ALL';
  } else if (viewKey === 'complete') {
    progState.status = 'complete';
    progState.owner = 'ALL';
  }

  progState.page = 1;
  syncFilterControlsFromState();
  syncSavedViewsPillState();
  loadProgramsData();
}

function syncFilterControlsFromState() {
  const selMachine = document.getElementById('prog-filter-machine');
  const selOwner = document.getElementById('prog-filter-owner');
  const selStatus = document.getElementById('prog-filter-status');
  const selStage = document.getElementById('prog-filter-stage');

  if (selMachine) selMachine.value = progState.machine;
  if (selOwner) selOwner.value = progState.owner;
  if (selStatus) selStatus.value = progState.status;
  if (selStage) selStage.value = progState.stage;
}

function resetAllProgFilters() {
  progState.search = '';
  progState.machine = 'ALL';
  progState.owner = 'ALL';
  progState.status = 'ALL';
  progState.stage = 'ALL';
  progState.page = 1;

  const inputSearch = document.getElementById('barcode-search-input');
  if (inputSearch) {
    inputSearch.value = '';
    inputSearch.dispatchEvent(new Event('input', { bubbles: true }));
  }

  syncFilterControlsFromState();
  syncSavedViewsPillState();
  loadProgramsData();
}

function populateProgOwners(ownersList) {
  const selOwner = document.getElementById('prog-filter-owner');
  if (!selOwner) return;
  const currentVal = progState.owner || selOwner.value || 'ALL';
  selOwner.innerHTML = `
    <option value="ALL">ผู้ทำ: ทั้งหมด</option>
    <option value="unassigned">-- ยังไม่มอบหมาย --</option>
    ${ownersList.map(o => `<option value="${h(o)}">${h(o)}</option>`).join('')}
  `;
  selOwner.value = (currentVal === 'ALL' || currentVal === 'unassigned' || ownersList.includes(currentVal)) ? currentVal : 'ALL';
}

function renderProgActiveChips() {
  const container = document.getElementById('prog-active-chips-container');
  const list = document.getElementById('prog-chips-list');
  if (!container || !list) return;

  const chips = [];

  if (progState.machine !== 'ALL') {
    chips.push({ key: 'machine', label: `เครื่อง: ${progState.machine}`, cls: 'is-machine' });
  }
  if (progState.owner !== 'ALL') {
    const oLabel = progState.owner === 'unassigned' ? 'ยังไม่มอบหมาย' : progState.owner;
    chips.push({ key: 'owner', label: `ผู้ทำ: ${oLabel}` });
  }
  if (progState.status !== 'ALL') {
    const sMap = { complete: 'เสร็จสมบูรณ์', wait_master: 'รอ Master', queued: 'รอทำโปรแกรม', overdue: 'ค้างนาน > 14 วัน' };
    chips.push({ key: 'status', label: `สถานะ: ${sMap[progState.status] || progState.status}`, cls: 'is-status' });
  }
  if (progState.stage !== 'ALL') {
    chips.push({ key: 'stage', label: `ค้างขั้น ${progState.stage}` });
  }

  if (chips.length === 0) {
    container.style.display = 'none';
    list.innerHTML = '';
    return;
  }

  container.style.display = 'flex';
  list.innerHTML = chips.map(c => `
    <span class="prog-chip ${c.cls || ''}">
      <span>${h(c.label)}</span>
      <button type="button" class="prog-chip-remove" data-remove-chip="${c.key}" title="ลบตัวกรอง ${h(c.label)}" aria-label="ลบตัวกรอง ${h(c.label)}">
        <i class="fa-solid fa-xmark text-[11px]" aria-hidden="true"></i>
      </button>
    </span>
  `).join('');

  list.querySelectorAll('[data-remove-chip]').forEach(btn => {
    btn.addEventListener('click', () => {
      const k = btn.getAttribute('data-remove-chip');
      if (k === 'machine') progState.machine = 'ALL';
      if (k === 'owner') progState.owner = 'ALL';
      if (k === 'status') progState.status = 'ALL';
      if (k === 'stage') progState.stage = 'ALL';
      progState.page = 1;
      syncFilterControlsFromState();
      syncSavedViewsPillState();
      loadProgramsData();
    });
  });

  const btnClearAllChips = document.getElementById('prog-chips-clear-all');
  if (btnClearAllChips) {
    btnClearAllChips.onclick = resetAllProgFilters;
  }
}
