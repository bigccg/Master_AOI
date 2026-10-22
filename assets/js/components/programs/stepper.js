/**
 * AOI Program Tracker - Stepper Rendering & Interactive Operations
 */

let activeContextMenuEl = null;

function renderProgStepperHtml(row) {
  const s1 = parseInt(row.stage_1, 10) || 0;
  const s2 = parseInt(row.stage_2, 10) || 0;
  const s3 = parseInt(row.stage_3, 10) || 0;
  const s4 = parseInt(row.stage_4, 10) || 0;

  const stages = [
    { num: 1, key: 'stage_1', val: s1, name: 'Capture P/D', th: 'ถ่ายภาพ P/D', by: row.stage_1_by, at: row.stage_1_at },
    { num: 2, key: 'stage_2', val: s2, name: 'Make Good/Sheet', th: 'สร้างแผ่น Good/Sheet', by: row.stage_2_by, at: row.stage_2_at },
    { num: 3, key: 'stage_3', val: s3, name: 'Master G+D', th: 'รวม Master G+D', by: row.stage_3_by, at: row.stage_3_at },
    { num: 4, key: 'stage_4', val: s4, name: 'Run Test', th: 'ทดสอบโปรแกรมเสร็จ', by: row.stage_4_by, at: row.stage_4_at },
  ];

  // Find next step (first step that is not complete and not N/A)
  let nextStepNum = null;
  for (const stg of stages) {
    if (stg.val === 0 || stg.val === 1) {
      nextStepNum = stg.num;
      break;
    }
  }

  // Check skip warnings (e.g. step 4 complete while step 2 or 3 is not complete)
  let hasSkippedBefore = false;
  const cellsHtml = stages.map((stg, idx) => {
    let stateCls = '';
    let iconHtml = '';
    let statusLabel = 'ยังไม่เริ่ม / Not Started';

    if (stg.val === 2) {
      stateCls = 'is-complete';
      iconHtml = '<i class="fa-solid fa-check text-[11px]"></i>';
      statusLabel = 'เสร็จสมบูรณ์ / Complete';
    } else if (stg.val === 1) {
      stateCls = 'is-progress';
      iconHtml = '<i class="fa-solid fa-circle-half-stroke text-[11px]"></i>';
      statusLabel = 'กำลังดำเนินการ / In Progress';
    } else if (stg.val === 9) {
      stateCls = 'is-na';
      iconHtml = '<span class="text-[10px] font-mono font-bold">N/A</span>';
      statusLabel = 'ไม่ต้องทำ / Not Needed';
    } else {
      iconHtml = '<i class="fa-regular fa-circle text-[10px] text-slate-300"></i>';
    }

    const isNext = (stg.num === nextStepNum);
    if (isNext) stateCls += ' is-next';

    // Skip warning flag
    let showWarning = false;
    if (stg.val === 2 && hasSkippedBefore) {
      showWarning = true;
    }
    if (stg.val === 0) {
      hasSkippedBefore = true;
    }

    // Connector line to next cell
    let connectorHtml = '';
    if (idx < stages.length - 1) {
      const isConnectorActive = (stg.val === 2 || stg.val === 9);
      connectorHtml = `<span class="prog-step-connector ${isConnectorActive ? 'is-active' : ''}"></span>`;
    }

    const byText = stg.by ? `โดย: ${h(stg.by)}` : '';
    const atText = stg.at ? ` (${formatProgramDate(stg.at)})` : '';
    const updaterInfo = (stg.by || stg.at) ? `<br><span class="text-slate-300 text-[10px]">${byText}${atText}</span>` : '';

    return `
      <div class="prog-step-cell ${stateCls}"
           data-prog-id="${row.id}"
           data-stage-num="${stg.num}"
           data-status-val="${stg.val}"
           tabindex="0"
           role="button"
           aria-label="ขั้นที่ ${stg.num}: ${stg.name} (${statusLabel})">
        ${iconHtml}
        ${showWarning ? '<span class="prog-step-warning" title="คำเตือน: ขั้นตอนนี้เสร็จก่อนขั้นตอนก่อนหน้า">⚠</span>' : ''}
        <div class="prog-tooltip">
          <strong>${stg.num}. ${h(stg.name)}</strong> (${h(stg.th)})<br>
          <span class="text-emerald-300 font-medium">${statusLabel}</span>
          ${updaterInfo}
          <div class="text-[9px] text-slate-400 mt-0.5">คลิก: เลื่อนสถานะ · คลิกขวา/Shift: เมนูเลือก</div>
        </div>
      </div>
      ${connectorHtml}
    `;
  }).join('');

  return `<div class="prog-stepper-track">${cellsHtml}</div>`;
}

function getNextStageStatus(curr) {
  if (curr === 0) return 1; // Not started -> In Progress
  if (curr === 1) return 2; // In Progress -> Complete
  if (curr === 2) return 0; // Complete -> Reset
  return 0; // N/A -> Reset
}

function getStageStatusLabel(val) {
  if (val === 2) return 'เสร็จสิ้น / Complete';
  if (val === 1) return 'กำลังทำ / In Progress';
  if (val === 9) return 'ข้าม (N/A) / Skip';
  return 'ยังไม่เริ่ม / Not Started';
}

/**
 * Perform stage update with optimistic UI update and undo toast
 */
