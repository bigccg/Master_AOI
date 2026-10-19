/**
 * KPI cards (components/kpi-cards.php).
 */

function renderKpi(kpi, sp) {
    const elTotal = document.getElementById('kpi-total');
    const elRange = document.getElementById('kpi-shelf-range');
    const elGood = document.getElementById('kpi-good');
    const elPctGood = document.getElementById('kpi-pct-good');
    const elDefect = document.getElementById('kpi-defect');
    const elMixed = document.getElementById('kpi-mixed');
    const elUtilTitle = document.getElementById('kpi-util-title');
    const elUtilText = document.getElementById('kpi-util-text');
    const elUtilBar = document.getElementById('kpi-util-bar');

    if (elTotal) elTotal.textContent = kpi.total;
    if (elRange) elRange.textContent = kpi.shelf_range;
    if (elGood) elGood.textContent = kpi.g;
    if (elPctGood) elPctGood.textContent = `${kpi.pct_g}% of section`;
    if (elDefect) elDefect.textContent = kpi.d;
    if (elMixed) elMixed.textContent = kpi.gd;
    if (elUtilTitle) {
        const utilPrefix = (sp === 'ALL' || state.sheet === 'ALL') ? 'All Zones' : `Rack ${h(sp)}`;
        elUtilTitle.innerHTML = `${utilPrefix} Utilization <span class="text-slate-400">(${kpi.used}/${kpi.capacity})</span>`;
    }
    if (elUtilText) elUtilText.textContent = `${kpi.util}%`;
    if (elUtilBar) elUtilBar.style.width = `${kpi.util}%`;
}

// KPI card spotlight follows cursor (visual effect)
document.querySelectorAll('main > section.grid > div').forEach(card => {
    card.addEventListener('pointermove', e => {
        const r = card.getBoundingClientRect();
        card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
        card.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
});
