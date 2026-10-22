/**
 * AOI Program Tracker - Table Rendering & Event Delegation
 */

function renderSingleRowHtml(r) {
  const isSelected = progState.selectedIds.has(r.id);
  const isOverdue = (r.overdue_days > 0);

  // Side badge
  const isFSide = String(r.side || '').toUpperCase().includes('F');
  const sideBadge = `<span class="prog-side-badge ${isFSide ? 'is-f' : 'is-b'}">${isFSide ? 'F' : 'B'}</span>`;

  // Machine badge
  const mach = String(r.machine_type || '');
  let machCls = 'is-omron';
  let machLabel = '2D';
  if (mach.includes('3D') || mach.includes('YAMAHA')) {
    machCls = 'is-yamaha';
    machLabel = '3D';
  } else if (mach.includes('TRI')) {
    machCls = 'is-tri';
    machLabel = 'TRI';
  }
  const machBadge = `<span class="prog-mach-badge ${machCls}" title="${h(mach)}">${machLabel}</span>`;

  // Stepper HTML
  const stepperHtml = renderProgStepperHtml(r);

  // Master Board Mock/Sync chips
  const masterHtml = `
    <div class="prog-master-group">
      <span class="prog-master-chip ${r.is_complete ? 'is-active-bare' : ''}" title="Bare Board">Bare</span>
      <span class="prog-master-chip ${(r.stage_2 === 2 || r.is_complete) ? 'is-active-g' : ''}" title="Good Board">G</span>
      <span class="prog-master-chip ${(r.stage_3 === 2 || r.is_complete) ? 'is-active-d' : ''}" title="Defect Board">D</span>
      <span class="prog-master-chip ${(r.stage_3 === 2 || r.is_complete) ? 'is-active-gd' : ''}" title="Combined G+D">G+D</span>
    </div>
  `;

  // Shelf badge
  const shelf = String(r.shelf || '').trim();
  let shelfHtml = '<span class="text-slate-400 text-xs italic">ยังไม่ผูก</span>';
  if (shelf) {
    const parts = shelf.split(',').map(s => s.trim()).filter(Boolean);
    if (parts.length > 2) {
      shelfHtml = `
        <div class="flex items-center gap-1">
          <span class="prog-shelf-badge">${h(parts[0])}</span>
          <span class="prog-shelf-badge">${h(parts[1])}</span>
          <span class="px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 font-mono text-[10px] font-bold cursor-pointer" title="${h(shelf)}">+${parts.length - 2}</span>
        </div>
      `;
    } else {
      shelfHtml = `<span class="prog-shelf-badge">${h(shelf)}</span>`;
    }
  }

  // Owner info
  const owner = String(r.owner || '').trim();
  let ownerHtml = `
    <button type="button" class="prog-owner-btn-ghost" data-action="assign" data-id="${r.id}" title="คลิกเพื่อมอบหมายผู้รับผิดชอบ">
      <i class="fa-solid fa-user-plus text-[10px]"></i>
      <span>มอบหมาย</span>
    </button>
  `;
  if (owner) {
    const initials = owner.substring(0, 2).toUpperCase();
    ownerHtml = `
      <div class="prog-owner-group">
        <div class="prog-owner-avatar" title="${h(owner)}">${h(initials)}</div>
        <span class="prog-owner-name truncate max-w-[85px]">${h(owner)}</span>
      </div>
    `;
  }

  // Updated date & Overdue tag
  let updatedHtml = `<span>${h(r.updated_rel || '-')}</span>`;
  if (isOverdue) {
    updatedHtml = `
      <div class="flex items-center gap-1.5">
        <span class="text-slate-500">${h(r.updated_rel || '-')}</span>
        <span class="px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-mono text-[10px] font-bold" title="ค้างมาแล้ว ${r.overdue_days} วัน">ค้าง ${r.overdue_days} วัน</span>
      </div>
    `;
  }

  // Note truncate
  const note = String(r.note || '').trim();
  const waitReason = String(r.wait_reason || '').trim();
  let noteHtml = '<span class="text-slate-300">-</span>';
  if (waitReason) {
    noteHtml = `<span class="inline-flex items-center px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 text-xs truncate max-w-[150px]" title="${h(waitReason)}: ${h(note)}">${h(waitReason)}</span>`;
  } else if (note) {
    noteHtml = `<span class="text-slate-600 truncate max-w-[160px] inline-block" title="${h(note)}">${h(note)}</span>`;
  }

  return `
    <tr class="prog-row ${isSelected ? 'is-selected' : ''} ${isOverdue ? 'is-overdue' : ''}" data-id="${r.id}" tabindex="0">
      <td class="prog-td prog-col-check prog-td-sticky-check">
        <input type="checkbox" class="prog-row-checkbox rounded accent-emerald-600 w-3.5 h-3.5 cursor-pointer align-middle" data-id="${r.id}" ${isSelected ? 'checked' : ''} aria-label="เลือกรายการ ${h(r.product_model)}">
      </td>
      <td class="prog-td prog-col-product prog-td-sticky-product">
        <div class="flex items-center gap-2">
          <span class="prog-prod-model">${h(r.product_model)}</span>
          ${sideBadge}
        </div>
        <div class="text-[11px] text-slate-400 font-mono">${h(r.family_key)}</div>
      </td>
      <td class="prog-td prog-col-machine">
        ${machBadge}
      </td>
      <td class="prog-td prog-col-stepper">
        ${stepperHtml}
      </td>
      <td class="prog-td prog-col-master">
        ${masterHtml}
      </td>
      <td class="prog-td prog-col-shelf">
        ${shelfHtml}
      </td>
      <td class="prog-td prog-col-owner">
        ${ownerHtml}
      </td>
      <td class="prog-td prog-col-updated">
        ${updatedHtml}
      </td>
      <td class="prog-td prog-col-note">
        ${noteHtml}
      </td>
      <td class="prog-td prog-col-action text-center">
        <div class="flex items-center justify-center gap-1 text-slate-400">
          <button type="button" class="p-1 hover:text-emerald-700" title="รายละเอียด / Detail" data-action="detail" data-id="${r.id}">
            <i class="fa-solid fa-chevron-right text-[12px]"></i>
          </button>
        </div>
      </td>
    </tr>
  `;
}

