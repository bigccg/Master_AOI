/**
 * Export Station Controller — Glass HyperOS + Hypermodern Edition
 * Manages multi-zone, multi-sheet, year/month, Bare/Bad Mark, and Defect Matrix export configuration,
 * real-time telemetry preview with smooth number interpolation, and Excel (.xlsx) / CSV download.
 */

const esState = {
    format: 'xlsx',          // 'xlsx' | 'csv'
    includeTables: 'both',   // 'both' | 'matrix' | 'boards'
    zones: new Set(),        // empty = ALL zones
    sheets: new Set(),       // empty = ALL sheets
    year: 'ALL',             // 'ALL' | '2026' | ...
    months: new Set(),       // empty = ALL months
    otherMode: 'ALL',        // 'ALL' | 'Bare' | 'Bad Mark' | 'BARE_OR_BAD' | 'NONE'
    statusMode: 'ALL',       // 'ALL' | 'DEFECT_ALL' | 'G' | 'D' | 'GD'
};

let esPreviewCtrl = null;
let esAnimFrames  = {};
let esCloseTimer  = null;

/**
 * Smoothly interpolate a numeric element from its current value to targetVal.
 */
function animateMetricNumber(elId, targetVal) {
    const el = document.getElementById(elId);
    if (!el) return;
    const startVal = parseInt(String(el.textContent).replace(/[^0-9]/g, ''), 10) || 0;
    const endVal   = Number(targetVal) || 0;
    if (startVal === endVal) {
        el.textContent = endVal.toLocaleString('en-US');
        return;
    }
    if (esAnimFrames[elId]) cancelAnimationFrame(esAnimFrames[elId]);

    const duration = 260;
    const t0       = performance.now();

    function step(now) {
        const p    = Math.min(1, (now - t0) / duration);
        const ease = 1 - Math.pow(1 - p, 3); // cubic ease-out
        const curr = Math.round(startVal + (endVal - startVal) * ease);
        el.textContent = curr.toLocaleString('en-US');
        if (p < 1) {
            esAnimFrames[elId] = requestAnimationFrame(step);
        }
    }
    esAnimFrames[elId] = requestAnimationFrame(step);
}

/**
 * Sync Export Station state from current page filters when opening.
 */
function syncEsStateFromPage(preset = null) {
    esState.format        = 'xlsx';
    esState.includeTables = preset === 'matrix' ? 'matrix' : 'both';
    esState.zones.clear();
    esState.sheets.clear();
    esState.months.clear();
    esState.year = 'ALL';

    if (typeof state !== 'undefined') {
        if (state.zone && state.zone !== 'ALL') {
            esState.zones.add(state.zone);
        }
        if (state.sheet && state.sheet !== 'ALL') {
            esState.sheets.add(state.sheet);
        }
        if (state.period && /^\d{4}-\d{2}$/.test(state.period)) {
            const [y, m] = state.period.split('-');
            esState.year = y;
            esState.months.add(m);
        }
        esState.otherMode  = (state.other && state.other !== 'ALL') ? state.other : 'ALL';
        esState.statusMode = (state.status && state.status !== 'ALL') ? state.status : 'ALL';
    }
}

/**
 * Reset all filters in the Export Station back to "All Zones · All Sizes · All Time".
 */
function resetExportStationFilters() {
    esState.zones.clear();
    esState.sheets.clear();
    esState.year = 'ALL';
    esState.months.clear();
    esState.otherMode  = 'ALL';
    esState.statusMode = 'ALL';
    esState.includeTables = 'both';
    renderExportStationControls();
    refreshExportStationPreview();
}
window.resetExportStationFilters = resetExportStationFilters;

/**
 * Open Export Station Modal.
 */
function openExportStation(preset = null) {
    const overlay = document.getElementById('export-station');
    if (!overlay) return;

    clearTimeout(esCloseTimer);
    syncEsStateFromPage(preset);
    renderExportStationControls();

    overlay.classList.remove('es-closing', 'hidden');
    overlay.setAttribute('aria-hidden', 'false');

    refreshExportStationPreview();

    requestAnimationFrame(() => {
        document.getElementById('es-submit-btn')?.focus();
    });
}
window.openExportStation = openExportStation;

/**
 * Close Export Station Modal with smooth exit transition.
 */
function closeExportStation() {
    const overlay = document.getElementById('export-station');
    if (!overlay || overlay.classList.contains('hidden') || overlay.classList.contains('es-closing')) return;

    overlay.classList.add('es-closing');
    overlay.setAttribute('aria-hidden', 'true');
    esCloseTimer = setTimeout(() => {
        overlay.classList.add('hidden');
        overlay.classList.remove('es-closing');
    }, 240);
}
window.closeExportStation = closeExportStation;