async function applyProgStageUpdate(progId, stageNum, nextStatus, note = null) {
  const rowIdx = progState.rows.findIndex(r => r.id === progId);
  if (rowIdx === -1) return;

  const originalRow = Object.assign({}, progState.rows[rowIdx]);
  const row = progState.rows[rowIdx];
  const oldStatus = parseInt(row[`stage_${stageNum}`], 10) || 0;

  // Optimistic update
  row[`stage_${stageNum}`] = nextStatus;
  if (nextStatus === 0) {
    row[`stage_${stageNum}_by`] = '';
    row[`stage_${stageNum}_at`] = '';
  } else {
    row[`stage_${stageNum}_by`] = progState.currentUser || 'Thanapol';
    row[`stage_${stageNum}_at`] = new Date().toISOString();
  }

  // Update DOM optimistically
  updateRowInDom(row);
  flashRowInDom(progId);

  try {
    const res = await apiUpdateStage(progId, stageNum, nextStatus, originalRow.version, note);

    // Apply server canonical response
    if (res.program) {
      progState.rows[rowIdx] = res.program;
      updateRowInDom(res.program);
    }
    if (res.kpi) {
      progState.kpi = res.kpi;
      renderProgKpis();
    }

    // Show Undo Toast
    const stageNames = ['', 'Capture P/D', 'Make Good', 'Master G+D', 'Run Test'];
    const sName = stageNames[stageNum] || `ขั้นที่ ${stageNum}`;
    showProgToast(
      `อัปเดต ${sName} สำเร็จ`,
      `${row.product_model} (${row.side}): ${getStageStatusLabel(nextStatus)}`,
      async () => {
        // Undo callback
        try {
          const undoRes = await apiUndoAction(progId, res.audit_id);
          if (undoRes.program) {
            progState.rows[rowIdx] = undoRes.program;
            updateRowInDom(undoRes.program);
            flashRowInDom(progId);
          }
          if (undoRes.kpi) {
            progState.kpi = undoRes.kpi;
            renderProgKpis();
          }
          showProgToast('เลิกทำสำเร็จ', `คืนค่า ${row.product_model} เป็น: ${getStageStatusLabel(oldStatus)}`);
        } catch (undoErr) {
          showProgErrorToast('ไม่สามารถเลิกทำได้', undoErr.message);
        }
      }
    );
  } catch (err) {
    console.error('Stage update failed:', err);
    // Rollback to original
    progState.rows[rowIdx] = originalRow;
    updateRowInDom(originalRow);

    if (err.status === 409 || (err.data && err.data.conflict)) {
      showProgErrorToast(
        'ข้อมูลถูกแก้ไขโดยผู้อื่น',
        'มีผู้ใช้อื่นอัปเดตรายการนี้แล้ว กรุณากดปุ่มเพื่อโหลดข้อมูลล่าสุด',
        () => loadProgramsData()
      );
    } else {
      showProgErrorToast('บันทึกไม่สำเร็จ', err.message);
    }
  }
}

/**
 * Stepper Context Menu
 */
function closeProgContextMenu() {
  if (activeContextMenuEl) {
    activeContextMenuEl.remove();
    activeContextMenuEl = null;
  }
}

function openProgContextMenu(stepCell, x, y) {
  closeProgContextMenu();

  const progId = parseInt(stepCell.getAttribute('data-prog-id'), 10);
  const stageNum = parseInt(stepCell.getAttribute('data-stage-num'), 10);
  const currVal = parseInt(stepCell.getAttribute('data-status-val'), 10) || 0;
  const row = progState.rows.find(r => r.id === progId);
  if (!row) return;

  const stageNames = ['', 'Capture P/D', 'Make Good/Sheet', 'Master G+D', 'Run Test'];
  const sName = stageNames[stageNum] || `ขั้นที่ ${stageNum}`;

  const menu = document.createElement('div');
  menu.className = 'prog-step-context-menu';

  // Position nicely within viewport
  const winW = window.innerWidth;
  const winH = window.innerHeight;
  const menuW = 180;
  const menuH = 190;
  const posX = Math.min(x, winW - menuW - 10);
  const posY = Math.min(y, winH - menuH - 10);

  menu.style.left = `${Math.max(10, posX)}px`;
  menu.style.top = `${Math.max(10, posY)}px`;

  menu.innerHTML = `
    <div class="prog-step-context-header">${stageNum}. ${h(sName)}</div>
    <button type="button" class="prog-step-context-item ${currVal === 0 ? 'is-active' : ''}" data-val="0">
      <i class="fa-regular fa-circle text-slate-400"></i>
      <span>ยังไม่เริ่ม / Not Started</span>
    </button>
    <button type="button" class="prog-step-context-item ${currVal === 1 ? 'is-active' : ''}" data-val="1">
      <i class="fa-solid fa-circle-half-stroke text-amber-500"></i>
      <span>กำลังทำ / In Progress</span>
    </button>
    <button type="button" class="prog-step-context-item ${currVal === 2 ? 'is-active' : ''}" data-val="2">
      <i class="fa-solid fa-circle-check text-emerald-600"></i>
      <span>เสร็จสิ้น / Complete</span>
    </button>
    <button type="button" class="prog-step-context-item ${currVal === 9 ? 'is-active' : ''}" data-val="9">
      <i class="fa-solid fa-minus text-slate-400"></i>
      <span>ข้าม (N/A) / Skip</span>
    </button>
  `;

  document.body.appendChild(menu);
  activeContextMenuEl = menu;

  menu.querySelectorAll('.prog-step-context-item').forEach(btn => {
    btn.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const val = parseInt(btn.getAttribute('data-val'), 10);
      closeProgContextMenu();
      if (val !== currVal) {
        applyProgStageUpdate(progId, stageNum, val);
      }
    });
  });
}

// Close context menu on outside click or Esc
document.addEventListener('click', (e) => {
  if (activeContextMenuEl && !activeContextMenuEl.contains(e.target)) {
    closeProgContextMenu();
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeProgContextMenu();
  }
});