function renderProgTable() {
  const tbody = document.getElementById('prog-table-body');
  if (!tbody) return;

  if (progState.viewStatus === 'empty') {
    tbody.innerHTML = `
      <tr>
        <td colspan="10" class="py-16 text-center text-slate-500">
          <div class="flex flex-col items-center gap-2 max-w-sm mx-auto">
            <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 text-lg">
              <i class="fa-solid fa-inbox"></i>
            </div>
            <span class="font-semibold text-slate-700">ไม่พบรายการโปรแกรม AOI</span>
            <span class="text-xs text-slate-400 leading-relaxed">
              ${progState.search ? `ไม่มีข้อมูลตรงกับคำค้นหา "${h(progState.search)}"` : 'ไม่มีรายการในเงื่อนไขตัวกรองที่เลือก'}
            </span>
            <button type="button" onclick="resetAllProgFilters()" class="mt-2 px-3 py-1.5 rounded-md bg-white border border-slate-300 text-slate-700 text-xs font-semibold hover:bg-slate-50 shadow-sm">
              ล้างตัวกรองทั้งหมด
            </button>
          </div>
        </td>
      </tr>
    `;
    updateProgBulkBar();
    return;
  }

  tbody.innerHTML = progState.rows.map(renderSingleRowHtml).join('');
  updateProgBulkBar();
}

function updateRowInDom(row) {
  const existing = document.querySelector(`tr.prog-row[data-id="${row.id}"]`);
  if (!existing) return;
  const temp = document.createElement('tbody');
  temp.innerHTML = renderSingleRowHtml(row);
  const newTr = temp.firstElementChild;
  existing.replaceWith(newTr);
}

