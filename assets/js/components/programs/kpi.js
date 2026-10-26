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
}

function initProgKpis() {
  // KPI cards are display-only (no click-to-filter). Keep the cursor spotlight hover effect.
  document.querySelectorAll('.prog-kpi-card').forEach(card => {
    card.addEventListener('pointermove', e => {
      const r = card.getBoundingClientRect();
      card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      card.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
  });
}
