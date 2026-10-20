/**
 * Defect panel: checkbox selection <-> hidden defects input (components/board-modal/defect-panel.php).
 */

const defectPanel = document.getElementById('defect-panel');
const gmStage = document.getElementById('gm-stage');

function syncDefectCheckboxesFromHidden() {
    const defectsVal = document.getElementById('defects-input')?.value || '';
    const codes = defectsVal ? defectsVal.split(',').filter(Boolean) : [];
    document.querySelectorAll('#defect-panel input[name="defect[]"]').forEach(cb => {
        cb.checked = codes.includes(cb.value);
    });
    const otherCode = codes.find(c => c === 'OTHER' || c.startsWith('OTHER:'));
    const otherCb = document.getElementById('defect-other-checkbox');
    const otherTxt = document.getElementById('defect-other-text');
    const otherWrap = otherTxt?.closest('.gm-other-note');
    if (otherCb && otherTxt) {
        if (otherCode) {
            otherCb.checked = true;
            otherTxt.value = otherCode.startsWith('OTHER:') ? otherCode.slice(6) : '';
            otherTxt.classList.remove('hidden');
            otherWrap?.classList.remove('hidden');
            otherTxt.disabled = false;
        } else {
            otherCb.checked = false;
            otherTxt.value = '';
            otherTxt.classList.add('hidden');
            otherWrap?.classList.add('hidden');
            otherTxt.disabled = true;
        }
    }
    gmSyncDefectCount();
}

function gmSyncDefectCount() {
    const n = document.querySelectorAll('#defect-panel input[name="defect[]"]:checked').length
        + (document.getElementById('defect-other-checkbox')?.checked ? 1 : 0);
    const el = document.getElementById('gm-defect-count');
    if (el) el.textContent = n;
}

function toggleOtherDefectInput() {
    const checkbox = document.getElementById('defect-other-checkbox');
    const input = document.getElementById('defect-other-text');
    const wrap = input?.closest('.gm-other-note');
    if (!checkbox || !input) return;
    if (checkbox.checked) {
        wrap?.classList.remove('hidden');
        input.classList.remove('hidden');
        input.disabled = false;
        input.focus();
    } else {
        input.classList.add('hidden');
        wrap?.classList.add('hidden');
        input.disabled = true;
        input.value = '';
    }
    updateDefectsInput();
}
window.toggleOtherDefectInput = toggleOtherDefectInput;

function updateDefectsInput() {
    const checkboxes = document.querySelectorAll('#defect-panel input[name="defect[]"]:checked');
    const otherChecked = document.getElementById('defect-other-checkbox')?.checked;
    const rawOther = document.getElementById('defect-other-text')?.value || '';
    const otherText = rawOther.trim().replace(/\s*\r?\n\s*/g, ' ');
    const codes = Array.from(checkboxes).map(cb => cb.value);
    if (otherChecked) {
        codes.push(otherText ? 'OTHER:' + otherText : 'OTHER');
    }
    const hidden = document.getElementById('defects-input');
    if (hidden) hidden.value = codes.join(',');
    gmSyncDefectCount();
    gmSyncDefectChip();
}
window.updateDefectsInput = updateDefectsInput;

function clearDefects() {
    document.querySelectorAll('#defect-panel input[name="defect[]"]').forEach(cb => { cb.checked = false; });
    const otherCb = document.getElementById('defect-other-checkbox');
    const otherTxt = document.getElementById('defect-other-text');
    const otherWrap = otherTxt?.closest('.gm-other-note');
    if (otherCb) otherCb.checked = false;
    if (otherTxt) {
        otherTxt.value = '';
        otherTxt.classList.add('hidden');
        otherWrap?.classList.add('hidden');
        otherTxt.disabled = true;
    }
    updateDefectsInput();
}
window.clearDefects = clearDefects;

function switchToStatusG() {
    const gRadio = document.querySelector('#board-form input[name="board_status"][value="G"]');
    if (gRadio) {
        gRadio.checked = true;
        gRadio.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
        closeDefectPanel(false);
    }
}
window.switchToStatusG = switchToStatusG;

function confirmDefects() {
    updateDefectsInput();
}
window.confirmDefects = confirmDefects;

// Listeners: Board Classification D / G+D opens the defect panel, G closes it
function setupDefectStatusEvents() {
    // Defect status selection in modal (D / GD pops out water droplet to right; G morphs back into droplet and flows into main modal)
    document.addEventListener('change', (e) => {
        if (e.target.name === 'board_status') {
            if (e.target.value === 'D' || e.target.value === 'GD') {
                syncDefectCheckboxesFromHidden();
                openDefectPanel(e.target.value);
            } else if (e.target.value === 'G') {
                clearDefects();
                closeDefectPanel(false);
            }
        }
    });
}
