/**
 * Board modal: AJAX save (api/save.php).
 */

async function handleBoardFormSubmit(e) {
    e.preventDefault();
    clearModalErrors();

    const form = e.target;
    const submitBtn = document.getElementById('modal-submit-btn');
    const origHtml = submitBtn ? submitBtn.innerHTML : '';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
    }

    const formData = new FormData(form);
    const targetSheet = activeModalSheet || (state.sheet === 'ALL' ? 'B200_Gen' : state.sheet);
    formData.set('sheet', targetSheet);

    try {
        const res = await fetch('api/save.php', {
            method: 'POST',
            body: formData
        });

        const result = await res.json();

        if (result.success) {
            document.getElementById('gm-window')?.classList.add('gm-ok');
            closeModal();
            showFlash('ok', result.message);
            await Promise.all([
                loadTable(),
                refreshSheetData(state.sheet)
            ]);
        } else {
            showModalErrors(result.errors || ['บันทึกไม่สำเร็จ']);
        }
    } catch (err) {
        console.error(err);
        showModalErrors(['เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์']);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origHtml;
        }
    }
}
window.handleBoardFormSubmit = handleBoardFormSubmit;
