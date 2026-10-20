/**
 * Board modal (Add / Edit): open, close, mode, progress, free-shelf dropdown (components/board-modal/).
 */

const modal = document.getElementById('add-entry-modal');

let activeModalZone  = null;
let activeModalSheet = null;

// INSERT / EDIT mode pill + breadcrumb + accent colour
function gmSetMode(mode, code) {
    const win = document.getElementById('gm-window');
    win?.classList.remove('gm-ok');
    win?.classList.toggle('is-edit', mode === 'edit');
    const set = (id, t) => { const n = document.getElementById(id); if (n) n.textContent = t; };
    set('gm-mode-text', mode === 'edit' ? 'EDIT' : 'INSERT');
    set('gm-path-tail', mode === 'edit' ? '/edit/' + (code || '') : '/new');
    const z = activeModalZone || state.zone;
    const s = activeModalSheet || state.sheet;
    set('gm-path-sheet', `${z}/${s}`);
    set('gm-st-sheet', `${z} · ${s}`);
}

// required-fields progress in the statusline (shelf, product, side, board type, board classification unless locked)
function gmSyncProgress() {
    const ids = ['modal-shelf', 'modal-product', 'modal-side', 'modal-board-type'];
    const statusChecked = !!document.querySelector('#board-form input[name="board_status"]:checked');
    const statusLocked = !!document.querySelector('#board-form input[name="board_status"]:disabled');
    const total = ids.length + (statusLocked ? 0 : 1);
    const done = ids.filter(id => (document.getElementById(id)?.value || '').trim() !== '').length + (statusChecked ? 1 : 0);
    const bar = document.getElementById('gm-prog-bar');
    const txt = document.getElementById('gm-prog-text');
    if (bar) bar.style.width = (done / total * 100) + '%';
    if (txt) txt.textContent = done + '/' + total;
}

// Other = Bare / Bad Mark (window.OTHER_NO_CLASS) -> Board Classification is cleared and locked, defects dropped with it
function syncClassificationLock() {
    const locked = (window.OTHER_NO_CLASS || []).includes(document.getElementById('modal-pcs')?.value);
    document.querySelectorAll('#board-form input[name="board_status"]').forEach(r => {
        r.disabled = locked;
        if (locked) r.checked = false;
    });
    if (locked) {
        clearDefects();
        closeDefectPanel(false);
    }
    return locked;
}

function gmSyncDefectChip() {
    // Chip removed per UX request
}
window.gmSyncDefectChip = gmSyncDefectChip;

// cursor spotlight + ripple + live progress (wired once)
(function gmInit() {
    document.querySelectorAll('.gm-window').forEach(win => {
        win.addEventListener('pointermove', e => {
            const r = win.getBoundingClientRect();
            win.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            win.style.setProperty('--my', (e.clientY - r.top) + 'px');
        });
    });
    document.addEventListener('pointerdown', e => {
        const btn = e.target.closest?.('.gm-btn');
        if (!btn || btn.disabled) return;
        const r = btn.getBoundingClientRect();
        const d = Math.max(r.width, r.height) * 2;
        const rip = document.createElement('span');
        rip.className = 'gm-ripple';
        rip.style.cssText = `width:${d}px;height:${d}px;left:${e.clientX - r.left - d / 2}px;top:${e.clientY - r.top - d / 2}px`;
        btn.appendChild(rip);
        setTimeout(() => rip.remove(), 650);
    });
    const form = document.getElementById('board-form');
    form?.addEventListener('input', gmSyncProgress);
    form?.addEventListener('change', (e) => {
        if (e.target.id === 'modal-pcs') syncClassificationLock();
        gmSyncProgress();
        if (e.target.name === 'board_status') gmSyncDefectChip();
    });
})();

