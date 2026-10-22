/**
 * AOI Program Tracker - 4-Stage Glass Dynamic (HyperOS) Stepper & Interactive Operations
 */

let activeContextMenuEl = null;

function formatProgramDate(dtStr) {
  if (!dtStr) return '—';
  try {
    const d = new Date(dtStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(dtStr);
    const now = new Date();
    const diffMs = now - d;
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
    const pad = n => String(n).padStart(2, '0');
    const timeStr = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    if (diffDays === 0 && d.getDate() === now.getDate()) {
      return `วันนี้ ${timeStr}`;
    }
    if (diffDays <= 1) {
      return `เมื่อวาน ${timeStr}`;
    }
    if (diffDays < 7) {
      return `${diffDays} วันที่แล้ว`;
    }
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  } catch (e) {
    return String(dtStr);
  }
}
window.formatProgramDate = formatProgramDate;

function renderProgStepperHtml(row) {
  const s1 = parseInt(row.stage_1, 10) || 0;
  const s2 = parseInt(row.stage_2, 10) || 0;
  const s3 = parseInt(row.stage_3, 10) || 0;
  const s4 = parseInt(row.stage_4, 10) || 0;

  const stages = [
    { num: 1, key: 'stage_1', val: s1, name: 'Capture P/D', by: row.stage_1_by, at: row.stage_1_at },
    { num: 2, key: 'stage_2', val: s2, name: 'Make Good/Sheet', by: row.stage_2_by, at: row.stage_2_at },
    { num: 3, key: 'stage_3', val: s3, name: 'Master G+D', by: row.stage_3_by, at: row.stage_3_at },
    { num: 4, key: 'stage_4', val: s4, name: 'Run Test', by: row.stage_4_by, at: row.stage_4_at },
  ];

  // Current active step (first step that is not complete and not N/A)
  let nextStepNum = null;
  for (const stg of stages) {
    if (stg.val === 0 || stg.val === 1) {
      nextStepNum = stg.num;
      break;
    }
  }

  let hasSkippedBefore = false;
  const cellsHtml = stages.map((stg, idx) => {
    let stateCls = 'is-idle';
    let iconHtml = '';
    let statusShort = 'ยังไม่เริ่ม';

    if (stg.val === 2) {
      stateCls = 'is-complete';
      iconHtml = '<i class="fa-solid fa-check prog-step-icon" aria-hidden="true"></i>';
      statusShort = 'เสร็จสมบูรณ์';
    } else if (stg.val === 1) {
      stateCls = 'is-progress';
      iconHtml = '<i class="fa-solid fa-circle-half-stroke prog-step-icon" aria-hidden="true"></i>';
      statusShort = 'กำลังทำ';
    } else if (stg.val === 9) {
      stateCls = 'is-na';
      iconHtml = '<span class="prog-step-na-label">N/A</span>';
      statusShort = 'ไม่ต้องทำ (N/A)';
    } else {
      iconHtml = '<i class="fa-regular fa-circle prog-step-icon is-muted" aria-hidden="true"></i>';
    }

    const isNext = (stg.num === nextStepNum);
    if (isNext) stateCls += ' is-next';

    let showWarning = false;
    if (stg.val === 2 && hasSkippedBefore) {
      showWarning = true;
    }
    if (stg.val === 0) {
      hasSkippedBefore = true;
    }

    // Connector line to next capsule: fills when current step is complete (or N/A)
    let connectorHtml = '';
    if (idx < stages.length - 1) {
      const isFilled = (stg.val === 2 || stg.val === 9);
      const isPartial = (stg.val === 1);
      connectorHtml = `<span class="prog-step-connector ${isFilled ? 'is-active' : (isPartial ? 'is-partial' : '')}" aria-hidden="true"><span class="prog-step-connector-fill"></span></span>`;
    }

    const byStr = stg.by ? `โดย ${h(stg.by)}` : 'ยังไม่ระบุผู้ทำ';
    const atStr = stg.at ? formatProgramDate(stg.at) : 'ยังไม่บันทึกเวลา';
    const tooltipText = `ขั้น ${stg.num} · ${h(stg.name)} · ${statusShort} · ${byStr} · ${h(atStr)}`;

    return `
      <div class="prog-step-cell ${stateCls}"
           data-prog-id="${row.id}"
           data-stage-num="${stg.num}"
           data-status-val="${stg.val}"
           tabindex="0"
           role="button"
           aria-label="${tooltipText}">
        ${iconHtml}
        ${showWarning ? '<span class="prog-step-warning" title="ขั้นตอนนี้เสร็จก่อนขั้นตอนก่อนหน้า">!</span>' : ''}
        <div class="prog-tooltip" role="tooltip">${tooltipText}</div>
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
  if (val === 2) return 'เสร็จสมบูรณ์';
  if (val === 1) return 'กำลังทำ';
  if (val === 9) return 'ไม่ต้องทำ (N/A)';
  return 'ยังไม่เริ่ม';
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

  updateRowInDom(row);
  flashRowInDom(progId);

  try {
    const res = await apiUpdateStage(progId, stageNum, nextStatus, originalRow.version, note);

    if (res.program) {
      progState.rows[rowIdx] = res.program;
      updateRowInDom(res.program);
    }
    if (res.kpi) {
      progState.kpi = res.kpi;
      renderProgKpis();
    }
    if (Array.isArray(res.owners)) {
      populateProgOwners(res.owners);
    }

    const stageNames = ['', 'Capture P/D', 'Make Good/Sheet', 'Master G+D', 'Run Test'];
    const sName = stageNames[stageNum] || `ขั้นที่ ${stageNum}`;
    showProgToast(
      `อัปเดตขั้น ${stageNum} (${sName}) สำเร็จ`,
      `${row.product_model} · ${row.side} → ${getStageStatusLabel(nextStatus)}`,
      async () => {
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
          showProgToast('เลิกทำสำเร็จ', `คืนค่า ${row.product_model} (${row.side}) เป็น ${getStageStatusLabel(oldStatus)}`);
        } catch (undoErr) {
          showProgErrorToast('เลิกทำไม่สำเร็จ', undoErr.message);
        }
      }
    );
  } catch (err) {
    console.error('Stage update failed:', err);
    progState.rows[rowIdx] = originalRow;
    updateRowInDom(originalRow);

    if (err.status === 409 || (err.data && err.data.conflict)) {
      showProgErrorToast(
        'ข้อมูลถูกแก้ไขโดยผู้อื่น',
        'มีผู้ใช้อื่นอัปเดตรายการนี้ไปแล้ว กรุณากดโหลดข้อมูลใหม่',
        () => loadProgramsData()
      );
    } else {
      showProgErrorToast('บันทึกสถานะไม่สำเร็จ', err.message);
    }
  }
}

/**
 * Stepper Context Menu Popover
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
  menu.setAttribute('role', 'menu');

  const winW = window.innerWidth;
  const winH = window.innerHeight;
  const menuW = 190;
  const menuH = 195;
  const posX = Math.min(x, winW - menuW - 10);
  const posY = Math.min(y, winH - menuH - 10);

  menu.style.left = `${Math.max(10, posX)}px`;
  menu.style.top = `${Math.max(10, posY)}px`;

  menu.innerHTML = `
    <div class="prog-step-context-header">ขั้น ${stageNum} · ${h(sName)}</div>
    <button type="button" class="prog-step-context-item ${currVal === 0 ? 'is-active' : ''}" data-val="0" role="menuitem">
      <i class="fa-regular fa-circle prog-ctx-icon is-idle" aria-hidden="true"></i>
      <span>ตั้งเป็น ยังไม่เริ่ม</span>
    </button>
    <button type="button" class="prog-step-context-item ${currVal === 1 ? 'is-active' : ''}" data-val="1" role="menuitem">
      <i class="fa-solid fa-circle-half-stroke prog-ctx-icon is-wip" aria-hidden="true"></i>
      <span>ตั้งเป็น กำลังทำ</span>
    </button>
    <button type="button" class="prog-step-context-item ${currVal === 2 ? 'is-active' : ''}" data-val="2" role="menuitem">
      <i class="fa-solid fa-check prog-ctx-icon is-done" aria-hidden="true"></i>
      <span>ตั้งเป็น เสร็จสมบูรณ์</span>
    </button>
    <button type="button" class="prog-step-context-item ${currVal === 9 ? 'is-active' : ''}" data-val="9" role="menuitem">
      <i class="fa-solid fa-slash prog-ctx-icon is-na" aria-hidden="true"></i>
      <span>ตั้งเป็น ไม่ต้องทำ (N/A)</span>
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
