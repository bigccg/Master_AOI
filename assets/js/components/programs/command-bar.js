/**
 * AOI Program Tracker - Command Bar & Filter Handlers
 */

let searchDebounceTimer = null;

function initProgCommandBar() {
  const inputSearch = document.getElementById('prog-search-input');
  const btnClearSearch = document.getElementById('prog-search-clear');
  const selMachine = document.getElementById('prog-filter-machine');
  const selOwner = document.getElementById('prog-filter-owner');
  const selStatus = document.getElementById('prog-filter-status');
  const selStage = document.getElementById('prog-filter-stage');
  const selPageSize = document.getElementById('prog-page-size');
  const btnRefresh = document.getElementById('prog-refresh-btn');
  const btnClearAll = document.getElementById('prog-clear-filters-btn');
  const btnDensity = document.getElementById('prog-density-btn');
  const densityLabel = document.getElementById('prog-density-label');

  // Search input debounce (200ms)
  inputSearch?.addEventListener('input', (e) => {
    const val = e.target.value.trim();
    if (btnClearSearch) {
      btnClearSearch.classList.toggle('is-active', val !== '');
    }
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
      progState.search = val;
      progState.page = 1;
      loadProgramsData();
    }, 200);
  });

  // Search clear button
  btnClearSearch?.addEventListener('click', () => {
    if (inputSearch) inputSearch.value = '';
    btnClearSearch.classList.remove('is-active');
    progState.search = '';
    progState.page = 1;
    loadProgramsData();
    inputSearch?.focus();
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

  btnRefresh?.addEventListener('click', () => {
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
    const map = { compact: 'กะทัดรัด (34px)', standard: 'มาตรฐาน (44px)', comfortable: 'สบายตา (52px)' };
    label.textContent = map[density] || 'มาตรฐาน';
  }
}

function applySavedView(viewKey) {
  // Update UI pills
  document.querySelectorAll('.prog-view-pill').forEach(p => {
    p.classList.toggle('is-active', p.getAttribute('data-saved-view') === viewKey);
  });

  if (viewKey === 'all') {
    progState.status = 'ALL';
    progState.owner = 'ALL';
    progState.machine = 'ALL';
    progState.stage = 'ALL';
  } else if (viewKey === 'my_tasks') {
    progState.owner = progState.currentUser;
    progState.status = 'ALL';
  } else if (viewKey === 'overdue') {
    progState.status = 'overdue';
  } else if (viewKey === 'unassigned') {
    progState.owner = 'unassigned';
  } else if (viewKey === 'complete') {
    progState.status = 'complete';
  }

  progState.page = 1;
  syncFilterControlsFromState();
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

  const inputSearch = document.getElementById('prog-search-input');
  if (inputSearch) inputSearch.value = '';

  const btnClearSearch = document.getElementById('prog-search-clear');
  if (btnClearSearch) btnClearSearch.classList.remove('is-active');

  syncFilterControlsFromState();
  loadProgramsData();
}

function populateProgOwners(ownersList) {
  const selOwner = document.getElementById('prog-filter-owner');
  if (selOwner) {
    const currentVal = selOwner.value;
    selOwner.innerHTML = `
      <option value="ALL">ผู้ทำ: ทั้งหมด</option>
      <option value="unassigned">-- ยังไม่มอบหมาย --</option>
      ${ownersList.map(o => `<option value="${h(o)}">${h(o)}</option>`).join('')}
    `;
    selOwner.value = currentVal;
  }

  // Also populate bulk owner list
  const bulkOwnerList = document.getElementById('prog-bulk-owner-list');
  if (bulkOwnerList) {
    bulkOwnerList.innerHTML = ownersList.map(o => `
      <button type="button" class="prog-dropdown-item" data-bulk-owner="${h(o)}">
        <i class="fa-solid fa-user text-slate-400 mr-1.5"></i>${h(o)}
      </button>
    `).join('');

    bulkOwnerList.querySelectorAll('[data-bulk-owner]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const ownerMenu = document.getElementById('prog-bulk-owner-menu');
        ownerMenu?.classList.remove('is-open');
        const targetOwner = btn.getAttribute('data-bulk-owner');
        try {
          const res = await apiBulkAction('assign_owner', Array.from(progState.selectedIds), { owner: targetOwner });
          showProgToast('มอบหมายสำเร็จ', `มอบหมายให้ "${targetOwner}" สำหรับ ${res.updated_count || progState.selectedIds.size} รายการ`);
          progState.selectedIds.clear();
          await loadProgramsData();
        } catch (err) {
          showProgErrorToast('มอบหมายไม่สำเร็จ', err.message);
        }
      });
    });
  }
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
    const sMap = { complete: 'เสร็จสมบูรณ์', wait_master: 'รอ Master', queued: 'รอทำโปรแกรม', overdue: 'ค้างนาน' };
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
      <button type="button" class="prog-chip-remove" data-remove-chip="${c.key}" title="ลบตัวกรองนี้">
        <i class="fa-solid fa-xmark text-[11px]"></i>
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
      loadProgramsData();
    });
  });

  const btnClearAllChips = document.getElementById('prog-chips-clear-all');
  btnClearAllChips?.addEventListener('click', resetAllProgFilters);
}
