/**
 * Delete confirm popup + AJAX delete (components/popups/delete-confirm.php, api/delete.php).
 */

const deleteConfirm = document.getElementById('delete-board-confirm');
let pendingDeleteResolver = null;

function openDeleteConfirm(code) {
    if (!deleteConfirm) {
        return Promise.resolve(confirm(MSG.confirmDel(code)));
    }
    if (pendingDeleteResolver) {
        pendingDeleteResolver(false);
        pendingDeleteResolver = null;
    }
    const c1 = document.getElementById('delete-confirm-code');
    const c2 = document.getElementById('delete-confirm-code2');
    const displayCode = code || ('#' + (document.getElementById('modal-id')?.value || ''));
    if (c1) c1.textContent = displayCode;
    if (c2) c2.textContent = displayCode;
    gmShow(deleteConfirm);
    setTimeout(() => document.getElementById('delete-confirm-ok-btn')?.focus({ preventScroll: true }), 80);

    return new Promise((resolve) => {
        pendingDeleteResolver = resolve;
    });
}
window.openDeleteConfirm = openDeleteConfirm;

function closeDeleteConfirm() {
    gmHide(deleteConfirm);
    if (pendingDeleteResolver) {
        const resolve = pendingDeleteResolver;
        pendingDeleteResolver = null;
        resolve(false);
    }
}
window.closeDeleteConfirm = closeDeleteConfirm;

function confirmDeleteBoard() {
    gmHide(deleteConfirm);
    if (pendingDeleteResolver) {
        const resolve = pendingDeleteResolver;
        pendingDeleteResolver = null;
        resolve(true);
    }
}
window.confirmDeleteBoard = confirmDeleteBoard;

async function deleteBoard(id, code) {
    const confirmed = await openDeleteConfirm(code);
    if (!confirmed) return false;

    try {
        const res = await fetch('api/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, csrf: getCsrf() }),
        });

        const result = await res.json();

        if (result.success) {
            showFlash('ok', result.message);
            await Promise.all([
                reloadTable(),
                refreshSheetData(state.sheet)
            ]);
            return true;
        } else {
            showFlash('err', result.message || MSG.deleteFailed);
            return false;
        }
    } catch (e) {
        console.error(e);
        showFlash('err', MSG.networkError);
        return false;
    }
}
window.deleteBoard = deleteBoard;

// Listeners: backdrop click closes
function setupDeleteConfirmEvents() {
    deleteConfirm?.addEventListener('click', (e) => {
        if (e.target === deleteConfirm) closeDeleteConfirm();
    });
}
