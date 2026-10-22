/**
 * AOI Program Tracker - KPI Component Logic
 */

function renderProgKpis() {
  const kpi = progState.kpi || {};

  const elTotal = document.getElementById('kpi-num-total');
  const elComplete = document.getElementById('kpi-num-complete');
  const elPctComplete = document.getElementById('kpi-pct-complete');
  const elBarComplete = document.getElementById('kpi-bar-complete');
  const elWaitMaster = document.getElementById('kpi-num-wait-master');
  const elQueued = document.getElementById('kpi-num-queued');
  const elOverdue = document.getElementById('kpi-num-overdue');
  const elBreakdown = document.getElementById('kpi-breakdown-mach');

  if (elTotal) elTotal.textContent = String(kpi.total ?? 0);
  if (elComplete) elComplete.textContent = String(kpi.complete ?? 0);
  if (elPctComplete) elPctComplete.textContent = `${kpi.pct_complete ?? 0}%`;
  if (elBarComplete) elBarComplete.style.width = `${kpi.pct_complete ?? 0}%`;
  if (elWaitMaster) elWaitMaster.textContent = String(kpi.wait_master ?? 0);
  if (elQueued) elQueued.textContent = String(kpi.queued ?? 0);
  if (elOverdue) elOverdue.textContent = String(kpi.overdue ?? 0);

  if (elBreakdown) {
    elBreakdown.innerHTML = `
      <span>OMRON ${kpi.omron ?? 0}</span>
      <span class="text-slate-300">·</span>
      <span>YAMAHA ${kpi.yamaha ?? 0}</span>
      <span class="text-slate-300">·</span>
      <span>TRI ${kpi.tri ?? 0}</span>
    `;
    elBreakdown.setAttribute('title', `OMRON ${kpi.omron ?? 0} · YAMAHA ${kpi.yamaha ?? 0} · TRI ${kpi.tri ?? 0}`);
  }

  // Update active state of KPI cards
  document.querySelectorAll('.prog-kpi-card').forEach(card => {
    const filter = card.getAttribute('data-kpi-filter');
    const isActive = (progState.status === filter);
    card.classList.toggle('is-active', isActive);
    card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
  });
}

function initProgKpis() {
  document.querySelectorAll('.prog-kpi-card').forEach(card => {
    // Cursor spotlight (same effect as the index.php KPI cards)
    card.addEventListener('pointermove', e => {
      const r = card.getBoundingClientRect();
      card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      card.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
    });
    card.addEventListener('click', () => {
      const targetFilter = card.getAttribute('data-kpi-filter');
      if (progState.status === targetFilter && targetFilter !== 'ALL') {
        // Toggle off if already active
        progState.status = 'ALL';
      } else {
        progState.status = targetFilter;
      }
      progState.page = 1;

      // Sync select dropdown
      const selStatus = document.getElementById('prog-filter-status');
      if (selStatus) selStatus.value = progState.status;

      loadProgramsData();
    });
  });
}
