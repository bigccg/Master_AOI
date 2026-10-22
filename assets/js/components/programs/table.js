/**
 * AOI Program Tracker - Table Rendering & Event Delegation
 * 9 Columns: Product Model (sticky), Side, Machine, 4-Stage Pipeline,
 * Master Samples (live D / G+D from index.php), Shelf, Operator, Updated, Note / Reason
 */

function renderSingleRowHtml(r) {
  const isOverdue = (r.overdue_days > 0);

  // Side badge for dedicated Side column (F / B with tooltip F-Side / B-Side)
  const sideRaw = String(r.side || 'F-Side').trim();
  const isFSide = sideRaw.toUpperCase().startsWith('F');
  const sideCode = isFSide ? 'F' : 'B';
  const sideTitle = isFSide ? 'F-Side' : 'B-Side';
  const sideBadge = `<span class="prog-side-badge ${isFSide ? 'is-f' : 'is-b'}" title="${sideTitle}">${sideCode}</span>`;

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

  // 4-Stage Glass Pipeline Stepper HTML
  const stepperHtml = renderProgStepperHtml(r);

  // Master Samples: Live Link from index.php (ONLY D and G+D; never Bare or G)
  const countD = parseInt(r.master_d, 10) || 0;
  const countGd = parseInt(r.master_gd, 10) || 0;
  const dShelves = String(r.master_d_shelves || '').trim();
  const gdShelves = String(r.master_gd_shelves || '').trim();
  const noDefectBoards = (countD === 0 && countGd === 0);

  const dTip = countD > 0
    ? `Defect ${countD} แผ่น${dShelves ? ' · ' + dShelves : ''}`
    : (noDefectBoards ? 'ไม่มีแผ่น Defect ใน Master Board' : 'ไม่มีแผ่น Defect (D)');

  const gdTip = countGd > 0
    ? `Good+Defect ${countGd} แผ่น${gdShelves ? ' · ' + gdShelves : ''}`
    : (noDefectBoards ? 'ไม่มีแผ่น Defect ใน Master Board' : 'ไม่มีแผ่น G+D');

  const dChipHtml = countD > 0
    ? `<span class="prog-master-chip is-active-d" title="${h(dTip)}">D</span>`
    : `<span class="prog-master-chip is-empty" title="${h(dTip)}">—</span>`;

  const gdChipHtml = countGd > 0
    ? `<span class="prog-master-chip is-active-gd" title="${h(gdTip)}">G+D</span>`
    : `<span class="prog-master-chip is-empty" title="${h(gdTip)}">—</span>`;

  const masterHtml = `
    <div class="prog-master-group ${noDefectBoards ? 'is-no-defect' : ''}" ${noDefectBoards ? 'title="ไม่มีแผ่น Defect ใน Master Board"' : ''}>
      ${dChipHtml}
      ${gdChipHtml}
    </div>
  `;

  // Shelf badge
  const shelf = String(r.shelf || '').trim();
  let shelfHtml = '<span class="prog-cell-empty">—</span>';
  if (shelf) {
    const parts = shelf.split(',').map(s => s.trim()).filter(Boolean);
    if (parts.length > 1) {
      shelfHtml = `
        <div class="inline-flex items-center gap-1">
          <span class="prog-shelf-pill">${h(parts[0])}</span>
          <span class="prog-shelf-more" title="${h(shelf)}">+${parts.length - 1}</span>
        </div>
      `;
    } else {
      shelfHtml = `<span class="prog-shelf-pill">${h(shelf)}</span>`;
    }
  }

  // Operator cell (clickable to assign or update operator)
  const owner = String(r.owner || '').trim();
  let ownerHtml = `
    <button type="button" class="prog-assign-btn is-empty" data-action="assign" data-id="${r.id}" title="คลิกเพื่อระบุผู้ทำ">
      <i class="fa-solid fa-user-plus text-[10px]" aria-hidden="true"></i>
      <span>ระบุผู้ทำ</span>
    </button>
  `;
  if (owner) {
    ownerHtml = `
      <button type="button" class="prog-assign-btn is-assigned" data-action="assign" data-id="${r.id}" title="คลิกเพื่อเปลี่ยนผู้ทำ (${h(owner)})">
        <i class="fa-regular fa-user text-[11px] opacity-60" aria-hidden="true"></i>
        <span class="truncate max-w-[95px]">${h(owner)}</span>
      </button>
    `;
  }

  // Updated date & Overdue badge
  let updatedHtml = `<span class="prog-updated-text">${h(r.updated_rel || '-')}</span>`;
  if (isOverdue) {
    updatedHtml = `
      <div class="inline-flex items-center justify-center gap-1.5">
        <span class="prog-updated-text is-muted">${h(r.updated_rel || '-')}</span>
        <span class="prog-overdue-badge" title="ค้างเกินกำหนด ${r.overdue_days} วัน">ค้าง ${r.overdue_days} วัน</span>
      </div>
    `;
  }

  // Note / Reason cell (clickable to edit note; shows "ไม่มีแผ่น Defect ใน Master Board" if board was removed/absent and no note set)
  const note = String(r.note || '').trim();
  const waitReason = String(r.wait_reason || '').trim();
  let noteInnerHtml = '<span class="prog-cell-empty">—</span>';
  let noteTitle = 'คลิกเพื่อแก้ไขหมายเหตุ';
  if (waitReason) {
    const fullReason = note ? `${waitReason}: ${note}` : waitReason;
    noteInnerHtml = `<span class="prog-wait-badge">${h(waitReason)}</span>`;
    noteTitle = `${fullReason} (คลิกเพื่อแก้ไขหมายเหตุ)`;
  } else if (note) {
    noteInnerHtml = `<span class="prog-note-text">${h(note)}</span>`;
    noteTitle = `${note} (คลิกเพื่อแก้ไขหมายเหตุ)`;
  } else if (noDefectBoards) {
    noteInnerHtml = `<span class="prog-no-defect-badge">ไม่มีแผ่น Defect ใน Master Board</span>`;
    noteTitle = 'ไม่มีแผ่น Defect ใน Master Board (คลิกเพื่อเพิ่มหมายเหตุ)';
  }

  const noteHtml = `
    <button type="button" class="prog-note-btn" data-action="edit-note" data-id="${r.id}" title="${h(noteTitle)}">
      ${noteInnerHtml}
    </button>
  `;

  return `
    <tr class="aoi-row prog-row ${isOverdue ? 'is-overdue' : ''}" data-id="${r.id}" tabindex="0">
      <td class="aoi-td prog-td-sticky-product text-left" data-col="product">
        <span class="prog-product-name">${h(r.product_model)}</span>
      </td>
      <td class="aoi-td" data-col="side">
        ${sideBadge}
      </td>
      <td class="aoi-td" data-col="machine">
        ${machBadge}
      </td>
      <td class="aoi-td" data-col="stepper">
        ${stepperHtml}
      </td>
      <td class="aoi-td" data-col="master">
        ${masterHtml}
      </td>
      <td class="aoi-td" data-col="shelf">
        ${shelfHtml}
      </td>
      <td class="aoi-td" data-col="owner">
        ${ownerHtml}
      </td>
      <td class="aoi-td" data-col="updated">
        ${updatedHtml}
      </td>
      <td class="aoi-td text-left" data-col="note">
        ${noteHtml}
      </td>
    </tr>
  `;
}