function prependRowToDom(row) {
  const tbody = document.getElementById('prog-table-body');
  if (!tbody) return;

  if (progState.viewStatus === 'empty') {
    tbody.innerHTML = '';
    progState.viewStatus = 'ready';
  }

  const temp = document.createElement('tbody');
  temp.innerHTML = renderSingleRowHtml(row);
  const newTr = temp.firstElementChild;
  tbody.prepend(newTr);
  flashRowInDom(row.id);
  updateProgBulkBar();
}

function flashRowInDom(id) {
  const tr = document.querySelector(`tr.prog-row[data-id="${id}"]`);
  if (!tr) return;
  tr.classList.remove('flash-update');
  void tr.offsetWidth; // trigger reflow
  tr.classList.add('flash-update');
  setTimeout(() => {
    tr.classList.remove('flash-update');
  }, 1300);
}

function updateProgBulkBar() {
  const bar = document.getElementById('prog-bulk-bar');
  const countEl = document.getElementById('prog-bulk-count');
  const checkAll = document.getElementById('prog-check-all');
  const meNameEl = document.getElementById('prog-bulk-me-name');
  if (!bar) return;

  if (meNameEl) meNameEl.textContent = progState.currentUser || 'ฉัน';

  const count = progState.selectedIds.size;
  if (count > 0) {
    bar.style.display = 'flex';
    if (countEl) countEl.textContent = count;
  } else {
    bar.style.display = 'none';
  }

  // Update check-all state
  if (checkAll) {
    const visibleCount = progState.rows.length;
    if (visibleCount > 0 && count >= visibleCount) {
      checkAll.checked = true;
      checkAll.indeterminate = false;
    } else if (count > 0) {
      checkAll.checked = false;
      checkAll.indeterminate = true;
    } else {
      checkAll.checked = false;
      checkAll.indeterminate = false;
    }
  }
}

function renderProgTableSkeleton() {
  const tbody = document.getElementById('prog-table-body');
  if (!tbody) return;

  tbody.innerHTML = Array.from({ length: 8 }).map(() => `
    <tr class="prog-row animate-pulse">
      <td class="prog-td text-center"><div class="w-3.5 h-3.5 bg-slate-200 rounded mx-auto"></div></td>
      <td class="prog-td"><div class="h-4 w-32 bg-slate-200 rounded"></div></td>
      <td class="prog-td"><div class="h-4 w-16 bg-slate-200 rounded"></div></td>
      <td class="prog-td"><div class="h-6 w-44 bg-slate-200 rounded"></div></td>
      <td class="prog-td"><div class="h-4 w-20 bg-slate-200 rounded"></div></td>
      <td class="prog-td"><div class="h-4 w-14 bg-slate-200 rounded"></div></td>
      <td class="prog-td"><div class="h-4 w-20 bg-slate-200 rounded"></div></td>
      <td class="prog-td"><div class="h-4 w-16 bg-slate-200 rounded"></div></td>
      <td class="prog-td"><div class="h-4 w-28 bg-slate-200 rounded"></div></td>
      <td class="prog-td text-center"><div class="h-4 w-8 bg-slate-200 rounded mx-auto"></div></td>
    </tr>
  `).join('');
}

