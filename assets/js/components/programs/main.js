/**
 * AOI Program Tracker - Main Entry Script
 */

document.addEventListener('DOMContentLoaded', () => {
  initProgState();
  initProgKpis();
  initProgCommandBar();
  initProgTableEvents();
  initTableSortingEvents();
  initProgKeyboardShortcuts();

  loadProgramsData();
});

function initProgKeyboardShortcuts() {
  document.addEventListener('keydown', (e) => {
    const active = document.activeElement;
    const isTyping = active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT');

    // 1-4 -> If row is focused and activeElement is tr, advance that stage
    if (['1', '2', '3', '4'].includes(e.key) && !isTyping) {
      const row = active?.closest('tr.prog-row');
      if (row && active.tagName === 'TR') {
        const stageNum = parseInt(e.key, 10);
        const cell = row.querySelector(`.prog-step-cell[data-stage-num="${stageNum}"]`);
        if (cell) {
          e.preventDefault();
          const progId = parseInt(cell.getAttribute('data-prog-id'), 10);
          const currVal = parseInt(cell.getAttribute('data-status-val'), 10) || 0;
          const nextVal = getNextStageStatus(currVal);
          applyProgStageUpdate(progId, stageNum, nextVal);
        }
      }
      return;
    }
  });
}

function syncTableSortHeaders() {
  document.querySelectorAll('#prog-table .aoi-th.is-sortable').forEach(th => {
    const col = th.getAttribute('data-sort');
    const ico = th.querySelector('.prog-th-sort-icon');
    if (col === progState.sort) {
      th.setAttribute('aria-sort', progState.dir === 'asc' ? 'ascending' : 'descending');
      if (ico) {
        ico.className = `fa-solid fa-sort-${progState.dir === 'asc' ? 'up' : 'down'} prog-th-sort-icon text-white text-[10px]`;
      }
    } else {
      th.setAttribute('aria-sort', 'none');
      if (ico) {
        ico.className = 'fa-solid fa-sort prog-th-sort-icon opacity-60 text-[10px]';
      }
    }
  });
}

function initTableSortingEvents() {
  syncTableSortHeaders();

  document.querySelectorAll('#prog-table .aoi-th.is-sortable').forEach(th => {
    th.addEventListener('click', () => {
      const col = th.getAttribute('data-sort');
      if (!col) return;
      if (progState.sort === col) {
        progState.dir = (progState.dir === 'asc') ? 'desc' : 'asc';
      } else {
        progState.sort = col;
        progState.dir = 'asc';
      }
      progState.page = 1;
      syncTableSortHeaders();
      loadProgramsData();
    });
  });
}