function renderProgTable() {
  const tbody = document.getElementById('prog-table-body');
  if (!tbody) return;

  if (progState.viewStatus === 'empty') {
    const hasFilter = progState.search !== '' || progState.machine !== 'ALL' || progState.owner !== 'ALL' || progState.status !== 'ALL' || progState.stage !== 'ALL';
    tbody.innerHTML = `
      <tr>
        <td colspan="9" class="prog-state-cell">
          <div class="prog-state-box">
            <div class="prog-state-icon">
              <i class="fa-solid fa-inbox" aria-hidden="true"></i>
            </div>
            <span class="prog-state-title">ยังไม่มีโปรแกรมที่ตรงกับตัวกรอง</span>
            <span class="prog-state-desc">
              ยังไม่มีโปรแกรมที่ตรงกับตัวกรอง — ลองล้างตัวกรอง โปรแกรมใหม่จะแสดงเองเมื่อมีบอร์ด Defect ถูกเพิ่มที่ Master Board
            </span>
            <div class="prog-state-actions">
              ${hasFilter ? `
                <button type="button" onclick="resetAllProgFilters()" class="prog-state-btn is-primary">
                  <i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>
                  <span>ล้างตัวกรอง</span>
                </button>
              ` : ''}
              <a href="index.php" class="prog-state-btn">
                <i class="fa-solid fa-table-list" aria-hidden="true"></i>
                <span>เปิดหน้า Master Board</span>
              </a>
            </div>
          </div>
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = progState.rows.map(renderSingleRowHtml).join('');
}

function updateRowInDom(row) {
  const existing = document.querySelector(`tr.aoi-row[data-id="${row.id}"]`);
  if (!existing) return;
  const temp = document.createElement('tbody');
  temp.innerHTML = renderSingleRowHtml(row);
  const newTr = temp.firstElementChild;
  existing.replaceWith(newTr);
}

function flashRowInDom(id) {
  const tr = document.querySelector(`tr.aoi-row[data-id="${id}"]`);
  if (!tr) return;
  tr.classList.remove('flash-update');
  void tr.offsetWidth;
  tr.classList.add('flash-update');
  setTimeout(() => {
    tr.classList.remove('flash-update');
  }, 1300);
}

function renderProgTableSkeleton() {
  const tbody = document.getElementById('prog-table-body');
  if (!tbody) return;

  tbody.innerHTML = Array.from({ length: 8 }).map(() => `
    <tr class="aoi-row animate-pulse">
      <td class="aoi-td prog-td-sticky-product"><div class="h-4 w-32 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-4 w-8 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-4 w-12 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-6 w-44 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-4 w-16 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-4 w-14 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-4 w-20 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-4 w-16 bg-slate-200 rounded mx-auto"></div></td>
      <td class="aoi-td"><div class="h-4 w-28 bg-slate-200 rounded mx-auto"></div></td>
    </tr>
  `).join('');
}

function renderProgTableError() {
  const tbody = document.getElementById('prog-table-body');
  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="9" class="prog-state-cell is-error">
        <div class="prog-state-box">
          <div class="prog-state-icon is-error">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
          </div>
          <span class="prog-state-title">โหลดข้อมูลโปรแกรมไม่สำเร็จ</span>
          <span class="prog-state-desc">
            ไม่สามารถดึงข้อมูลโปรแกรมจากระบบได้ชั่วคราว เนื่องจากการเชื่อมต่อขัดข้องหรือเซิร์ฟเวอร์ไม่ตอบสนอง กรุณากดโหลดข้อมูลใหม่อีกครั้ง
          </span>
          <div class="prog-state-actions">
            <button type="button" onclick="loadProgramsData()" class="prog-state-btn is-primary">
              <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
              <span>โหลดข้อมูลใหม่</span>
            </button>
          </div>
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
    summaryEl.textContent = 'ไม่พบรายการที่ตรงกับเงื่อนไข';
    controlsEl.innerHTML = '';
    return;
  }

  const isAll = (progState.pageSize === 'all');
  const start = isAll ? 1 : ((progState.page - 1) * progState.pageSize) + 1;
  const end = isAll ? progState.total : Math.min(progState.page * progState.pageSize, progState.total);

  summaryEl.innerHTML = `แสดง <strong class="font-semibold text-slate-900">${start}–${end}</strong> จาก ${progState.total} รายการ`;

  if (isAll || progState.pages <= 1) {
    controlsEl.innerHTML = '';
    return;
  }

  let btns = '<div class="inline-flex rounded-md shadow-sm -space-x-px">';

  btns += `
    <button type="button" class="prog-page-btn" data-page="${progState.page - 1}" ${progState.page <= 1 ? 'disabled' : ''} title="ไปหน้าก่อนหน้า">
      <i class="fa-solid fa-chevron-left text-[11px]" aria-hidden="true"></i>
    </button>
  `;

  const cur = progState.page;
  const maxP = progState.pages;
  const keep = new Set();
  for (let i = 1; i <= Math.min(2, maxP); i++) keep.add(i);
  for (let i = Math.max(1, maxP - 1); i <= maxP; i++) keep.add(i);
  for (let i = cur - 1; i <= cur + 1; i++) if (i >= 1 && i <= maxP) keep.add(i);

  const sortedPages = [...keep].sort((a, b) => a - b);
  let prev = 0;

  sortedPages.forEach(p => {
    if (p - prev === 2) {
      btns += `<button type="button" class="prog-page-btn" data-page="${prev + 1}">${prev + 1}</button>`;
    } else if (p - prev > 2) {
      btns += `<span class="prog-page-btn text-slate-400 cursor-default select-none">…</span>`;
    }

    const isCur = (p === cur);
    btns += `
      <button type="button" class="prog-page-btn ${isCur ? 'is-active' : ''}" data-page="${p}">
        ${p}
      </button>
    `;
    prev = p;
  });

  btns += `
    <button type="button" class="prog-page-btn" data-page="${progState.page + 1}" ${progState.page >= maxP ? 'disabled' : ''} title="ไปหน้าถัดไป">
      <i class="fa-solid fa-chevron-right text-[11px]" aria-hidden="true"></i>
    </button>
  `;
  btns += '</div>';

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

function initProgTableEvents() {
  const tbody = document.getElementById('prog-table-body');

  tbody?.addEventListener('click', (e) => {
    // 1. Step cell click: 1-Click Cycle or Shift+Click context menu
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

    // 2. Assign / change operator on existing row
    const assignBtn = e.target.closest('button[data-action="assign"]');
    if (assignBtn) {
      const progId = parseInt(assignBtn.getAttribute('data-id'), 10);
      const rowIdx = progState.rows.findIndex(r => r.id === progId);
      if (rowIdx === -1) return;
      const row = progState.rows[rowIdx];
      const oldOwner = row.owner || '';

      const newOwner = prompt(`ระบุชื่อผู้ทำ (Operator) สำหรับ ${row.product_model} (${row.side}) — เว้นว่างเพื่อล้างค่า:`, oldOwner || progState.currentUser || '');
      if (newOwner !== null && newOwner.trim() !== oldOwner) {
        const cleanOwner = newOwner.trim();
        if (cleanOwner !== '') {
          progState.currentUser = cleanOwner;
          setStoredItem('aoi_prog_user', cleanOwner);
        }
        apiUpdateMeta(progId, { owner: cleanOwner }, row.version)
          .then((res) => {
            if (res.program) {
              progState.rows[rowIdx] = res.program;
              updateRowInDom(res.program);
              flashRowInDom(progId);
            }
            if (Array.isArray(res.owners)) {
              populateProgOwners(res.owners);
            }
            showProgToast(
              'อัปเดตผู้ทำสำเร็จ',
              `${row.product_model} (${row.side}) → ${cleanOwner || 'ยังไม่มอบหมาย'}`,
              res.audit_id ? async () => {
                const undoRes = await apiUndoAction(progId, res.audit_id);
                if (undoRes.program) {
                  progState.rows[rowIdx] = undoRes.program;
                  updateRowInDom(undoRes.program);
                  flashRowInDom(progId);
                }
                showProgToast('เลิกทำสำเร็จ', `คืนค่าผู้ทำของ ${row.product_model} เรียบร้อย`);
              } : null
            );
          })
          .catch(err => showProgErrorToast('อัปเดตผู้ทำไม่สำเร็จ', err.message));
      }
      return;
    }

    // 3. Edit note on existing row
    const noteBtn = e.target.closest('button[data-action="edit-note"]');
    if (noteBtn) {
      const progId = parseInt(noteBtn.getAttribute('data-id'), 10);
      const rowIdx = progState.rows.findIndex(r => r.id === progId);
      if (rowIdx === -1) return;
      const row = progState.rows[rowIdx];
      const oldNote = row.note || '';

      const newNote = prompt(`แก้ไขหมายเหตุสำหรับ ${row.product_model} (${row.side}):`, oldNote);
      if (newNote !== null && newNote.trim() !== oldNote) {
        const cleanNote = newNote.trim();
        apiUpdateMeta(progId, { note: cleanNote }, row.version)
          .then((res) => {
            if (res.program) {
              progState.rows[rowIdx] = res.program;
              updateRowInDom(res.program);
              flashRowInDom(progId);
            }
            showProgToast(
              'บันทึกหมายเหตุสำเร็จ',
              `${row.product_model} (${row.side})`,
              res.audit_id ? async () => {
                const undoRes = await apiUndoAction(progId, res.audit_id);
                if (undoRes.program) {
                  progState.rows[rowIdx] = undoRes.program;
                  updateRowInDom(undoRes.program);
                  flashRowInDom(progId);
                }
                showProgToast('เลิกทำสำเร็จ', `คืนค่าหมายเหตุของ ${row.product_model} เรียบร้อย`);
              } : null
            );
          })
          .catch(err => showProgErrorToast('บันทึกหมายเหตุไม่สำเร็จ', err.message));
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

  // Stepper cell keyboard accessibility (Space, Enter, ContextMenu, 0, 1, 2, 9)
  tbody?.addEventListener('keydown', (e) => {
    const stepCell = e.target.closest('.prog-step-cell');
    if (stepCell) {
      const progId = parseInt(stepCell.getAttribute('data-prog-id'), 10);
      const stageNum = parseInt(stepCell.getAttribute('data-stage-num'), 10);
      const currVal = parseInt(stepCell.getAttribute('data-status-val'), 10) || 0;

      if ((e.shiftKey && (e.key === 'Enter' || e.key === 'F10')) || e.key === 'ContextMenu') {
        e.preventDefault();
        const rect = stepCell.getBoundingClientRect();
        openProgContextMenu(stepCell, rect.left, rect.bottom + 4);
        return;
      }

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
}
