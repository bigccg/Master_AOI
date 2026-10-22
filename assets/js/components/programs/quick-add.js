/**
 * AOI Program Tracker - Quick Add Dock Logic
 */

function initProgQuickAdd() {
  const inputModel = document.getElementById('quick-add-model');
  const selSide = document.getElementById('quick-add-side');
  const selMach = document.getElementById('quick-add-machine');
  const selOwner = document.getElementById('quick-add-owner');
  const btnSubmit = document.getElementById('quick-add-submit-btn');

  const alertDup = document.getElementById('quick-add-duplicate-alert');
  const alertSim = document.getElementById('quick-add-similar-alert');
  const simText = document.getElementById('quick-add-similar-text');

  // Load sticky preferences from localStorage
  if (selSide) selSide.value = getStoredItem('aoi_quick_side', 'F-Side');
  if (selMach) selMach.value = getStoredItem('aoi_quick_mach', 'OMRON (2D)');
  if (selOwner) selOwner.value = getStoredItem('aoi_quick_owner', progState.currentUser || '');

  // Real-time input cleaner & similarity detector
  inputModel?.addEventListener('input', (e) => {
    // Auto-uppercase and strip leading/trailing spaces
    const rawVal = e.target.value.toUpperCase().replace(/\s+/g, '');
    e.target.value = rawVal;

    if (!rawVal) {
      if (alertDup) alertDup.style.display = 'none';
      if (alertSim) alertSim.style.display = 'none';
      return;
    }

    const side = selSide ? selSide.value : 'F-Side';

    // 1. Check exact duplicate in current state
    const exactDup = progState.rows.find(r => r.product_model === rawVal && r.side === side);
    if (exactDup) {
      if (alertDup) alertDup.style.display = 'inline-flex';
      if (alertSim) alertSim.style.display = 'none';
      const gotoLink = document.getElementById('quick-add-goto-dup');
      if (gotoLink) {
        gotoLink.onclick = (ev) => {
          ev.preventDefault();
          const targetRow = document.querySelector(`tr[data-id="${exactDup.id}"]`);
          if (targetRow) {
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetRow.classList.add('flash-update');
            setTimeout(() => targetRow.classList.remove('flash-update'), 1500);
          }
        };
      }
      return;
    } else {
      if (alertDup) alertDup.style.display = 'none';
    }

    // 2. Check similar family model (first 6-10 characters)
    const prefix = rawVal.split('-').slice(0, 2).join('-');
    if (prefix.length >= 4) {
      const similar = progState.rows.find(r => r.product_model !== rawVal && r.product_model.startsWith(prefix));
      if (similar) {
        if (alertSim) alertSim.style.display = 'inline-flex';
        if (simText) simText.textContent = `ตรวจพบโมเดลคล้ายคลึง: ${similar.product_model}`;
        return;
      }
    }
    if (alertSim) alertSim.style.display = 'none';
  });

  // Enter key submission
  inputModel?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      btnSubmit?.click();
    }
  });

  btnSubmit?.addEventListener('click', async () => {
    const model = inputModel?.value.trim().toUpperCase();
    if (!model) {
      inputModel?.focus();
      return;
    }

    const side = selSide?.value || 'F-Side';
    const mach = selMach?.value || 'OMRON (2D)';
    const owner = selOwner?.value || '';

    // Remember sticky fields
    setStoredItem('aoi_quick_side', side);
    setStoredItem('aoi_quick_mach', mach);
    setStoredItem('aoi_quick_owner', owner);

    // Disable button while submitting
    btnSubmit.disabled = true;
    const originalBtnText = btnSubmit.innerHTML;
    btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>บันทึก…';

    try {
      const res = await apiSaveProgram({
        product_model: model,
        side: side,
        machine_type: mach,
        owner: owner,
      });

      if (res.program) {
        // Prepend to state rows
        progState.rows.unshift(res.program);
        progState.total += 1;

        if (res.kpi) {
          progState.kpi = res.kpi;
          renderProgKpis();
        }

        // Prepend directly into DOM
        prependRowToDom(res.program);

        // Show Undo Toast
        showProgToast(
          'เพิ่มโปรแกรมสำเร็จ',
          `${model} (${side}) · ${mach}`,
          async () => {
            try {
              await apiUndoAction(res.program.id, res.audit_id);
              // Remove row from DOM and state
              const rowEl = document.querySelector(`tr[data-id="${res.program.id}"]`);
              rowEl?.remove();
              const idx = progState.rows.findIndex(r => r.id === res.program.id);
              if (idx !== -1) progState.rows.splice(idx, 1);
              progState.total = Math.max(0, progState.total - 1);
              if (res.kpi) {
                // Refresh KPI
                const fresh = await fetch(buildProgApiUrl()).then(r => r.json());
                if (fresh.kpi) {
                  progState.kpi = fresh.kpi;
                  renderProgKpis();
                }
              }
              showProgToast('เลิกทำสำเร็จ', `ยกเลิกการสร้างโมเดล ${model} (${side}) เรียบร้อย`);
            } catch (undoErr) {
              showProgErrorToast('ไม่สามารถเลิกทำได้', undoErr.message);
            }
          }
        );

        // Clear input and refocus for rapid consecutive entries
        inputModel.value = '';
        if (alertDup) alertDup.style.display = 'none';
        if (alertSim) alertSim.style.display = 'none';
      }
    } catch (err) {
      console.error('Save program failed:', err);
      showProgErrorToast('ไม่สามารถเพิ่มโปรแกรมได้', err.message);
    } finally {
      btnSubmit.disabled = false;
      btnSubmit.innerHTML = originalBtnText;
      inputModel.focus();
    }
  });
}