function updateModalFreeShelves(freeByMag, freeShelves, capacity, sp, isMax, selectedShelf = null) {
    const sel = document.getElementById('modal-shelf');
    const info = document.getElementById('modal-shelf-info');
    if (!sel) return;

    if (isMax && !selectedShelf) {
        sel.disabled = true;
        sel.className = 'gm-input mono is-max';
        sel.innerHTML = '<option value="">Magazine is Max</option>';
        if (info) info.innerHTML = '<span class="font-semibold text-red-600">Magazine is Max — เพิ่ม Magazine ได้ที่ Rack Map (กด Confirm)</span>';
        return;
    }

    sel.disabled = false;
    sel.className = 'gm-input mono';

    let html = '<option value="" disabled ' + (selectedShelf ? '' : 'selected') + '>เลือก Shelf...</option>';

    // Grouping
    for (const [mg, list] of Object.entries(freeByMag)) {
        html += `<optgroup label="Magazine ${ordinal(Number(mg))} = ${(Number(mg) - 1) * 50 + 1}-${Number(mg) * 50}">`;
        let includedSelected = false;
        list.forEach(item => {
            const isSel = selectedShelf === item.shelf;
            if (isSel) includedSelected = true;
            html += `<option value="${h(item.shelf)}" ${isSel ? 'selected' : ''}>${h(item.shelf)}</option>`;
        });
        // If editing and selected shelf belongs to this magazine but was occupied
        if (selectedShelf && !includedSelected) {
            const match = selectedShelf.match(/\d+$/);
            if (match) {
                const sNo = parseInt(match[0], 10);
                if (Math.floor((sNo - 1) / 50) + 1 === Number(mg)) {
                    html += `<option value="${h(selectedShelf)}" selected>${h(selectedShelf)} (Current)</option>`;
                }
            }
        }
        html += `</optgroup>`;
    }

    sel.innerHTML = html;
    if (info) {
        info.innerHTML = `แสดงเฉพาะ Shelf ว่าง (${freeShelves.length}/${capacity})`;
    }
}

function clearModalErrors() {
    const errBox = document.getElementById('modal-error-box');
    if (errBox) {
        errBox.classList.add('hidden');
        errBox.innerHTML = '';
    }
}

function showModalErrors(errors) {
    const errBox = document.getElementById('modal-error-box');
    if (!errBox) return;
    errBox.innerHTML = errors.map(e => `<div><i class="fa-solid fa-circle-exclamation mr-1"></i>${h(e)}</div>`).join('');
    errBox.classList.remove('hidden');
}

function setModalDeleteVisible(visible) {
    const delBtn = document.getElementById('modal-delete-btn');
    if (!delBtn) return;
    delBtn.hidden = !visible;
    delBtn.classList.toggle('hidden', !visible);
}

function openAddModal() {
    if (!zoneHasSheetsJs(state.zone)) {
        showFlash('err', EMPTY_ZONE_INSERT_MSG);
        return;
    }
    if (state.sheet === 'ALL') {
        showFlash('err', ALL_SIZE_INSERT_MSG);
        return;
    }
    clearModalErrors();
    setModalDeleteVisible(false);

    const form = document.getElementById('board-form');
    if (form) form.reset();
    document.querySelectorAll('#board-form input[name="board_status"]').forEach(r => { r.checked = false; });

    activeModalZone  = state.zone;
    activeModalSheet = state.sheet;

    const modalZone = document.getElementById('modal-zone');
    if (modalZone) modalZone.value = activeModalZone;

    document.getElementById('modal-id').value = '0';
    document.getElementById('modal-title-text').textContent = 'Add New AOI Board Record / บันทึกข้อมูลบอร์ดใหม่';
    document.getElementById('modal-title-icon').className = 'fa-solid fa-plus';
    document.getElementById('modal-submit-label').textContent = `บันทึกลง ${activeModalSheet} / Save to ${activeModalSheet}`;
    gmSetMode('insert');

    const codeHidden = document.getElementById('modal-code');
    if (codeHidden) {
        codeHidden.value = window.currentNextCode || '';
    }

    document.getElementById('defects-input').value = '';
    syncDefectCheckboxesFromHidden();
    closeDefectPanel(true);

    if (currentSheetData) {
        updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, currentSheetData.rack.is_max);
    }

    syncClassificationLock();
    gmSyncProgress();
    gmSyncDefectChip();
    gmShow(modal);
    setTimeout(() => document.getElementById('modal-product')?.focus({ preventScroll: true }), 120);
}
window.openAddModal = openAddModal;

