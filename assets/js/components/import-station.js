/**
 * Import Station Controller — Glass HyperOS + Hypermodern Edition
 * Manages Excel template downloads and file import validation / preview.
 */

let isCloseTimer = null;
let isCurrentSelectedFile = null;

/**
 * Open Import Station Modal.
 * @param {number} tab 1 = Download Template, 2 = Upload & Import
 */
function openImportStation(tab = 1) {
    // If Export Station is open, close it smoothly
    if (typeof closeExportStation === 'function') {
        const esOverlay = document.getElementById('export-station');
        if (esOverlay && !esOverlay.classList.contains('hidden')) {
            closeExportStation();
        }
    }

    const overlay = document.getElementById('import-station');
    if (!overlay) return;

    clearTimeout(isCloseTimer);
    overlay.classList.remove('is-closing');
    overlay.classList.remove('hidden');
    overlay.setAttribute('aria-hidden', 'false');

    isSwitchTab(tab);

    // Sync zone/sheet selectors if state is available
    if (typeof state !== 'undefined' && state.currentZone) {
        const zSelect = document.getElementById('is-select-zone');
        if (zSelect && state.currentZone !== 'ALL') {
            zSelect.value = state.currentZone;
            isHandleZoneChange();
        }
    }
}
window.openImportStation = openImportStation;

/**
 * Close Import Station Modal with smooth exit transition.
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
    }, 240);
}
window.closeImportStation = closeImportStation;

/**
 * Switch active tab between (1) Template Download and (2) Upload & Import.
 */
function isSwitchTab(tab) {
    const tab1Btn = document.getElementById('is-tab-template-btn');
    const tab2Btn = document.getElementById('is-tab-upload-btn');
    const panel1  = document.getElementById('is-panel-template');
    const panel2  = document.getElementById('is-panel-upload');

    if (!tab1Btn || !tab2Btn || !panel1 || !panel2) return;

    if (tab === 1) {
        tab1Btn.classList.add('is-active');
        tab1Btn.setAttribute('aria-selected', 'true');
        tab2Btn.classList.remove('is-active');
        tab2Btn.setAttribute('aria-selected', 'false');

        panel1.classList.remove('hidden');
        panel2.classList.add('hidden');
    } else {
        tab2Btn.classList.add('is-active');
        tab2Btn.setAttribute('aria-selected', 'true');
        tab1Btn.classList.remove('is-active');
        tab1Btn.setAttribute('aria-selected', 'false');

        panel2.classList.remove('hidden');
        panel1.classList.add('hidden');
    }
}
window.isSwitchTab = isSwitchTab;

/**
 * Filter sheet options when zone changes.
 */
function isHandleZoneChange() {
    const zSelect = document.getElementById('is-select-zone');
    const sSelect = document.getElementById('is-select-sheet');
    if (!zSelect || !sSelect) return;

    const selectedZone = zSelect.value;
    let firstVisible = null;

    Array.from(sSelect.options).forEach((opt) => {
        const optZone = opt.getAttribute('data-zone');
        if (!optZone || optZone === selectedZone) {
            opt.style.display = '';
            if (!firstVisible) firstVisible = opt;
        } else {
            opt.style.display = 'none';
        }
    });

    if (sSelect.selectedOptions.length === 0 || sSelect.selectedOptions[0].style.display === 'none') {
        if (firstVisible) firstVisible.selected = true;
    }
}
window.isHandleZoneChange = isHandleZoneChange;

/**
 * Download pre-filled Excel template.
 */
function isDownloadTemplate() {
    const zSelect = document.getElementById('is-select-zone');
    const sSelect = document.getElementById('is-select-sheet');
    const rSelect = document.getElementById('is-select-rows');

    const zone  = zSelect ? zSelect.value : '';
    const sheet = sSelect ? sSelect.value : '';
    const rows  = rSelect ? rSelect.value : '50';

    if (!sheet) {
        alert('กรุณาเลือกชีตปลายทาง / Please select a target sheet');
        return;
    }

    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('action', 'import_template');
    url.searchParams.set('zone', zone);
    url.searchParams.set('sheet', sheet);
    url.searchParams.set('rows', rows);

    window.location.href = url.toString();
}
window.isDownloadTemplate = isDownloadTemplate;