function renderProgTableError(errorMsg) {
  const tbody = document.getElementById('prog-table-body');
  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="10" class="py-12 text-center text-red-600">
        <div class="flex flex-col items-center gap-2 max-w-sm mx-auto">
          <i class="fa-solid fa-triangle-exclamation text-2xl text-red-500"></i>
          <span class="font-semibold">เกิดข้อผิดพลาดในการโหลดข้อมูล</span>
          <span class="text-xs text-slate-500">${h(errorMsg)}</span>
          <button type="button" onclick="loadProgramsData()" class="mt-2 px-3 py-1.5 rounded-md bg-white border border-slate-300 text-slate-700 text-xs font-semibold hover:bg-slate-50 shadow-sm">
            ลองใหม่อีกครั้ง
          </button>
        </div>
      </td>
    </tr>
  `;
}

function renderProgPagination() {
  const summaryEl = document.getElementById('prog-paging-summary');
  const controlsEl = document.getElementById('prog-paging-controls');
  if (!summaryEl || !controlsEl) return;

  if (progState.total === 0) {
    summaryEl.textContent = 'ไม่มีข้อมูล';
    controlsEl.innerHTML = '';
    return;
  }

  const isAll = (progState.pageSize === 'all');
  const start = isAll ? 1 : ((progState.page - 1) * progState.pageSize) + 1;
  const end = isAll ? progState.total : Math.min(progState.page * progState.pageSize, progState.total);

  summaryEl.innerHTML = `แสดง <strong class="font-semibold text-slate-800">${start}–${end}</strong> จาก ${progState.total} รายการ`;

  if (isAll || progState.pages <= 1) {
    controlsEl.innerHTML = '';
    return;
  }

  let btns = '';
  // First page & Prev
  btns += `
    <button type="button" class="w-7 h-7 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition disabled:opacity-40" data-page="1" ${progState.page === 1 ? 'disabled' : ''} title="หน้าแรก">
      <i class="fa-solid fa-angles-left text-[11px]"></i>
    </button>
    <button type="button" class="w-7 h-7 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition disabled:opacity-40" data-page="${progState.page - 1}" ${progState.page === 1 ? 'disabled' : ''} title="ก่อนหน้า">
      <i class="fa-solid fa-chevron-left text-[11px]"></i>
    </button>
  `;

  // Number buttons
  const cur = progState.page;
  const maxP = progState.pages;
  const pageRange = [];

  for (let i = Math.max(1, cur - 2); i <= Math.min(maxP, cur + 2); i++) {
    pageRange.push(i);
  }

  pageRange.forEach(p => {
    const isCur = (p === cur);
    btns += `
      <button type="button" class="w-7 h-7 rounded-md font-mono text-xs font-semibold flex items-center justify-center transition ${isCur ? 'bg-emerald-600 text-white shadow-sm' : 'hover:bg-slate-100 text-slate-700'}" data-page="${p}">
        ${p}
      </button>
    `;
  });

  // Next and Last
  btns += `
    <button type="button" class="w-7 h-7 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition disabled:opacity-40" data-page="${progState.page + 1}" ${progState.page >= maxP ? 'disabled' : ''} title="ถัดไป">
      <i class="fa-solid fa-chevron-right text-[11px]"></i>
    </button>
    <button type="button" class="w-7 h-7 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition disabled:opacity-40" data-page="${maxP}" ${progState.page >= maxP ? 'disabled' : ''} title="หน้าสุดท้าย">
      <i class="fa-solid fa-angles-right text-[11px]"></i>
    </button>
  `;

  controlsEl.innerHTML = btns;

  controlsEl.querySelectorAll('[data-page]').forEach(b => {
    b.addEventListener('click', () => {
      const p = parseInt(b.getAttribute('data-page'), 10);
      if (p >= 1 && p <= maxP && p !== cur) {
        progState.page = p;
        loadProgramsData();
      }
    });
  });
}

function exportSelectedRowsCsv() {
  if (progState.selectedIds.size === 0) return;
  const selectedRows = progState.rows.filter(r => progState.selectedIds.has(r.id));
  if (selectedRows.length === 0) return;

  const headers = ['ID', 'Product Model', 'Side', 'Machine', 'Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Owner', 'Shelf', 'Status', 'Updated At'];
  const csvLines = [headers.join(',')];

  selectedRows.forEach(r => {
    const row = [
      r.id,
      `"${r.product_model}"`,
      `"${r.side}"`,
      `"${r.machine_type}"`,
      r.stage_1,
      r.stage_2,
      r.stage_3,
      r.stage_4,
      `"${(r.owner || '').replace(/"/g, '""')}"`,
      `"${(r.shelf || '').replace(/"/g, '""')}"`,
      `"${r.program_ok || ''}"`,
      `"${r.updated_at || ''}"`
    ];
    csvLines.push(row.join(','));
  });

  const blob = new Blob(["\uFEFF" + csvLines.join("\r\n")], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `aoi-programs-selected-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  showProgToast('ส่งออกข้อมูลสำเร็จ', `ส่งออก ${selectedRows.length} รายการเป็นไฟล์ CSV เรียบร้อย`);
}

function initProgTableEvents() {
  const tbody = document.getElementById('prog-table-body');
  const checkAll = document.getElementById('prog-check-all');

  // Single event delegation at tbody
  tbody?.addEventListener('click', (e) => {
    // Checkbox click
    const chk = e.target.closest('.prog-row-checkbox');
    if (chk) {
      const id = parseInt(chk.getAttribute('data-id'), 10);
      if (chk.checked) progState.selectedIds.add(id);
      else progState.selectedIds.delete(id);

      const row = chk.closest('tr');
      row?.classList.toggle('is-selected', chk.checked);
      updateProgBulkBar();
      return;
    }

    // Step cell click: 1-Click Cycle or Shift+Click context menu
    const stepCell = e.target.closest('.prog-step-cell');
    if (stepCell) {
      const progId = parseInt(stepCell.getAttribute('data-prog-id'), 10);
      const stageNum = parseInt(stepCell.getAttribute('data-stage-num'), 10);
      const currVal = parseInt(stepCell.getAttribute('data-status-val'), 10) || 0;

      if (e.shiftKey) {
        openProgContextMenu(stepCell, e.clientX, e.clientY);
        return;
      }

      const nextVal = getNextStageStatus(currVal);
      applyProgStageUpdate(progId, stageNum, nextVal);
      return;
    }

    // Assign owner button click on row
    const assignBtn = e.target.closest('button[data-action="assign"]');
    if (assignBtn) {
      const progId = parseInt(assignBtn.getAttribute('data-id'), 10);
      const row = progState.rows.find(r => r.id === progId);
      if (!row) return;

      const newOwner = prompt(`ระบุชื่อผู้รับผิดชอบสำหรับ ${row.product_model}:`, progState.currentUser || '');
      if (newOwner !== null && newOwner.trim() !== '') {
        apiBulkAction('assign_owner', [progId], { owner: newOwner.trim() })
          .then((res) => {
            row.owner = newOwner.trim();
            updateRowInDom(row);
            flashRowInDom(progId);
            showProgToast('มอบหมายผู้รับผิดชอบสำเร็จ', `${row.product_model}: ${newOwner.trim()}`);
          })
          .catch(err => showProgErrorToast('มอบหมายไม่สำเร็จ', err.message));
      }
      return;
    }
  });

  // Right-click on stepper cell
  tbody?.addEventListener('contextmenu', (e) => {
    const stepCell = e.target.closest('.prog-step-cell');
    if (stepCell) {
      e.preventDefault();
      openProgContextMenu(stepCell, e.clientX, e.clientY);
    }
  });

  // Stepper cell keyboard accessibility (Space, Enter, 0, 1, 2, 9)
  tbody?.addEventListener('keydown', (e) => {
    const stepCell = e.target.closest('.prog-step-cell');
    if (stepCell) {
      const progId = parseInt(stepCell.getAttribute('data-prog-id'), 10);
      const stageNum = parseInt(stepCell.getAttribute('data-stage-num'), 10);
      const currVal = parseInt(stepCell.getAttribute('data-status-val'), 10) || 0;

      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        const nextVal = getNextStageStatus(currVal);
        applyProgStageUpdate(progId, stageNum, nextVal);
        return;
      }

      if (['0', '1', '2', '9'].includes(e.key)) {
        e.preventDefault();
        applyProgStageUpdate(progId, stageNum, parseInt(e.key, 10));
        return;
      }
    }
  });

  // Check all in current page
  checkAll?.addEventListener('change', (e) => {
    const isChecked = e.target.checked;
    document.querySelectorAll('.prog-row-checkbox').forEach(chk => {
      chk.checked = isChecked;
      const id = parseInt(chk.getAttribute('data-id'), 10);
      if (isChecked) progState.selectedIds.add(id);
      else progState.selectedIds.delete(id);

      const row = chk.closest('tr');
      row?.classList.toggle('is-selected', isChecked);
    });
    updateProgBulkBar();
  });

  // Bulk Actions
  const btnDeselect = document.getElementById('prog-bulk-deselect-btn');
  btnDeselect?.addEventListener('click', () => {
    progState.selectedIds.clear();
    document.querySelectorAll('.prog-row-checkbox').forEach(chk => {
      chk.checked = false;
      chk.closest('tr')?.classList.remove('is-selected');
    });
    updateProgBulkBar();
  });

  // Bulk Advance Next Stage
  const btnAdvance = document.getElementById('prog-bulk-advance-btn');
  btnAdvance?.addEventListener('click', async () => {
    if (progState.selectedIds.size === 0) return;
    btnAdvance.disabled = true;
    try {
      const res = await apiBulkAction('advance_stage', Array.from(progState.selectedIds));
      showProgToast('เลื่อนขั้นสำเร็จ', `เลื่อนขั้นสำหรับ ${res.updated_count || progState.selectedIds.size} รายการ`);
      progState.selectedIds.clear();
      await loadProgramsData();
    } catch (err) {
      showProgErrorToast('ไม่สามารถเลื่อนขั้นได้', err.message);
    } finally {
      btnAdvance.disabled = false;
    }
  });

  // Bulk Stage Menu Toggle
  const btnStageMenu = document.getElementById('prog-bulk-stage-menu-btn');
  const stageMenu = document.getElementById('prog-bulk-stage-menu');
  btnStageMenu?.addEventListener('click', (e) => {
    e.stopPropagation();
    stageMenu?.classList.toggle('is-open');
    ownerMenu?.classList.remove('is-open');
  });

  stageMenu?.querySelectorAll('[data-bulk-stage]').forEach(btn => {
    btn.addEventListener('click', async () => {
      stageMenu.classList.remove('is-open');
      const stage = parseInt(btn.getAttribute('data-bulk-stage'), 10);
      const status = parseInt(btn.getAttribute('data-bulk-status'), 10);

      try {
        const res = await apiBulkAction('set_stage', Array.from(progState.selectedIds), { stage, status });
        showProgToast('กำหนดสถานะสำเร็จ', `ปรับปรุงขั้นที่ ${stage} ให้ ${res.updated_count || progState.selectedIds.size} รายการ`);
        progState.selectedIds.clear();
        await loadProgramsData();
      } catch (err) {
        showProgErrorToast('กำหนดสถานะไม่สำเร็จ', err.message);
      }
    });
  });

  // Bulk Owner Menu Toggle
  const btnOwnerMenu = document.getElementById('prog-bulk-owner-menu-btn');
  const ownerMenu = document.getElementById('prog-bulk-owner-menu');
  btnOwnerMenu?.addEventListener('click', (e) => {
    e.stopPropagation();
    ownerMenu?.classList.toggle('is-open');
    stageMenu?.classList.remove('is-open');
  });

  ownerMenu?.querySelectorAll('[data-bulk-owner]').forEach(btn => {
    btn.addEventListener('click', async () => {
      ownerMenu.classList.remove('is-open');
      let targetOwner = btn.getAttribute('data-bulk-owner');
      if (targetOwner === '__ME__') {
        targetOwner = progState.currentUser || 'Thanapol';
      }

      try {
        const res = await apiBulkAction('assign_owner', Array.from(progState.selectedIds), { owner: targetOwner });
        const label = targetOwner ? `มอบหมายให้ "${targetOwner}"` : 'ยกเลิกการมอบหมาย';
        showProgToast('มอบหมายสำเร็จ', `${label} ให้ ${res.updated_count || progState.selectedIds.size} รายการ`);
        progState.selectedIds.clear();
        await loadProgramsData();
      } catch (err) {
        showProgErrorToast('มอบหมายไม่สำเร็จ', err.message);
      }
    });
  });

  // Bulk Export CSV
  const btnExport = document.getElementById('prog-bulk-export-btn');
  btnExport?.addEventListener('click', exportSelectedRowsCsv);

  // Close dropdowns on outside click
  document.addEventListener('click', () => {
    stageMenu?.classList.remove('is-open');
    ownerMenu?.classList.remove('is-open');
  });
}
