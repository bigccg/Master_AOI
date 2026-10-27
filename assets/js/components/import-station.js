/**
 * Import Station Controller — 3-Step Excel Batch Import
 *
 * Step 1: Download Pre-Numbered Excel Template (with live Number Code & Rack telemetry)
 * Step 2: Upload Filled .xlsx File (Drag & Drop + Client Pre-Check)
 * Step 3: Dry-Run Preview Table, Error Filter, .xlsx Error Report Download & All-or-Nothing Commit
 */

let isCloseTimer = null;
let isLastTriggerEl = null;
let isCurrentStep = 1;
let isSelectedFile = null;
let isLastPreviewData = null;
let isCurrentRowFilter = 'all'; // 'all' | 'errors'
let isTelemetryAbortCtrl = null;

/**
 * HTML-escape helper for safe DOM rendering.
 */
function isEsc(val) {
    if (typeof esc === 'function') {
        return esc(val);
    }
    return String(val ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Read active Zone and Sheet from global app state.
 */
function isGetActiveAppZoneSheet() {
    const curZone = (typeof state !== 'undefined' && state.zone)
        ? state.zone
        : (window.initialZone || 'General');
    const curSheet = (typeof state !== 'undefined' && state.sheet)
        ? state.sheet
        : (window.initialSheet || 'ALL');
    return { zone: curZone, sheet: curSheet };
}

/**
 * Update live status indicator in modal header.
 */
function isSetHeaderStatus(text, tone = 'ready') {
    const statusText = document.getElementById('is-live-status-text');
    const statusDot  = document.getElementById('is-live-dot');
    if (statusText) statusText.textContent = text;
    if (statusDot) {
        statusDot.className = 'is-live-dot';
        if (tone === 'busy') statusDot.classList.add('is-busy');
        else if (tone === 'error') statusDot.classList.add('is-error');
    }
}

/**
 * Open Import Station Modal at the requested step (1 = Download Template, 2 = Upload .xlsx).
 */
function openImportStation(step = 1, triggerEl = null) {
    // Close Export Station if open
    if (typeof closeExportStation === 'function') {
        const esOverlay = document.getElementById('export-station');
        if (esOverlay && !esOverlay.classList.contains('hidden')) {
            closeExportStation();
        }
    }

    const overlay = document.getElementById('import-station');
    if (!overlay) return;

    isLastTriggerEl = triggerEl || document.activeElement;

    clearTimeout(isCloseTimer);
    overlay.classList.remove('is-closing', 'hidden');
    overlay.setAttribute('aria-hidden', 'false');

    // Sync Zone & Sheet dropdowns with active page state
    const { zone, sheet } = isGetActiveAppZoneSheet();
    const isAllMode = (!zone || zone === 'ALL' || !sheet || sheet === 'ALL');
    const allNotice = document.getElementById('is-all-mode-notice');
    if (allNotice) {
        allNotice.classList.toggle('hidden', !isAllMode);
    }

    const zSelect = document.getElementById('is-select-zone');
    const sSelect = document.getElementById('is-select-sheet');
    if (zSelect && zone && zone !== 'ALL') {
        const hasZoneOpt = Array.from(zSelect.options).some((o) => o.value === zone);
        if (hasZoneOpt) zSelect.value = zone;
    }
    isHandleZoneChange(false);

    if (sSelect && sheet && sheet !== 'ALL') {
        const matchingOpt = Array.from(sSelect.options).find(
            (o) => o.value === sheet && !o.disabled && o.style.display !== 'none'
        );
        if (matchingOpt) {
            sSelect.value = sheet;
        }
    }

    isRefreshTemplateTelemetry();
    isGoToStep(step);

    setTimeout(() => {
        const firstFocus = step === 2
            ? document.getElementById('is-dropzone')
            : document.getElementById('is-select-zone');
        if (firstFocus) firstFocus.focus();
    }, 60);
}
window.openImportStation = openImportStation;
window.isSwitchTab = openImportStation;

/**
 * Close Import Station Modal and restore focus to trigger element.
 */
function closeImportStation() {
    const overlay = document.getElementById('import-station');
    if (!overlay || overlay.classList.contains('hidden')) return;

    overlay.classList.add('is-closing');
    overlay.setAttribute('aria-hidden', 'true');

    clearTimeout(isCloseTimer);
    isCloseTimer = setTimeout(() => {
        overlay.classList.add('hidden');
        overlay.classList.remove('is-closing');
        if (isLastTriggerEl && typeof isLastTriggerEl.focus === 'function') {
            try { isLastTriggerEl.focus(); } catch (_) {}
        }
    }, 220);
}
window.closeImportStation = closeImportStation;

/**
 * Switch active step in the 3-step workflow (1, 2, or 3).
 */
function isGoToStep(step) {
    if (step === 3 && !isLastPreviewData) {
        step = 2;
    }
    isCurrentStep = step;

    for (let i = 1; i <= 3; i++) {
        const btn   = document.getElementById(`is-step-btn-${i}`);
        const panel = document.getElementById(`is-panel-step-${i}`);
        const active = (i === step);

        if (btn) {
            btn.classList.toggle('is-active', active);
            btn.classList.toggle('is-done', i < step);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
            if (i === 3) {
                btn.disabled = !isLastPreviewData;
            }
        }
        if (panel) {
            panel.classList.toggle('hidden', !active);
        }
    }
}
window.isGoToStep = isGoToStep;

/**
 * Filter sheet options when target Zone changes in Step 1.
 */
function isHandleZoneChange(refreshTelemetry = true) {
    const zSelect = document.getElementById('is-select-zone');
    const sSelect = document.getElementById('is-select-sheet');
    if (!zSelect || !sSelect) return;

    const selectedZone = zSelect.value;
    let firstVisible = null;

    Array.from(sSelect.options).forEach((opt) => {
        const optZone = opt.getAttribute('data-zone');
        const matches = (!optZone || optZone === selectedZone);
        opt.style.display = matches ? '' : 'none';
        opt.disabled = !matches;
        if (matches && !firstVisible) {
            firstVisible = opt;
        }
    });

    const curOpt = sSelect.selectedOptions[0];
    if (!curOpt || curOpt.disabled || curOpt.style.display === 'none') {
        if (firstVisible) {
            sSelect.value = firstVisible.value;
        } else {
            sSelect.value = '';
        }
    }

    if (refreshTelemetry) {
        isRefreshTemplateTelemetry();
    }
}
window.isHandleZoneChange = isHandleZoneChange;

/**
 * Fetch live template telemetry (next Number Code range, remaining monthly quota, Rack range) for Step 1.
 */
function isRefreshTemplateTelemetry() {
    const zSelect = document.getElementById('is-select-zone');
    const sSelect = document.getElementById('is-select-sheet');
    const rSelect = document.getElementById('is-select-rows');
    const dlBtn   = document.getElementById('is-download-template-btn');

    const codeRangeEl = document.getElementById('is-tpl-code-range');
    const remainingEl = document.getElementById('is-tpl-remaining');
    const shelfRangeEl = document.getElementById('is-tpl-shelf-range');
    const freeShelvesEl = document.getElementById('is-tpl-free-shelves');
    const capBanner   = document.getElementById('is-tpl-cap-banner');
    const capText     = document.getElementById('is-tpl-cap-text');

    const zone  = zSelect ? zSelect.value : '';
    const sheet = sSelect ? sSelect.value : '';
    const rows  = rSelect ? rSelect.value : '50';

    if (!zone || !sheet) {
        if (codeRangeEl) codeRangeEl.textContent = '-';
        if (remainingEl) remainingEl.textContent = 'กรุณาเลือกโซนและชีตที่เปิดใช้งาน';
        if (shelfRangeEl) shelfRangeEl.textContent = '-';
        if (freeShelvesEl) freeShelvesEl.textContent = '-';
        if (dlBtn) dlBtn.disabled = true;
        return;
    }

    if (isTelemetryAbortCtrl) {
        isTelemetryAbortCtrl.abort();
    }
    isTelemetryAbortCtrl = new AbortController();

    const params = new URLSearchParams({
        mode: 'info',
        zone,
        sheet,
        rows,
    });

    fetch(`api/import_template.php?${params.toString()}`, {
        method: 'GET',
        headers: { Accept: 'application/json' },
        signal: isTelemetryAbortCtrl.signal,
    })
        .then((res) => res.json())
        .then((data) => {
            const ctx = data.context || {};
            if (!data.success || !ctx.ok) {
                const errMsg = (data.errors && data.errors[0]) || ctx.error || 'ไม่สามารถสร้างแม่แบบได้';
                if (codeRangeEl) codeRangeEl.textContent = 'เต็มโควตา / ไม่พร้อมใช้งาน';
                if (remainingEl) remainingEl.textContent = errMsg;
                if (capBanner && capText) {
                    capBanner.className = 'is-banner is-banner-error';
                    capText.textContent = errMsg;
                    capBanner.classList.remove('hidden');
                }
                if (dlBtn) dlBtn.disabled = true;
                return;
            }

            if (dlBtn) dlBtn.disabled = false;

            if (codeRangeEl) {
                codeRangeEl.textContent = `${ctx.start_code} – ${ctx.end_code} (${ctx.actual_rows} แถว)`;
            }
            if (remainingEl) {
                remainingEl.textContent = `เดือน ${ctx.year_month} เหลือรหัสว่างอีก ${ctx.remaining_in_month} รหัส (สูงสุด 999)`;
            }
            if (shelfRangeEl) {
                shelfRangeEl.textContent = `${ctx.shelf_prefix}1 – ${ctx.shelf_prefix}${ctx.max_shelf_no} (${ctx.mag_count} Magazine)`;
            }
            if (freeShelvesEl) {
                freeShelvesEl.textContent = `ว่าง ${ctx.free_shelf_count} ช่อง (มีบอร์ดแล้ว ${ctx.occupied_count}/${ctx.max_shelf_no} ช่อง)`;
            }

            if (capBanner && capText) {
                if (ctx.capped_by_month_limit && ctx.cap_notice) {
                    capBanner.className = 'is-banner is-banner-warn';
                    capText.textContent = ctx.cap_notice;
                    capBanner.classList.remove('hidden');
                } else {
                    capBanner.classList.add('hidden');
                }
            }
        })
        .catch((err) => {
            if (err && err.name === 'AbortError') return;
        });
}
window.isRefreshTemplateTelemetry = isRefreshTemplateTelemetry;

/**
 * Download the signed .xlsx Import Template for the selected Zone, Sheet, and Row Count.
 */
function isDownloadTemplate() {
    const zSelect = document.getElementById('is-select-zone');
    const sSelect = document.getElementById('is-select-sheet');
    const rSelect = document.getElementById('is-select-rows');

    const zone  = zSelect ? zSelect.value : '';
    const sheet = sSelect ? sSelect.value : '';
    const rows  = rSelect ? rSelect.value : '50';

    if (!zone || !sheet || zone === 'ALL' || sheet === 'ALL') {
        if (typeof showFlashToast === 'function') {
            showFlashToast('กรุณาเลือกโซนและชีตปลายทางก่อนดาวน์โหลดแม่แบบ / Please select a target Zone and Sheet first.', 'error');
        }
        return;
    }

    const params = new URLSearchParams({
        zone,
        sheet,
        rows,
    });

    window.location.href = `api/import_template.php?${params.toString()}`;

    if (typeof showFlashToast === 'function') {
        showFlashToast(`กำลังดาวน์โหลดแม่แบบ Excel สำหรับโซน ${zone} · ชีต ${sheet} (${rows} แถว)`, 'success');
    }
}
window.isDownloadTemplate = isDownloadTemplate;

/**
 * Download a fresh template directly from the Step 3 Expired Template banner.
 */
function isDownloadFreshTemplateFromExpired() {
    const meta = (isLastPreviewData && isLastPreviewData.meta) || {};
    const summary = (isLastPreviewData && isLastPreviewData.summary) || {};
    const zone = meta.zone || summary.zone || (document.getElementById('is-select-zone')?.value || '');
    const sheet = meta.sheet || summary.sheet || (document.getElementById('is-select-sheet')?.value || '');
    const rows = meta.row_count || summary.total_template_rows || 50;

    if (zone && sheet) {
        const params = new URLSearchParams({ zone, sheet, rows: String(rows) });
        window.location.href = `api/import_template.php?${params.toString()}`;
        if (typeof showFlashToast === 'function') {
            showFlashToast(`กำลังดาวน์โหลดแม่แบบใหม่พร้อมรหัสล่าสุดสำหรับ ${zone} · ${sheet}`, 'success');
        }
    } else {
        isGoToStep(1);
    }
}
window.isDownloadFreshTemplateFromExpired = isDownloadFreshTemplateFromExpired;

/**
 * Trigger hidden file input in Step 2.
 */
function isTriggerFilePicker() {
    const input = document.getElementById('is-file-input');
    if (input) {
        input.value = '';
        input.click();
    }
}
window.isTriggerFilePicker = isTriggerFilePicker;

/**
 * Update Dropzone visual state ('idle' | 'loading' | 'error' | 'ready').
 */
function isSetDropzoneState(stateName, titleText, subText) {
    const dz    = document.getElementById('is-dropzone');
    const icon  = document.getElementById('is-dropzone-icon');
    const label = document.getElementById('is-filename-label');
    const sub   = document.getElementById('is-dropzone-sub');

    if (!dz) return;
    dz.classList.remove('is-loading', 'is-error', 'is-ready');
    if (stateName !== 'idle') {
        dz.classList.add(`is-${stateName}`);
    }

    if (icon) {
        if (stateName === 'loading') {
            icon.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        } else if (stateName === 'error') {
            icon.innerHTML = '<i class="fa-solid fa-file-circle-xmark"></i>';
        } else if (stateName === 'ready') {
            icon.innerHTML = '<i class="fa-solid fa-file-circle-check"></i>';
        } else {
            icon.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i>';
        }
    }
    if (label && titleText) label.textContent = titleText;
    if (sub && subText) sub.textContent = subText;
}

/**
 * Handle file selection from input or drag-and-drop in Step 2.
 */
function isHandleFileSelect(e) {
    const files = (e.target && e.target.files) || (e.dataTransfer && e.dataTransfer.files);
    if (!files || !files.length) return;

    const file = files[0];
    const errBanner = document.getElementById('is-upload-error-banner');
    const errText   = document.getElementById('is-upload-error-text');
    const recheckBtn = document.getElementById('is-recheck-btn');

    if (errBanner) errBanner.classList.add('hidden');

    // Client-side pre-checks: extension .xlsx & size <= 2 MB
    if (!file.name.toLowerCase().endsWith('.xlsx')) {
        const msg = 'ไม่สามารถอ่านไฟล์ได้ เนื่องจากไฟล์ที่เลือกไม่ใช่ไฟล์ .xlsx กรุณาเลือกไฟล์แม่แบบ Excel (.xlsx) ที่ดาวน์โหลดจากระบบเท่านั้น / Please select a valid .xlsx file.';
        isSetDropzoneState('error', `ไฟล์ไม่รองรับ: ${file.name}`, 'ต้องเป็นไฟล์นามสกุล .xlsx เท่านั้น');
        if (errBanner && errText) {
            errText.textContent = msg;
            errBanner.classList.remove('hidden');
        }
        return;
    }

    if (file.size > 2097152) {
        const msg = 'ไม่สามารถอ่านไฟล์ได้ เนื่องจากไฟล์มีขนาดใหญ่เกิน 2 MB กรุณาใช้ไฟล์แม่แบบจากระบบ / File exceeds the 2 MB maximum limit.';
        isSetDropzoneState('error', `ไฟล์ใหญ่เกินกำหนด: ${file.name}`, 'ขนาดสูงสุดไม่เกิน 2 MB');
        if (errBanner && errText) {
            errText.textContent = msg;
            errBanner.classList.remove('hidden');
        }
        return;
    }

    isSelectedFile = file;
    const kb = Math.max(1, Math.round(file.size / 1024));
    isSetDropzoneState('ready', `เลือกไฟล์แล้ว: ${file.name} (${kb} KB)`, 'กำลังส่งตรวจสอบความถูกต้องรายแถว...');
    if (recheckBtn) recheckBtn.disabled = false;

    isRunPreviewValidation();
}
window.isHandleFileSelect = isHandleFileSelect;

/**
 * Run Dry-Run Preview Validation against POST api/import_preview.php.
 */
function isRunPreviewValidation() {
    if (!isSelectedFile) return;

    const errBanner  = document.getElementById('is-upload-error-banner');
    const errText    = document.getElementById('is-upload-error-text');
    const recheckBtn = document.getElementById('is-recheck-btn');

    if (errBanner) errBanner.classList.add('hidden');
    if (recheckBtn) recheckBtn.disabled = true;

    isSetHeaderStatus('กำลังตรวจสอบไฟล์... / Validating...', 'busy');
    isSetDropzoneState('loading', `กำลังตรวจสอบ: ${isSelectedFile.name}`, 'ระบบกำลังตรวจ Number Code, Shelf, Status และ Defects ทุกแถว...');

    const { zone, sheet } = isGetActiveAppZoneSheet();
    const formData = new FormData();
    formData.append('file', isSelectedFile);
    formData.append('csrf', window.csrfToken || '');
    formData.append('zone', zone || '');
    formData.append('sheet', sheet || '');

    fetch('api/import_preview.php', {
        method: 'POST',
        body: formData,
    })
        .then(async (res) => {
            const data = await res.json();
            return { status: res.status, data };
        })
        .then(({ data }) => {
            if (recheckBtn) recheckBtn.disabled = false;

            // If file-level failure with zero parsed rows and not an expired template with rows, show on Step 2 or Step 3
            const hasRows = Array.isArray(data.rows) && data.rows.length > 0;
            if (!data.success && !hasRows && !data.template_expired) {
                const msg = (data.errors && data.errors[0]) || 'ไม่สามารถตรวจสอบไฟล์ได้ กรุณาตรวจสอบรูปแบบไฟล์';
                isSetHeaderStatus('พบปัญหาในไฟล์ / Validation Failed', 'error');
                isSetDropzoneState('error', `ตรวจสอบไม่ผ่าน: ${isSelectedFile.name}`, 'คลิกเพื่อเลือกไฟล์แม่แบบที่ถูกต้อง');
                if (errBanner && errText) {
                    errText.textContent = msg;
                    errBanner.classList.remove('hidden');
                }
                return;
            }

            isLastPreviewData = data;
            isSetDropzoneState('ready', `ตรวจสอบแล้ว: ${isSelectedFile.name}`, 'คลิกที่นี่หากต้องการเปลี่ยนไฟล์ใหม่');
            isRenderPreviewStep(data);
            isGoToStep(3);
        })
        .catch(() => {
            if (recheckBtn) recheckBtn.disabled = false;
            isSetHeaderStatus('เชื่อมต่อไม่สำเร็จ / Network Error', 'error');
            isSetDropzoneState('error', `เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ`, 'กรุณาตรวจสอบเครือข่ายแล้วลองอีกครั้ง');
            if (errBanner && errText) {
                errText.textContent = (typeof MSG !== 'undefined' && MSG.networkError)
                    ? MSG.networkError
                    : 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบเครือข่ายแล้วลองอีกครั้ง';
                errBanner.classList.remove('hidden');
            }
        });
}
window.isRunPreviewValidation = isRunPreviewValidation;

/**
 * Render Step 3 (Preview Summary Cards, Banners, Filter Counts, Table & Commit CTA).
 */
function isRenderPreviewStep(data) {
    const summary  = data.summary || {};
    const meta     = data.meta || {};
    const rows     = Array.isArray(data.rows) ? data.rows : [];
    const warnings = Array.isArray(data.warnings) ? data.warnings : [];
    const errors   = Array.isArray(data.errors) ? data.errors : [];

    const filledCount  = Number(summary.filled_rows ?? 0);
    const validCount   = Number(summary.valid_rows ?? 0);
    const errorCount   = Number(summary.error_rows ?? 0);
    const skippedCount = Number(summary.skipped_blank_rows ?? 0);

    // 1. Metadata Chips
    const metaZone  = document.getElementById('is-meta-zone');
    const metaSheet = document.getElementById('is-meta-sheet');
    const metaCodes = document.getElementById('is-meta-codes');
    if (metaZone) {
        const z = summary.zone || meta.zone || '-';
        const zl = summary.zone_label ? ` (${summary.zone_label})` : '';
        metaZone.textContent = `${z}${zl}`;
    }
    if (metaSheet) {
        metaSheet.textContent = summary.sheet || meta.sheet || '-';
    }
    if (metaCodes) {
        metaCodes.textContent = (summary.first_code && summary.last_code)
            ? `${summary.first_code} – ${summary.last_code}`
            : (meta.start_code ? `${meta.start_code} – ${meta.end_code}` : '-');
    }

    // 2. KPI Cards
    const kpiFilled  = document.getElementById('is-kpi-filled');
    const kpiValid   = document.getElementById('is-kpi-valid');
    const kpiErrors  = document.getElementById('is-kpi-errors');
    const kpiSkipped = document.getElementById('is-kpi-skipped');
    if (kpiFilled)  kpiFilled.textContent  = String(filledCount);
    if (kpiValid)   kpiValid.textContent   = String(validCount);
    if (kpiErrors)  kpiErrors.textContent  = String(errorCount);
    if (kpiSkipped) kpiSkipped.textContent = String(skippedCount);

    // 3. Warnings Banner
    const warnBanner = document.getElementById('is-preview-warn-banner');
    const warnText   = document.getElementById('is-preview-warn-text');
    if (warnBanner && warnText) {
        if (warnings.length > 0) {
            warnText.innerHTML = warnings.map((w) => `<div>${isEsc(w)}</div>`).join('');
            warnBanner.classList.remove('hidden');
        } else {
            warnBanner.classList.add('hidden');
        }
    }

    // 4. Expired Template Banner
    const expBanner = document.getElementById('is-expired-banner');
    const expText   = document.getElementById('is-expired-text');
    if (expBanner) {
        if (data.template_expired) {
            if (expText && errors.length > 0) {
                expText.textContent = errors[0];
            }
            expBanner.classList.remove('hidden');
        } else {
            expBanner.classList.add('hidden');
        }
    }

    // 5. Global Errors vs All-OK Banner
    const errBanner = document.getElementById('is-preview-error-banner');
    const errText   = document.getElementById('is-preview-error-text');
    const okBanner  = document.getElementById('is-preview-ok-banner');
    const okText    = document.getElementById('is-preview-ok-text');

    const nonExpiredErrors = data.template_expired ? errors.slice(1) : errors;
    if (errBanner && errText) {
        if (!data.success && nonExpiredErrors.length > 0) {
            errText.innerHTML = nonExpiredErrors.map((e) => `<div>${isEsc(e)}</div>`).join('');
            errBanner.classList.remove('hidden');
        } else {
            errBanner.classList.add('hidden');
        }
    }

    if (okBanner && okText) {
        if (data.success && errorCount === 0 && validCount > 0) {
            okText.textContent = `ผ่านการตรวจสอบครบทั้ง ${validCount} รายการ (${summary.first_code} – ${summary.last_code}) พร้อมบันทึกลงโซน ${summary.zone} · ชีต ${summary.sheet} ทันที / All ${validCount} row(s) validated and ready to import.`;
            okBanner.classList.remove('hidden');
            isSetHeaderStatus(`พร้อมนำเข้า ${validCount} รายการ / Ready`, 'ready');
        } else {
            okBanner.classList.add('hidden');
            isSetHeaderStatus(`พบข้อผิดพลาด ${errorCount} แถว / Needs Fix`, 'error');
        }
    }

    // 6. Filter Counts & Error Report Download Button
    const allCountEl = document.getElementById('is-filter-all-count');
    const errCountEl = document.getElementById('is-filter-err-count');
    if (allCountEl) allCountEl.textContent = String(rows.length);
    if (errCountEl) errCountEl.textContent = String(errorCount);

    const reportBtn = document.getElementById('is-download-error-report-btn');
    if (reportBtn) {
        reportBtn.classList.toggle('hidden', !data.error_report_xlsx_base64);
    }

    // Default filter: if there are errors, keep 'all' or let user toggle; reset to 'all'
    isSetRowFilter('all');

    // 7. Commit Button State (enabled ONLY when 0 errors and > 0 valid rows and not expired)
    const commitBtn   = document.getElementById('is-commit-btn');
    const commitLabel = document.getElementById('is-commit-btn-label');
    const canCommit   = Boolean(data.success && errorCount === 0 && validCount > 0 && !data.template_expired);

    if (commitBtn) {
        commitBtn.disabled = !canCommit;
    }
    if (commitLabel) {
        commitLabel.textContent = canCommit
            ? `นำเข้า ${validCount} รายการ / Import ${validCount} Board(s)`
            : 'ไม่สามารถนำเข้าได้ (กรุณาแก้ไขแถวที่ผิดก่อน)';
    }
}

/**
 * Toggle between showing All Rows vs Errors Only in Step 3 table.
 */
function isSetRowFilter(mode) {
    isCurrentRowFilter = (mode === 'errors') ? 'errors' : 'all';

    const allBtn = document.getElementById('is-filter-all-btn');
    const errBtn = document.getElementById('is-filter-err-btn');
    if (allBtn) {
        allBtn.classList.toggle('is-active', isCurrentRowFilter === 'all');
        allBtn.setAttribute('aria-pressed', isCurrentRowFilter === 'all' ? 'true' : 'false');
    }
    if (errBtn) {
        errBtn.classList.toggle('is-active', isCurrentRowFilter === 'errors');
        errBtn.setAttribute('aria-pressed', isCurrentRowFilter === 'errors' ? 'true' : 'false');
    }

    isRenderPreviewTableRows();
}
window.isSetRowFilter = isSetRowFilter;

/**
 * Render the rows inside #is-preview-tbody according to isCurrentRowFilter.
 */
function isRenderPreviewTableRows() {
    const tbody = document.getElementById('is-preview-tbody');
    if (!tbody) return;

    const allRows = (isLastPreviewData && Array.isArray(isLastPreviewData.rows))
        ? isLastPreviewData.rows
        : [];

    const filtered = isCurrentRowFilter === 'errors'
        ? allRows.filter((r) => !r.valid)
        : allRows;

    if (filtered.length === 0) {
        const emptyMsg = isCurrentRowFilter === 'errors'
            ? 'ไม่พบแถวที่มีข้อผิดพลาด (ทุกแถวผ่านการตรวจสอบเรียบร้อยแล้ว) / No error rows found.'
            : 'ไม่มีแถวข้อมูลสำหรับแสดงผล / No rows to display.';
        tbody.innerHTML = `<tr><td colspan="11" class="is-table-empty">${isEsc(emptyMsg)}</td></tr>`;
        return;
    }

    tbody.innerHTML = filtered.map((r) => {
        const isErr = !r.valid;
        const fe    = r.field_errors || {};
        const rowClass = isErr ? 'is-row-error' : 'is-row-valid';

        const statusDisplay = r.status === 'GD' ? 'G+D' : (r.status || r.status_raw || '-');
        const statusBadgeClass = r.status === 'G'
            ? 'is-status-g'
            : (r.status === 'D' ? 'is-status-d' : (r.status === 'GD' ? 'is-status-gd' : ''));

        const resHtml = isErr
            ? `<div class="is-res-err">
                 <span class="is-res-badge is-err"><i class="fa-solid fa-circle-xmark"></i> ไม่ผ่าน</span>
                 <ul class="is-err-list">
                   ${(r.errors || []).map((e) => `<li>${isEsc(e)}</li>`).join('')}
                 </ul>
               </div>`
            : `<div class="is-res-ok">
                 <span class="is-res-badge is-ok"><i class="fa-solid fa-circle-check"></i> ผ่าน (Ready)</span>
               </div>`;

        return `<tr class="${rowClass}">
            <td class="is-col-row is-mono">#${Number(r.excel_row || 0)}</td>
            <td class="is-mono ${fe.code ? 'is-cell-err' : ''}">${isEsc(r.code || '-')}</td>
            <td class="is-shelf-cell ${fe.shelf ? 'is-cell-err' : ''}">${isEsc(r.shelf || '-')}</td>
            <td class="${fe.product ? 'is-cell-err' : ''}">${isEsc(r.product || '-')}</td>
            <td class="${fe.side ? 'is-cell-err' : ''}">${isEsc(r.side || '-')}</td>
            <td class="${fe.board_type ? 'is-cell-err' : ''}">${isEsc(r.board_type || '-')}</td>
            <td class="${fe.status ? 'is-cell-err' : ''}">
              ${statusBadgeClass ? `<span class="is-mini-pill ${statusBadgeClass}">${isEsc(statusDisplay)}</span>` : isEsc(statusDisplay)}
            </td>
            <td class="${fe.pcs_status ? 'is-cell-err' : ''}">${isEsc(r.pcs_status || '-')}</td>
            <td class="${fe.defects ? 'is-cell-err' : ''}">${isEsc(r.defects || r.defects_raw || '-')}</td>
            <td class="is-mag-cell ${fe.mag_slot ? 'is-cell-err' : ''}">${isEsc(r.mag_slot || '-')}</td>
            <td class="is-col-result">${resHtml}</td>
        </tr>`;
    }).join('');
}

/**
 * Download the generated .xlsx Error Report from base64 payload in Step 3.
 */
function isDownloadErrorReportXlsx() {
    const b64 = isLastPreviewData && isLastPreviewData.error_report_xlsx_base64;
    if (!b64) return;

    try {
        const binary = atob(b64);
        const bytes  = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        const blob = new Blob([bytes], {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        });
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        const sheetName = (isLastPreviewData?.summary?.sheet || 'Sheet').replace(/[^A-Za-z0-9_-]/g, '_');
        a.href     = url;
        a.download = `AOI_Import_Error_Report_${sheetName}.xlsx`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    } catch (e) {
        console.error('Failed to download error report:', e);
    }
}
window.isDownloadErrorReportXlsx = isDownloadErrorReportXlsx;

/**
 * Execute All-or-Nothing Commit against POST api/import_commit.php.
 */
function isCommitImport() {
    if (!isSelectedFile || !isLastPreviewData || !isLastPreviewData.success) {
        return;
    }

    const commitBtn   = document.getElementById('is-commit-btn');
    const commitLabel = document.getElementById('is-commit-btn-label');

    if (commitBtn) commitBtn.disabled = true;
    if (commitLabel) commitLabel.textContent = 'กำลังบันทึกข้อมูลเข้าฐานข้อมูล... / Importing...';
    isSetHeaderStatus('กำลังบันทึกข้อมูล... / Saving...', 'busy');

    const { zone, sheet } = isGetActiveAppZoneSheet();
    const formData = new FormData();
    formData.append('file', isSelectedFile);
    formData.append('csrf', window.csrfToken || '');
    formData.append('zone', zone || '');
    formData.append('sheet', sheet || '');

    fetch('api/import_commit.php', {
        method: 'POST',
        body: formData,
    })
        .then(async (res) => {
            const data = await res.json();
            return { status: res.status, data };
        })
        .then(({ status, data }) => {
            if (data.success) {
                if (data.next_code) {
                    window.currentNextCode = data.next_code;
                }
                const msg = data.message || `นำเข้าสำเร็จ ${data.imported_count} รายการ (${data.first_code} – ${data.last_code})`;
                if (typeof showFlashToast === 'function') {
                    showFlashToast(msg, 'success');
                }

                closeImportStation();

                // Refresh live page data without full reload when in the same zone
                const targetZone  = data.zone || zone;
                const targetSheet = data.sheet || sheet;
                if (typeof state !== 'undefined' && typeof loadSheetData === 'function' && (state.zone === targetZone || state.zone === 'ALL')) {
                    const sheetToLoad = (state.sheet === 'ALL' || state.sheet === targetSheet) ? state.sheet : targetSheet;
                    loadSheetData(sheetToLoad);
                } else {
                    window.location.href = `index.php?zone=${encodeURIComponent(targetZone)}&sheet=${encodeURIComponent(targetSheet)}`;
                }
                return;
            }

            // If 503 Lock Busy or 422 Validation/Expired conflict during commit, update Step 3 view
            isLastPreviewData = {
                ...isLastPreviewData,
                ...data,
                summary: (data.summary && Object.keys(data.summary).length > 0)
                    ? data.summary
                    : isLastPreviewData.summary,
                rows: (Array.isArray(data.rows) && data.rows.length > 0)
                    ? data.rows
                    : isLastPreviewData.rows,
            };
            isRenderPreviewStep(isLastPreviewData);

            if (status === 503 && commitBtn && commitLabel) {
                commitBtn.disabled = false;
                commitLabel.textContent = 'ลองกดนำเข้าอีกครั้ง / Retry Import';
            }
        })
        .catch(() => {
            if (commitBtn) commitBtn.disabled = false;
            if (commitLabel) commitLabel.textContent = 'ลองกดนำเข้าอีกครั้ง / Retry Import';
            isSetHeaderStatus('เชื่อมต่อไม่สำเร็จ / Network Error', 'error');
            if (typeof showFlashToast === 'function') {
                showFlashToast(
                    (typeof MSG !== 'undefined' && MSG.networkError)
                        ? MSG.networkError
                        : 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบเครือข่ายแล้วลองอีกครั้ง',
                    'error'
                );
            }
        });
}
window.isCommitImport = isCommitImport;

/**
 * Initialize Drag-and-Drop, Backdrop Click, Escape & Focus Trap listeners.
 */
document.addEventListener('DOMContentLoaded', () => {
    const overlay  = document.getElementById('import-station');
    const dropzone = document.getElementById('is-dropzone');

    if (overlay) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                closeImportStation();
            }
        });
    }

    if (dropzone) {
        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', (e) => {
            isHandleFileSelect(e);
        });
    }

    document.addEventListener('keydown', (e) => {
        if (!overlay || overlay.classList.contains('hidden')) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            closeImportStation();
            return;
        }

        // Focus trap inside modal window
        if (e.key === 'Tab') {
            const win = document.getElementById('is-window');
            if (!win) return;
            const focusables = Array.from(
                win.querySelectorAll('button:not([disabled]), select:not([disabled]), input:not([disabled]):not(.hidden), [tabindex="0"]')
            ).filter((el) => el.offsetParent !== null);

            if (focusables.length === 0) return;
            const first = focusables[0];
            const last  = focusables[focusables.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
});
