/**
 * AOI Program Tracker - 4-Stage Glass Dynamic (HyperOS) Stepper & Interactive Operations
 */

let activeContextMenuEl = null;

function parseProgramDate(dtStr) {
  if (!dtStr) return null;
  const d = new Date(String(dtStr).replace(' ', 'T'));
  return isNaN(d.getTime()) ? null : d;
}

/** DD/MM/YYYY */
function formatProgramDate(dtStr) {
  if (!dtStr) return '—';
  const d = parseProgramDate(dtStr);
  if (!d) return String(dtStr);
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

/** DD/MM/YYYY HH:mm น. (used in tooltips) */
function formatProgramDateTime(dtStr) {
  if (!dtStr) return '—';
  const d = parseProgramDate(dtStr);
  if (!d) return String(dtStr);
  const pad = n => String(n).padStart(2, '0');
  return `${formatProgramDate(dtStr)} ${pad(d.getHours())}:${pad(d.getMinutes())} น.`;
}
window.formatProgramDate = formatProgramDate;
window.formatProgramDateTime = formatProgramDateTime;

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
    let statusShort = 'ยังไม่ได้ทำ';

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
      iconHtml = `<span class="prog-step-num" aria-hidden="true">${stg.num}</span>`;
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
    const atStr = stg.at ? formatProgramDateTime(stg.at) : 'ยังไม่บันทึกเวลา';
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

/**
 * Pipeline now has two user-selectable states: Not started (0) <-> Complete (2).
 * Legacy values (1 = in progress, 9 = N/A) may still exist in old rows; they are shown as-is
 * but any click moves them to Complete.
 */
function getNextStageStatus(curr) {
  return curr === 2 ? 0 : 2;
}

function getStageStatusLabel(val) {
  if (val === 2) return 'เสร็จสมบูรณ์';
  if (val === 1) return 'กำลังทำ';
  if (val === 9) return 'ไม่ต้องทำ (N/A)';
  return 'ยังไม่ได้ทำ';
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
  const menuH = 125;
  const posX = Math.min(x, winW - menuW - 10);
  const posY = Math.min(y, winH - menuH - 10);

  menu.style.left = `${Math.max(10, posX)}px`;
  menu.style.top = `${Math.max(10, posY)}px`;

  menu.innerHTML = `
    <div class="prog-step-context-header">ขั้น ${stageNum} · ${h(sName)}</div>
    <button type="button" class="prog-step-context-item ${currVal === 0 ? 'is-active' : ''}" data-val="0" role="menuitem">
      <i class="fa-regular fa-circle prog-ctx-icon is-idle" aria-hidden="true"></i>
      <span>ตั้งเป็น ยังไม่ได้ทำ</span>
    </button>
    <button type="button" class="prog-step-context-item ${currVal === 2 ? 'is-active' : ''}" data-val="2" role="menuitem">
      <i class="fa-solid fa-check prog-ctx-icon is-done" aria-hidden="true"></i>
      <span>ตั้งเป็น เสร็จสมบูรณ์</span>
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
        requestProgStageChange(progId, stageNum, val);
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


/**
 * Stage Guide: collapsible strip above the table. Open by default so first-time visitors
 * see it immediately; once someone collapses it the choice is remembered in this browser.
 */
function initProgStageGuide() {
  const root = document.getElementById('prog-stage-guide');
  const btn = document.getElementById('prog-guide-toggle');
  const hint = document.getElementById('prog-guide-hint');
  if (!root || !btn) return;

  const KEY = 'aoiProgGuideCollapsed';
  const apply = (collapsed) => {
    root.classList.toggle('is-collapsed', collapsed);
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    if (hint) hint.textContent = collapsed ? 'แสดง' : 'ซ่อน';
  };

  let collapsed = false;
  try { collapsed = localStorage.getItem(KEY) === '1'; } catch (e) { /* storage blocked */ }
  apply(collapsed);

  btn.addEventListener('click', () => {
    collapsed = !root.classList.contains('is-collapsed');
    apply(collapsed);
    try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
  });
}


// ──────────────────────────────────────────────
// Stage change confirmation — Glass Dynamic popup
// Every status change goes through requestProgStageChange(); nothing is saved until the user confirms.
// ──────────────────────────────────────────────
let activeStageConfirmEl = null;

function closeStageConfirm() {
  if (!activeStageConfirmEl) return;
  const el = activeStageConfirmEl;
  activeStageConfirmEl = null;
  if (el._keyHandler) document.removeEventListener('keydown', el._keyHandler, true);
  el.classList.add('is-closing');
  setTimeout(() => el.remove(), 220);
}

function stageChipHtml(val, num) {
  if (val === 2) return '<span class="prog-sc-chip is-complete"><i class="fa-solid fa-check"></i></span>';
  if (val === 1) return '<span class="prog-sc-chip is-progress"><i class="fa-solid fa-circle-half-stroke"></i></span>';
  if (val === 9) return '<span class="prog-sc-chip is-na">N/A</span>';
  return `<span class="prog-sc-chip is-idle">${num}</span>`;
}

/** Resolves true only when the user explicitly confirms. */
function confirmProgStageChange(row, stageNum, fromVal, toVal) {
  closeStageConfirm();

  return new Promise((resolve) => {
    const meta = (window.PROGRAM_STAGES && window.PROGRAM_STAGES[stageNum]) || {};
    const stageLabel = meta.label || `ขั้นที่ ${stageNum}`;
    const stageDesc = meta.th || '';
    const toComplete = (toVal === 2);

    const sideRaw = String(row.side || 'F-Side').trim();
    const isF = sideRaw.toUpperCase().startsWith('F');
    const mach = String(row.machine_type || '');
    const machLabel = (mach.includes('3D') || mach.includes('YAMAHA')) ? '3D' : (mach.includes('TRI') ? 'TRI' : '2D');

    // Warn when completing a stage while an earlier one is still not done
    const skipped = [];
    if (toComplete) {
      for (let n = 1; n < stageNum; n++) {
        const v = parseInt(row[`stage_${n}`], 10) || 0;
        if (v === 0 || v === 1) skipped.push(n);
      }
    }
    const noteHtml = skipped.length
      ? `<div class="prog-sc-note is-warn"><i class="fa-solid fa-triangle-exclamation"></i><span>ขั้น ${skipped.join(', ')} ยังไม่เสร็จ — ยืนยันว่าจะทำขั้น ${stageNum} ข้ามลำดับ?</span></div>`
      : (toComplete
          ? ''
          : '<div class="prog-sc-note"><i class="fa-solid fa-rotate-left"></i><span>ระบบจะล้างชื่อผู้ทำและเวลาของขั้นนี้ (กดเลิกทำได้ภายหลัง)</span></div>');

    const user = (progState.currentUser || '').trim();

    const overlay = document.createElement('div');
    overlay.className = 'prog-sc-overlay';
    overlay.innerHTML = `
      <div class="prog-sc ${toComplete ? 'is-to-complete' : 'is-to-idle'}" role="alertdialog" aria-modal="true" aria-labelledby="prog-sc-title">
        <span class="prog-sc-aurora" aria-hidden="true"></span>

        <div class="prog-sc-morph" aria-hidden="true">
          ${stageChipHtml(fromVal, stageNum)}
          <span class="prog-sc-flow"><i></i><i></i><i></i></span>
          <span class="prog-sc-target">${stageChipHtml(toVal, stageNum)}</span>
        </div>

        <h3 id="prog-sc-title" class="prog-sc-title">${toComplete ? 'ยืนยันว่าขั้นนี้เสร็จสมบูรณ์?' : 'ย้อนกลับเป็น “ยังไม่ได้ทำ”?'}</h3>

        <div class="prog-sc-card">
          <div class="prog-sc-card-row">
            <strong class="prog-sc-product">${h(row.product_model)}</strong>
            <span class="prog-side-badge ${isF ? 'is-f' : 'is-b'}">${isF ? 'F' : 'B'}</span>
            <span class="prog-mach-badge ${machLabel === '3D' ? 'is-yamaha' : (machLabel === 'TRI' ? 'is-tri' : 'is-omron')}">${machLabel}</span>
          </div>
          <div class="prog-sc-card-stage">
            <span class="prog-sc-stage-num">${stageNum}</span>
            <div><b>${h(stageLabel)}</b>${stageDesc ? `<small>${h(stageDesc)}</small>` : ''}</div>
          </div>
          <div class="prog-sc-card-change">
            <span>${h(getStageStatusLabel(fromVal))}</span>
            <i class="fa-solid fa-arrow-right-long" aria-hidden="true"></i>
            <span class="is-to">${toComplete ? 'เสร็จสมบูรณ์' : 'ยังไม่ได้ทำ'}</span>
          </div>
        </div>

        ${noteHtml}

        <div class="prog-sc-actions">
          <button type="button" class="prog-sc-btn is-cancel" data-act="cancel">ยกเลิก <kbd>Esc</kbd></button>
          <button type="button" class="prog-sc-btn is-ok" data-act="ok">
            <i class="fa-solid ${toComplete ? 'fa-check' : 'fa-rotate-left'}" aria-hidden="true"></i>
            <span>${toComplete ? 'ยืนยัน เสร็จแล้ว' : 'ยืนยัน ย้อนกลับ'}</span>
            <span class="prog-sc-sheen" aria-hidden="true"></span>
          </button>
        </div>
        ${user ? `<div class="prog-sc-by"><i class="fa-regular fa-user"></i> บันทึกในชื่อ ${h(user)}</div>` : ''}
      </div>
    `;

    document.body.appendChild(overlay);
    activeStageConfirmEl = overlay;

    const finish = (val) => {
      if (activeStageConfirmEl !== overlay) return;
      closeStageConfirm();
      resolve(val);
    };

    overlay.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-act]');
      if (btn) finish(btn.getAttribute('data-act') === 'ok');
      else if (e.target === overlay) finish(false);
    });

    // Capture phase so Esc / Tab / Enter only affect this popup
    overlay._keyHandler = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault(); e.stopPropagation();
        finish(false);
      } else if (e.key === 'Tab') {
        const btns = [...overlay.querySelectorAll('button')];
        const idx = btns.indexOf(document.activeElement);
        e.preventDefault(); e.stopPropagation();
        const next = e.shiftKey ? (idx <= 0 ? btns.length - 1 : idx - 1) : (idx + 1) % btns.length;
        btns[next].focus();
      }
    };
    document.addEventListener('keydown', overlay._keyHandler, true);

    // Confirm is focused: Enter confirms, Esc cancels
    overlay.querySelector('[data-act="ok"]')?.focus();
  });
}

/** Single entry point for every status change (click, menu, keyboard). */
async function requestProgStageChange(progId, stageNum, nextVal) {
  if (activeStageConfirmEl) return;
  const row = progState.rows.find(r => r.id === progId);
  if (!row) return;
  const fromVal = parseInt(row[`stage_${stageNum}`], 10) || 0;
  if (fromVal === nextVal) return;

  const ok = await confirmProgStageChange(row, stageNum, fromVal, nextVal);
  if (ok) applyProgStageUpdate(progId, stageNum, nextVal);
}