/**
 * Render active states of all buttons and chips inside Export Station.
 */
function renderExportStationControls() {
    // 1. Format Switcher
    const fmtSwitch = document.querySelector('.es-format-switch');
    if (fmtSwitch) {
        fmtSwitch.setAttribute('data-active-format', esState.format);
        fmtSwitch.querySelectorAll('.es-format-btn').forEach(btn => {
            const on = btn.dataset.esFormat === esState.format;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-checked', on ? 'true' : 'false');
        });
    }

    // 2. Included Tables Cards
    document.querySelectorAll('.es-table-card[data-es-tables]').forEach(card => {
        const on = card.dataset.esTables === esState.includeTables;
        card.classList.toggle('is-selected', on);
    });

    // 3. Zone Chips
    const isAllZ = esState.zones.size === 0;
    document.querySelectorAll('#es-zone-chips .es-chip[data-es-zone]').forEach(chip => {
        const zk = chip.dataset.esZone;
        const on = zk === 'ALL' ? isAllZ : esState.zones.has(zk);
        chip.classList.toggle('is-active', on);
    });

    // 4. Sheet / Size Chips (also highlight/dim based on selected zones)
    const isAllS = esState.sheets.size === 0;
    document.querySelectorAll('#es-sheet-chips .es-chip[data-es-sheet]').forEach(chip => {
        const shk = chip.dataset.esSheet;
        const shZone = chip.dataset.esSheetZone || '';
        const on = shk === 'ALL' ? isAllS : esState.sheets.has(shk);
        chip.classList.toggle('is-active', on);
        if (shk !== 'ALL') {
            const zoneMatches = isAllZ || esState.zones.has(shZone);
            chip.classList.toggle('is-dimmed', !zoneMatches && !on);
        }
    });

    // 5. Year Chips
    document.querySelectorAll('#es-year-chips .es-chip[data-es-year]').forEach(chip => {
        const y = chip.dataset.esYear;
        chip.classList.toggle('is-active', y === esState.year);
    });

    // 6. Month Chips
    const isAllM = esState.months.size === 0;
    document.querySelectorAll('#es-month-chips .es-chip[data-es-month]').forEach(chip => {
        const m = chip.dataset.esMonth;
        const on = m === 'ALL' ? isAllM : esState.months.has(m);
        chip.classList.toggle('is-active', on);
    });

    // 7. Other (Bare / Bad Mark) Chips
    document.querySelectorAll('#es-other-chips .es-chip[data-es-other]').forEach(chip => {
        chip.classList.toggle('is-active', chip.dataset.esOther === esState.otherMode);
    });

    // 8. Status Chips
    document.querySelectorAll('#es-status-chips .es-chip[data-es-status]').forEach(chip => {
        chip.classList.toggle('is-active', chip.dataset.esStatus === esState.statusMode);
    });

    // 9. Update Submit Button Label
    const submitLabel = document.getElementById('es-submit-label');
    if (submitLabel) {
        if (esState.format === 'csv') {
            submitLabel.textContent = 'ส่งออกไฟล์ CSV (.csv)';
        } else if (esState.includeTables === 'matrix') {
            submitLabel.textContent = 'ส่งออก Excel (Defect Matrix)';
        } else if (esState.includeTables === 'boards') {
            submitLabel.textContent = 'ส่งออก Excel (Master Boards)';
        } else {
            submitLabel.textContent = 'ส่งออก Excel (.xlsx) จัดตาราง';
        }
    }
}

/**
 * Build URL parameters representing the current Export Station configuration.
 */
function buildExportStationQueryParams(isPreview = false) {
    const params = new URLSearchParams();
    if (isPreview) {
        params.set('export_preview', '1');
    } else {
        params.set('export', '1');
        params.set('format', esState.format);
        params.set('include_tables', esState.includeTables);
    }

    params.set('zones', esState.zones.size > 0 ? Array.from(esState.zones).join(',') : 'ALL');
    params.set('sheets', esState.sheets.size > 0 ? Array.from(esState.sheets).join(',') : 'ALL');
    params.set('year', esState.year || 'ALL');
    params.set('months', esState.months.size > 0 ? Array.from(esState.months).join(',') : 'ALL');
    params.set('other_mode', esState.otherMode || 'ALL');
    params.set('status_mode', esState.statusMode || 'ALL');

    if (typeof state !== 'undefined') {
        if (state.search) params.set('q', state.search);
        if (state.board_type && state.board_type !== 'ALL') params.set('board_type', state.board_type);
        if (state.shelf && state.shelf !== 'ALL') params.set('shelf', state.shelf);
    }

    return params;
}

