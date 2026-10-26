/**
 * AOI Program Tracker - Table Rendering & Event Delegation
 * 9 Columns: Product Model (sticky), Side, Machine, 4-Stage Pipeline,
 * Master Samples (live D / G+D from index.php), Shelf, Technician, Updated, Remark
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
  // ONE single pill that says directly what is on the Master Board:
  //   Defect | Good+Defect | Defect + G+D | — (none). Counts & shelves are in the tooltip.
  const countD = parseInt(r.master_d, 10) || 0;
  const countGd = parseInt(r.master_gd, 10) || 0;
  const dShelves = String(r.master_d_shelves || '').trim();
  const gdShelves = String(r.master_gd_shelves || '').trim();
  const noDefectBoards = (countD === 0 && countGd === 0);

  const tipParts = [];
  if (countD > 0) tipParts.push(`Defect ${countD} แผ่น${dShelves ? ' · ' + dShelves : ''}`);
  if (countGd > 0) tipParts.push(`Good+Defect ${countGd} แผ่น${gdShelves ? ' · ' + gdShelves : ''}`);
  const masterTip = noDefectBoards ? 'ไม่มีแผ่น Defect ใน Master Board' : tipParts.join('\n');

  let masterChipHtml;
  if (countD > 0 && countGd > 0) {
    masterChipHtml = `<span class="prog-master-chip is-active-both" title="${h(masterTip)}">Defect + G+D</span>`;
  } else if (countD > 0) {
    masterChipHtml = `<span class="prog-master-chip is-active-d" title="${h(masterTip)}">Defect</span>`;
  } else if (countGd > 0) {
    masterChipHtml = `<span class="prog-master-chip is-active-gd" title="${h(masterTip)}">Good+Defect</span>`;
  } else {
    masterChipHtml = `<span class="prog-master-chip is-empty" title="${h(masterTip)}">—</span>`;
  }

  const masterHtml = `
    <div class="prog-master-group ${noDefectBoards ? 'is-no-defect' : ''}">
      ${masterChipHtml}
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

  // Technician cell (clickable to assign or update technician)
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
  // Always shown as a calendar date (DD/MM/YYYY); hover shows the exact time
  const updatedTitle = r.updated_at ? formatProgramDateTime(r.updated_at) : '';
  let updatedHtml = `<span class="prog-updated-text" title="${h(updatedTitle)}">${h(r.updated_rel || '-')}</span>`;
  if (isOverdue) {
    updatedHtml = `
      <div class="inline-flex items-center justify-center gap-1.5">
        <span class="prog-updated-text is-muted" title="${h(updatedTitle)}">${h(r.updated_rel || '-')}</span>
        <span class="prog-overdue-badge" title="ค้างเกินกำหนด ${r.overdue_days} วัน">ค้าง ${r.overdue_days} วัน</span>
      </div>
    `;
  }

  // Remark cell (clickable to edit note; shows "ไม่มีแผ่น Defect ใน Master Board" if board was removed/absent and no note set)
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
      requestProgStageChange(progId, stageNum, nextVal);
      return;
    }

    // 2. Assign / change operator on existing row
    const assignBtn = e.target.closest('button[data-action="assign"]');
    if (assignBtn) {
      const progId = parseInt(assignBtn.getAttribute('data-id'), 10);
      openOperatorDropdown(assignBtn, progId);
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

  // Stepper cell keyboard accessibility (Space, Enter, ContextMenu, 0, 2)
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
        requestProgStageChange(progId, stageNum, nextVal);
        return;
      }

      // 0 = Not started, 2 = Complete (1 / 9 no longer offered)
      if (['0', '2'].includes(e.key)) {
        e.preventDefault();
        requestProgStageChange(progId, stageNum, parseInt(e.key, 10));
        return;
      }
    }
  });
}

// ──────────────────────────────────────────────
// Searchable Operator Dropdown Popover (Glass Dynamic · HyperOS)
//   - select zone  : the name (left)  -> assigns the operator
//   - delete zone  : trash button (right, visually separated) -> opens a glass confirm popup first
// ──────────────────────────────────────────────
let activeOpPopoverEl = null;
let opPopoverOutsideHandler = null;
let opPopoverKeyHandler = null;
let activeOpConfirmEl = null;

function closeOperatorConfirm() {
  if (activeOpConfirmEl) {
    const el = activeOpConfirmEl;
    activeOpConfirmEl = null;
    if (el._keyHandler) document.removeEventListener('keydown', el._keyHandler, true);
    el.classList.add('is-closing');
    setTimeout(() => el.remove(), 160);
  }
}

function closeOperatorDropdown() {
  closeOperatorConfirm();
  if (activeOpPopoverEl) {
    activeOpPopoverEl.remove();
    activeOpPopoverEl = null;
  }
  if (opPopoverOutsideHandler) {
    document.removeEventListener('pointerdown', opPopoverOutsideHandler);
    opPopoverOutsideHandler = null;
  }
  if (opPopoverKeyHandler) {
    document.removeEventListener('keydown', opPopoverKeyHandler);
    opPopoverKeyHandler = null;
  }
}

/**
 * Glass confirm popup for deleting an operator option. Resolves true only on explicit confirm.
 * Cancel is focused by default so a stray Enter / Space never deletes.
 */