async function openEditModal(id) {
    clearModalErrors();
    setModalDeleteVisible(false);

    try {
        const res = await fetch(`api/get.php?id=${id}&zone=${encodeURIComponent(state.zone)}&sheet=${encodeURIComponent(state.sheet)}`);
        const json = await res.json();
        if (!json.success || !json.data) throw new Error(json.message || 'Cannot load record');

        const d = json.data;
        activeModalZone  = d.zone || state.zone;
        activeModalSheet = d.sheet || (state.sheet === 'ALL' ? 'B200_Gen' : state.sheet);

        const modalZone = document.getElementById('modal-zone');
        if (modalZone) modalZone.value = activeModalZone;

        document.getElementById('modal-id').value = d.id;
        document.getElementById('modal-title-text').textContent = 'Edit AOI Board Record / แก้ไขข้อมูลบอร์ด';
        document.getElementById('modal-title-icon').className = 'fa-solid fa-pen';
        document.getElementById('modal-submit-label').textContent = 'บันทึกการแก้ไข / Update Record';
        gmSetMode('edit', d.code);
        setModalDeleteVisible(true);

        const codeHidden = document.getElementById('modal-code');
        if (codeHidden) {
            codeHidden.value = d.code || '';
        }

        if ((state.sheet === 'ALL' || state.zone !== activeModalZone) && d.sheet) {
            try {
                const shRes = await fetch(`api/sheet_data.php?zone=${encodeURIComponent(activeModalZone)}&sheet=${encodeURIComponent(d.sheet)}`);
                const shJson = await shRes.json();
                if (shJson?.success && shJson.data) {
                    updateModalFreeShelves(shJson.data.free_by_mag, shJson.data.free_shelves, shJson.data.rack.capacity, shJson.data.shelf_prefix, false, d.shelf);
                } else if (currentSheetData) {
                    updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, false, d.shelf);
                }
            } catch (_) {
                if (currentSheetData) {
                    updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, false, d.shelf);
                }
            }
        } else if (currentSheetData) {
            updateModalFreeShelves(currentSheetData.free_by_mag, currentSheetData.free_shelves, currentSheetData.rack.capacity, currentSheetData.shelf_prefix, false, d.shelf);
        }

        const prod = document.getElementById('modal-product');
        if (prod) prod.value = d.product;

        const side = document.getElementById('modal-side');
        if (side) side.value = d.side;

        const bt = document.getElementById('modal-board-type');
        if (bt) bt.value = d.board_type;

        document.querySelectorAll('#board-form input[name="board_status"]').forEach(r => {
            r.checked = (r.value === d.status);
        });

        const pcs = document.getElementById('modal-pcs');
        if (pcs) pcs.value = d.pcs_status || '';

        document.getElementById('defects-input').value = d.defects || '';
        syncDefectCheckboxesFromHidden();
        closeDefectPanel(true);
        const classLocked = syncClassificationLock();

        gmSyncProgress();
        gmSyncDefectChip();
        gmShow(modal);
        if (!classLocked && (d.status === 'D' || d.status === 'GD')) {
            setTimeout(() => openDefectPanel(d.status), 90);
        }
        setTimeout(() => prod?.focus({ preventScroll: true }), 120);
    } catch (err) {
        showFlash('err', 'ไม่สามารถโหลดข้อมูลบอร์ดได้ กรุณาลองอีกครั้ง: ' + err.message);
    }
}
window.openEditModal = openEditModal;

function closeModal() {
    gmHide(modal);
}
window.closeModal = closeModal;

// Listeners: Delete button inside Edit mode, backdrop click closes
function setupBoardModalEvents() {
    document.getElementById('modal-delete-btn')?.addEventListener('click', async () => {
        const id = document.getElementById('modal-id')?.value;
        const code = document.getElementById('modal-code')?.value || '';
        if (!id || id === '0') return;
        const deleted = await deleteBoard(id, code);
        if (deleted && gmIsOpen(modal)) {
            closeModal();
        }
    });

    modal?.addEventListener('click', (e) => {
        if (e.target === modal || e.target.id === 'gm-stage') closeModal();
    });
}