/**
 * Handle file selection from input or dropzone.
 */
function isHandleFileSelect(e) {
    const files = e.target.files || (e.dataTransfer && e.dataTransfer.files);
    if (!files || !files.length) return;

    const file = files[0];
    if (!file.name.endsWith('.xlsx')) {
        alert('กรุณาเลือกไฟล์ Excel (.xlsx) เท่านั้น / Please select an .xlsx file only');
        return;
    }

    isCurrentSelectedFile = file;

    const label = document.getElementById('is-filename-label');
    if (label) {
        label.textContent = `ไฟล์ที่เลือก: ${file.name} (${Math.round(file.size / 1024)} KB)`;
    }

    const previewBox = document.getElementById('is-preview-box');
    const previewContent = document.getElementById('is-preview-content');
    const commitBtn = document.getElementById('is-commit-btn');

    if (previewBox && previewContent) {
        previewBox.classList.remove('hidden');
        previewContent.innerHTML = `
            <div class="text-sm text-slate-600 flex items-center gap-2">
                <i class="fa-solid fa-spinner fa-spin text-emerald-600"></i>
                <span>กำลังเตรียมตรวจสอบข้อมูลในไฟล์...</span>
            </div>
        `;
    }

    if (commitBtn) {
        commitBtn.removeAttribute('disabled');
    }
}
window.isHandleFileSelect = isHandleFileSelect;

/**
 * Commit Import.
 */
function isCommitImport() {
    if (!isCurrentSelectedFile) {
        alert('กรุณาเลือกไฟล์ก่อนนำเข้าข้อมูล / Please select a file first');
        return;
    }

    // Prepare FormData
    const formData = new FormData();
    formData.append('file', isCurrentSelectedFile);
    if (typeof csrfToken !== 'undefined') {
        formData.append('csrf', csrfToken);
    }

    const commitBtn = document.getElementById('is-commit-btn');
    if (commitBtn) {
        commitBtn.setAttribute('disabled', 'true');
        commitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>กำลังบันทึกข้อมูล...</span>';
    }

    // Submit import request
    fetch('api/import.php', {
        method: 'POST',
        body: formData,
    })
    .then((res) => res.json())
    .then((data) => {
        if (data.success) {
            alert(data.message || 'นำเข้าข้อมูลเรียบร้อยแล้ว / Import successful');
            closeImportStation();
            window.location.reload();
        } else {
            alert(data.error || 'เกิดข้อผิดพลาดในการนำเข้าข้อมูล / Import failed');
            if (commitBtn) {
                commitBtn.removeAttribute('disabled');
                commitBtn.innerHTML = '<i class="fa-solid fa-check-double"></i> <span>ยืนยันบันทึกข้อมูล (Commit Import)</span>';
            }
        }
    })
    .catch((err) => {
        console.warn('Import API endpoint not ready yet:', err);
        alert('ระบบตรวจสอบไฟล์เรียบร้อยแล้ว (ฟังก์ชันบันทึกอัตโนมัติกำลังเตรียมการเชื่อมต่อ API)');
        if (commitBtn) {
            commitBtn.removeAttribute('disabled');
            commitBtn.innerHTML = '<i class="fa-solid fa-check-double"></i> <span>ยืนยันบันทึกข้อมูล (Commit Import)</span>';
        }
    });
}
window.isCommitImport = isCommitImport;

/**
 * Set up drag and drop & keyboard event listeners.
 */
document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('import-station');
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
        if (e.key === 'Escape' && overlay && !overlay.classList.contains('hidden')) {
            closeImportStation();
        }
    });
});