function confirmDeleteOperator(name, usageCount) {
  closeOperatorConfirm();

  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'prog-op-confirm-overlay';

    const usageHtml = usageCount > 0
      ? `<div class="prog-op-confirm-note is-info">
           <i class="fa-solid fa-circle-info"></i>
           <span>มี <strong>${usageCount}</strong> โปรแกรมที่ใช้ชื่อนี้อยู่ — ข้อมูลในตารางจะ<strong>ไม่ถูกเปลี่ยน</strong> ลบเฉพาะตัวเลือกใน Dropdown</span>
         </div>`
      : `<div class="prog-op-confirm-note">
           <i class="fa-regular fa-circle-check"></i>
           <span>ไม่มีโปรแกรมใช้ชื่อนี้อยู่</span>
         </div>`;

    overlay.innerHTML = `
      <div class="prog-op-confirm" role="alertdialog" aria-modal="true" aria-labelledby="prog-op-confirm-title">
        <div class="prog-op-confirm-icon"><i class="fa-solid fa-trash-can"></i></div>
        <h3 id="prog-op-confirm-title" class="prog-op-confirm-title">ลบผู้ทำออกจากตัวเลือก?</h3>
        <div class="prog-op-confirm-name"><i class="fa-regular fa-user"></i><span>${h(name)}</span></div>
        ${usageHtml}
        <div class="prog-op-confirm-actions">
          <button type="button" class="prog-op-confirm-btn is-cancel" data-act="cancel">ยกเลิก</button>
          <button type="button" class="prog-op-confirm-btn is-danger" data-act="ok">
            <i class="fa-solid fa-trash-can"></i><span>ลบตัวเลือก</span>
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);
    activeOpConfirmEl = overlay;

    const finish = (val) => {
      if (activeOpConfirmEl !== overlay) return;
      closeOperatorConfirm();
      resolve(val);
    };

    overlay.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-act]');
      if (btn) {
        finish(btn.getAttribute('data-act') === 'ok');
      } else if (e.target === overlay) {
        finish(false);
      }
    });

    // Capture phase + stopPropagation so Esc / Tab only affect this popup (not the dropdown behind it)
    overlay._keyHandler = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        finish(false);
      } else if (e.key === 'Tab') {
        const btns = [...overlay.querySelectorAll('button')];
        const idx = btns.indexOf(document.activeElement);
        e.preventDefault();
        e.stopPropagation();
        const next = e.shiftKey ? (idx <= 0 ? btns.length - 1 : idx - 1) : (idx + 1) % btns.length;
        btns[next].focus();
      }
    };
    document.addEventListener('keydown', overlay._keyHandler, true);

    overlay.querySelector('[data-act="cancel"]')?.focus();
  });
}

function openOperatorDropdown(anchorBtn, progId) {
  closeOperatorDropdown();

  const rowIdx = progState.rows.findIndex(r => r.id === progId);
  if (rowIdx === -1) return;
  const row = progState.rows[rowIdx];
  const currentOwner = (row.owner || '').trim();

  const popover = document.createElement('div');
  popover.className = 'prog-op-popover';
  popover.setAttribute('role', 'dialog');
  popover.setAttribute('aria-label', 'เลือกหรือเพิ่มผู้ทำ');

  popover.innerHTML = `
    <div class="prog-op-head">
      <div class="prog-op-title">
        <span class="prog-op-title-main">
          <span class="prog-op-title-icon"><i class="fa-solid fa-user-tag"></i></span>
          <span>ระบุผู้ทำ <em>Technician</em></span>
        </span>
        <button type="button" class="prog-op-close" title="ปิด (Esc)" aria-label="ปิด">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <div class="prog-op-subtitle">
        <span class="prog-op-chip" title="${h(row.product_model)}">${h(row.product_model)}</span>
        <span class="prog-op-chip is-side ${String(row.side || '').toUpperCase().startsWith('B') ? 'is-b' : ''}">${h(row.side)}</span>
      </div>
    </div>
    <div class="prog-op-search-box">
      <div class="prog-op-search-wrap">
        <i class="fa-solid fa-magnifying-glass prog-op-search-icon"></i>
        <input type="text"
               id="prog-op-search-input"
               class="prog-op-search-input"
               placeholder="ค้นหา หรือพิมพ์ชื่อเพื่อเพิ่มใหม่…"
               autocomplete="off"
               spellcheck="false" />
        <button type="button" id="prog-op-search-clear" class="prog-op-clear-btn" style="display: none;" title="ล้าง" tabindex="-1">
          <i class="fa-solid fa-circle-xmark"></i>
        </button>
      </div>
    </div>
    <div class="prog-op-list" id="prog-op-options-list" role="listbox"></div>
    <div class="prog-op-footer">
      <span><i class="fa-regular fa-lightbulb"></i> เลือกจากรายการ หรือพิมพ์ชื่อใหม่</span>
      <span class="prog-op-footer-keys"><kbd>↑↓</kbd><kbd>↵</kbd><kbd>Esc</kbd></span>
    </div>
  `;

  document.body.appendChild(popover);
  activeOpPopoverEl = popover;

  // Dynamic positioning relative to anchor button
  const rect = anchorBtn.getBoundingClientRect();
  const popW = 300;
  const estH = 380;
  let top = rect.bottom + 6;
  const left = Math.max(12, Math.min(rect.left, window.innerWidth - popW - 12));
  if (top + estH > window.innerHeight && rect.top > estH) {
    top = rect.top - estH - 6;
  }
  popover.style.top = `${Math.max(8, top)}px`;
  popover.style.left = `${left}px`;

  const searchInput = popover.querySelector('#prog-op-search-input');
  const clearBtn = popover.querySelector('#prog-op-search-clear');
  const listEl = popover.querySelector('#prog-op-options-list');
  const closeBtn = popover.querySelector('.prog-op-close');

  let highlightedIndex = -1;

  const operatorOptions = () => (Array.isArray(progState.operators) ? progState.operators : []);

  function renderList(query) {
    const q = (query || '').trim();
    const qLower = q.toLowerCase();
    const options = operatorOptions();

    const exactMatch = options.some(o => o.name.toLowerCase() === qLower);
    const filtered = options.filter(o => o.name.toLowerCase().includes(qLower));

    let html = '';

    // Typed a name that does not exist yet -> offer to create it
    if (q !== '' && !exactMatch) {
      html += `
        <div class="prog-op-create-row">
          <button type="button" class="prog-op-add-option" data-action="create-and-assign" data-name="${h(q)}" title="เพิ่มเป็นตัวเลือกใหม่และระบุทันที">
            <span class="prog-op-add-icon"><i class="fa-solid fa-plus"></i></span>
            <span class="prog-op-add-label">เพิ่ม <strong>“${h(q)}”</strong> และระบุ</span>
            <kbd class="prog-op-kbd">↵</kbd>
          </button>
          <button type="button" class="prog-op-save-only-btn" data-action="create-only" data-name="${h(q)}" title="บันทึกชื่อนี้เป็นตัวเลือกเท่านั้น (ยังไม่ระบุในแถวนี้)">
            <i class="fa-solid fa-floppy-disk"></i><span>เก็บไว้</span>
          </button>
        </div>
      `;
    }

    // Clear assignment
    if (!q || 'ยังไม่มอบหมาย'.includes(q) || 'unassigned'.includes(qLower) || 'ล้าง'.includes(q)) {
      const isUnassignedSelected = !currentOwner;
      html += `
        <div class="prog-op-row is-unassign">
          <button type="button" class="prog-op-item is-unassign ${isUnassignedSelected ? 'is-selected' : ''}" data-value="">
            <span class="prog-op-avatar is-none"><i class="fa-solid fa-user-slash"></i></span>
            <span class="prog-op-name">ไม่ระบุ / ล้างค่า</span>
            ${isUnassignedSelected ? '<i class="fa-solid fa-check prog-op-check"></i>' : ''}
          </button>
        </div>
      `;
    }

    // Existing operators: [ select zone ] | [ delete zone ]
    if (filtered.length > 0) {
      html += `<div class="prog-op-section-label">ผู้ทำทั้งหมด · ${filtered.length}</div>`;
      filtered.forEach(o => {
        const isSel = (o.name === currentOwner);
        const initial = (o.name.trim().charAt(0) || '?').toUpperCase();
        let hash = 0;
        for (const ch of o.name) hash = (hash * 31 + ch.codePointAt(0)) >>> 0;
        const colorCls = `is-c${hash % 6}`;
        html += `
          <div class="prog-op-row ${isSel ? 'is-selected' : ''}">
            <button type="button" class="prog-op-item ${isSel ? 'is-selected' : ''}" data-value="${h(o.name)}" role="option" aria-selected="${isSel}">
              <span class="prog-op-avatar ${colorCls}">${h(initial)}</span>
              <span class="prog-op-name">${h(o.name)}</span>
              ${o.count > 0 ? `<span class="prog-op-count" title="ใช้งานอยู่ ${o.count} โปรแกรม">${o.count}</span>` : ''}
              ${isSel ? '<i class="fa-solid fa-check prog-op-check"></i>' : ''}
            </button>
            <span class="prog-op-divider" aria-hidden="true"></span>
            <button type="button" class="prog-op-del" data-action="delete-operator" data-name="${h(o.name)}" data-count="${o.count}"
                    title="ลบ “${h(o.name)}” ออกจากตัวเลือก" aria-label="ลบ ${h(o.name)} ออกจากตัวเลือก" tabindex="-1">
              <i class="fa-regular fa-trash-can"></i>
            </button>
          </div>
        `;
      });
    } else if (q === '') {
      html += `<div class="prog-op-empty">ยังไม่มีรายชื่อผู้ทำในระบบ<br><span>พิมพ์ชื่อด้านบนเพื่อเพิ่มรายการแรก</span></div>`;
    }

    listEl.innerHTML = html;
    highlightedIndex = -1;
  }

  renderList('');
  setTimeout(() => searchInput?.focus(), 40);

  searchInput?.addEventListener('input', (e) => {
    const val = e.target.value;
    clearBtn.style.display = val ? 'inline-flex' : 'none';
    renderList(val);
  });

  clearBtn?.addEventListener('click', () => {
    searchInput.value = '';
    clearBtn.style.display = 'none';
    renderList('');
    searchInput.focus();
  });

  closeBtn?.addEventListener('click', () => closeOperatorDropdown());

  function syncOperatorLists(res, fallbackName) {
    if (Array.isArray(res.owners)) {
      progState.owners = res.owners;
      populateProgOwners(res.owners);
    }
    if (Array.isArray(res.operators)) {
      progState.operators = res.operators;
    } else if (fallbackName && !operatorOptions().some(o => o.name.toLowerCase() === fallbackName.toLowerCase())) {
      progState.operators = [...operatorOptions(), { name: fallbackName, count: 0 }]
        .sort((a, b) => a.name.localeCompare(b.name));
    }
  }

  async function handleAssign(cleanOwner, isNew = false) {
    closeOperatorDropdown();

    if (!isNew && cleanOwner === currentOwner) {
      return;
    }

    if (cleanOwner !== '') {
      progState.currentUser = cleanOwner;
      setStoredItem('aoi_prog_user', cleanOwner);
    }

    try {
      const res = await apiUpdateMeta(progId, { owner: cleanOwner }, row.version);
      if (res.program) {
        progState.rows[rowIdx] = res.program;
        updateRowInDom(res.program);
        flashRowInDom(progId);
      }
      syncOperatorLists(res, cleanOwner);

      showProgToast(
        cleanOwner ? (isNew ? 'เพิ่มและระบุผู้ทำสำเร็จ' : 'อัปเดตผู้ทำสำเร็จ') : 'ล้างค่าผู้ทำสำเร็จ',
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
    } catch (err) {
      showProgErrorToast('อัปเดตผู้ทำไม่สำเร็จ', err.message);
    }
  }

  async function handleSaveOnly(newOwner) {
    if (!newOwner) return;
    try {
      const res = await apiAddOperator(newOwner);
      syncOperatorLists(res, newOwner);
      searchInput.value = '';
      clearBtn.style.display = 'none';
      renderList('');
      searchInput.focus();
      showProgToast('บันทึกตัวเลือกสำเร็จ', `เพิ่มผู้ทำ "${newOwner}" เข้าสู่ระบบเรียบร้อย`);
    } catch (err) {
      showProgErrorToast('เพิ่มผู้ทำไม่สำเร็จ', err.message);
    }
  }

  async function handleDelete(name, count) {
    const ok = await confirmDeleteOperator(name, count);
    if (!ok) {
      searchInput?.focus();
      return;
    }
    try {
      const res = await apiDeleteOperator(name);
      syncOperatorLists(res, null);
      renderList(searchInput.value);
      searchInput.focus();
      showProgToast('ลบตัวเลือกสำเร็จ', `ลบผู้ทำ "${name}" ออกจากรายการตัวเลือกแล้ว`);
    } catch (err) {
      showProgErrorToast('ลบผู้ทำไม่สำเร็จ', err.message);
    }
  }

  listEl?.addEventListener('click', (e) => {
    // Delete zone first: it is a separate button, so it can never trigger the select action
    const delBtn = e.target.closest('button[data-action="delete-operator"]');
    if (delBtn) {
      e.stopPropagation();
      handleDelete(delBtn.getAttribute('data-name'), parseInt(delBtn.getAttribute('data-count'), 10) || 0);
      return;
    }

    const saveOnlyBtn = e.target.closest('button[data-action="create-only"]');
    if (saveOnlyBtn) {
      handleSaveOnly(saveOnlyBtn.getAttribute('data-name'));
      return;
    }

    const addBtn = e.target.closest('button[data-action="create-and-assign"]');
    if (addBtn) {
      handleAssign(addBtn.getAttribute('data-name'), true);
      return;
    }

    const item = e.target.closest('.prog-op-item');
    if (item) {
      handleAssign(item.getAttribute('data-value') || '', false);
    }
  });

  // Keyboard navigation (arrow keys only visit select targets, never the delete buttons)
  searchInput?.addEventListener('keydown', (e) => {
    const items = listEl.querySelectorAll('.prog-op-add-option, .prog-op-item');

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (items.length === 0) return;
      const step = e.key === 'ArrowDown' ? 1 : -1;
      highlightedIndex = (highlightedIndex + step + items.length) % items.length;
      items.forEach((it, idx) => it.classList.toggle('is-highlighted', idx === highlightedIndex));
      items[highlightedIndex]?.scrollIntoView({ block: 'nearest' });
      return;
    }

    if (e.key === 'Enter') {
      e.preventDefault();
      if (highlightedIndex >= 0 && items[highlightedIndex]) {
        items[highlightedIndex].click();
        return;
      }
      const addOption = listEl.querySelector('.prog-op-add-option');
      if (addOption) {
        addOption.click();
        return;
      }
      const firstItem = listEl.querySelector('.prog-op-item:not(.is-unassign)') || listEl.querySelector('.prog-op-item');
      firstItem?.click();
    }
  });

  // Close on outside pointerdown (the confirm popup lives outside the dropdown, so ignore it)
  opPopoverOutsideHandler = (e) => {
    if (e.target.closest('.prog-op-confirm-overlay')) return;
    if (!popover.contains(e.target) && !anchorBtn.contains(e.target)) {
      closeOperatorDropdown();
    }
  };
  setTimeout(() => {
    if (activeOpPopoverEl === popover) document.addEventListener('pointerdown', opPopoverOutsideHandler);
  }, 10);

  opPopoverKeyHandler = (e) => {
    if (e.key === 'Escape') {
      closeOperatorDropdown();
      anchorBtn?.focus();
    }
  };
  document.addEventListener('keydown', opPopoverKeyHandler);
}