/**
 * Fetch real-time telemetry counts from ?export_preview=1 and update the UI smoothly.
 */
async function refreshExportStationPreview() {
    const liveStatus     = document.getElementById('es-live-status');
    const liveStatusText = document.getElementById('es-live-status-text');
    if (liveStatus) liveStatus.classList.add('is-loading');
    if (liveStatusText) liveStatusText.textContent = 'กำลังคำนวณข้อมูล...';

    esPreviewCtrl?.abort();
    const ctrl = esPreviewCtrl = new AbortController();

    const url = new URL('index.php', window.location.href);
    url.search = buildExportStationQueryParams(true).toString();

    let json;
    try {
        const res = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            signal: ctrl.signal,
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        json = await res.json();
    } catch (err) {
        if (err.name === 'AbortError') return;
        console.error('Export preview failed:', err);
        if (liveStatus) liveStatus.classList.remove('is-loading');
        if (liveStatusText) liveStatusText.textContent = 'พร้อมส่งออกข้อมูล';
        return;
    }

    if (liveStatus) liveStatus.classList.remove('is-loading');
    if (liveStatusText) liveStatusText.textContent = 'พร้อมส่งออกทันที';

    const totalBoards = Number(json.total_boards ?? 0);
    const matrixRows  = Number(json.matrix_rows ?? 0);
    const cntG        = Number(json.cnt_g ?? 0);
    const cntD        = Number(json.cnt_d ?? 0);
    const cntGD       = Number(json.cnt_gd ?? 0);
    const cntBare     = Number(json.cnt_bare ?? 0);
    const cntBad      = Number(json.cnt_bad_mark ?? 0);

    animateMetricNumber('es-count-boards', totalBoards);
    animateMetricNumber('es-count-matrix', matrixRows);
    animateMetricNumber('es-count-special', cntBare + cntBad);
    animateMetricNumber('es-count-g', cntG);
    animateMetricNumber('es-count-d', cntD);
    animateMetricNumber('es-count-gd', cntGD);
    animateMetricNumber('es-count-bare', cntBare);
    animateMetricNumber('es-count-bad', cntBad);

    const submitBadge = document.getElementById('es-submit-badge');
    if (submitBadge) {
        if (esState.includeTables === 'matrix' && esState.format === 'xlsx') {
            submitBadge.textContent = `${matrixRows} rows`;
        } else {
            submitBadge.textContent = `${totalBoards} รายการ`;
        }
    }

    const summaryEl = document.getElementById('es-summary-text');
    if (summaryEl && json.summary_text) {
        summaryEl.textContent = json.summary_text;
        summaryEl.title       = json.summary_text;
    }

    // Update per-zone counts
    if (json.zone_counts) {
        let sumAllZ = 0;
        Object.entries(json.zone_counts).forEach(([zk, c]) => {
            sumAllZ += Number(c) || 0;
            const badge = document.querySelector(`[data-es-zcount="${zk}"]`);
            if (badge) badge.textContent = String(c);
        });
        const allZBadge = document.getElementById('es-zcount-ALL');
        if (allZBadge) allZBadge.textContent = String(sumAllZ);
    }

    // Update per-sheet counts
    if (json.sheet_counts) {
        Object.entries(json.sheet_counts).forEach(([shk, c]) => {
            const badge = document.querySelector(`[data-es-shcount="${shk}"]`);
            if (badge) badge.textContent = String(c);
        });
    }

    // Update year chips dynamically if additional years exist in DB
    if (json.years) {
        const yearBox = document.getElementById('es-year-chips');
        if (yearBox) {
            Object.entries(json.years).forEach(([yr, c]) => {
                let chip = yearBox.querySelector(`.es-chip[data-es-year="${yr}"]`);
                if (!chip) {
                    chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'es-chip';
                    chip.dataset.esYear = yr;
                    chip.innerHTML = `<span class="es-chip-label">ปี ${yr}</span><span class="es-chip-count" data-es-ycount="${yr}">${c}</span>`;
                    yearBox.appendChild(chip);
                } else {
                    const yb = chip.querySelector(`[data-es-ycount="${yr}"]`);
                    if (yb) yb.textContent = String(c);
                }
                chip.classList.toggle('is-active', esState.year === yr);
            });
        }
    }

    // Update month counts
    if (json.months) {
        document.querySelectorAll('[data-es-mcount]').forEach(el => {
            const m = el.getAttribute('data-es-mcount');
            el.textContent = String(json.months[m] ?? 0);
        });
    }
}

