/**
 * AOI Program Tracker - Main Entry Script
 */

document.addEventListener('DOMContentLoaded', () => {
  initProgState();
  initProgKpis();
  initProgStageGuide();
  initProgCommandBar();
  initProgTableEvents();
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
          requestProgStageChange(progId, stageNum, nextVal);
        }
      }
      return;
    }
  });
}