/**
 * Trigger the Excel (.xlsx) or CSV file download.
 */
function triggerExportStationDownload() {
    const url = new URL('index.php', window.location.href);
    url.search = buildExportStationQueryParams(false).toString();

    const submitBtn = document.getElementById('es-submit-btn');
    const submitIco = document.getElementById('es-submit-ico');
    if (submitBtn && submitIco) {
        submitIco.className = 'fa-solid fa-circle-check es-submit-ico';
        setTimeout(() => {
            submitIco.className = 'fa-solid fa-file-arrow-down es-submit-ico';
        }, 1400);
    }

    // Trigger download via hidden anchor so current page state stays untouched
    const a = document.createElement('a');
    a.href = url.toString();
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => a.remove(), 1000);

    if (typeof showFlash === 'function') {
        const fmtName = esState.format === 'xlsx' ? 'Excel (.xlsx) จัดระเบียบตาราง' : 'CSV (.csv)';
        showFlash('ok', `กำลังดาวน์โหลดไฟล์ ${fmtName} ตามตัวกรองที่เลือกเรียบร้อยแล้ว`);
    }
}
window.triggerExportStationDownload = triggerExportStationDownload;

/**
 * Initialize Export Station event listeners.
 */
function setupExportStationEvents() {
    const overlay = document.getElementById('export-station');
    const win     = document.getElementById('es-window');
    if (!overlay || !win) return;

    // Intercept header #export-btn click to open Export Station
    const exportBtn = document.getElementById('export-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openExportStation();
        });
    }

    // Pointer-tracking liquid glass spotlight inside #es-window
    win.addEventListener('pointermove', (e) => {
        const rect = win.getBoundingClientRect();
        win.style.setProperty('--es-mx', `${Math.round(e.clientX - rect.left)}px`);
        win.style.setProperty('--es-my', `${Math.round(e.clientY - rect.top)}px`);
    });

    // Backdrop click closes modal
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            closeExportStation();
        }
    });

    // Delegate clicks on format switcher, table cards, and filter chips
    win.addEventListener('click', (e) => {
        // Format switcher
        const fmtBtn = e.target.closest('.es-format-btn[data-es-format]');
        if (fmtBtn) {
            esState.format = fmtBtn.dataset.esFormat;
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }

        // Included tables card
        const tableCard = e.target.closest('.es-table-card[data-es-tables]');
        if (tableCard) {
            esState.includeTables = tableCard.dataset.esTables;
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }

        // Zone chip (multi-select)
        const zoneChip = e.target.closest('.es-chip[data-es-zone]');
        if (zoneChip) {
            const zk = zoneChip.dataset.esZone;
            if (zk === 'ALL') {
                esState.zones.clear();
            } else {
                if (esState.zones.has(zk)) esState.zones.delete(zk);
                else esState.zones.add(zk);
            }
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }

        // Sheet / Size chip (multi-select)
        const sheetChip = e.target.closest('.es-chip[data-es-sheet]');
        if (sheetChip) {
            const shk = sheetChip.dataset.esSheet;
            if (shk === 'ALL') {
                esState.sheets.clear();
            } else {
                if (esState.sheets.has(shk)) esState.sheets.delete(shk);
                else esState.sheets.add(shk);
            }
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }

        // Year chip
        const yearChip = e.target.closest('.es-chip[data-es-year]');
        if (yearChip) {
            esState.year = yearChip.dataset.esYear;
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }

        // Month chip (multi-select)
        const monthChip = e.target.closest('.es-chip[data-es-month]');
        if (monthChip) {
            const m = monthChip.dataset.esMonth;
            if (m === 'ALL') {
                esState.months.clear();
            } else {
                if (esState.months.has(m)) esState.months.delete(m);
                else esState.months.add(m);
            }
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }

        // Other (Bare / Bad Mark) chip
        const otherChip = e.target.closest('.es-chip[data-es-other]');
        if (otherChip) {
            esState.otherMode = otherChip.dataset.esOther;
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }

        // Status chip
        const statusChip = e.target.closest('.es-chip[data-es-status]');
        if (statusChip) {
            esState.statusMode = statusChip.dataset.esStatus;
            renderExportStationControls();
            refreshExportStationPreview();
            return;
        }
    });

    // Keyboard navigation (Escape to close, Tab trap)
    document.addEventListener('keydown', (e) => {
        if (overlay.classList.contains('hidden') || overlay.classList.contains('es-closing')) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            closeExportStation();
        } else if (e.key === 'Tab' && typeof gmTrapTab === 'function') {
            gmTrapTab(e, win);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupExportStationEvents);
} else {
    setupExportStationEvents();
}
