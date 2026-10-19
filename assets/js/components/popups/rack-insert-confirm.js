/**
 * Rack Map "Confirm Insert" popup (components/popups/rack-insert-confirm.php).
 */

const rackConfirm = document.getElementById('rack-insert-confirm');
let pendingShelf = '';

function openRackConfirm(shelf) {
    pendingShelf = shelf;
    const s1 = document.getElementById('rack-confirm-shelf');
    const s2 = document.getElementById('rack-confirm-shelf2');
    if (s1) s1.textContent = shelf;
    if (s2) s2.textContent = shelf;
    gmShow(rackConfirm);
}
window.openRackConfirm = openRackConfirm;

function closeRackConfirm() {
    gmHide(rackConfirm);
    pendingShelf = '';
}
window.closeRackConfirm = closeRackConfirm;

function confirmRackInsert() {
    const shelf = pendingShelf;
    closeRackConfirm();
    openAddModal();

    const sel = document.getElementById('modal-shelf');
    if (sel) {
        sel.value = shelf;
        if (sel.value !== shelf) {
            // Option may need to be injected if not in list
            const opt = document.createElement('option');
            opt.value = shelf;
            opt.textContent = shelf;
            opt.selected = true;
            sel.appendChild(opt);
        }
    }
    gmSyncProgress();
    setTimeout(() => document.getElementById('modal-product')?.focus({ preventScroll: true }), 120);
}
window.confirmRackInsert = confirmRackInsert;

// Listeners: backdrop click closes
function setupRackConfirmEvents() {
    rackConfirm?.addEventListener('click', (e) => {
        if (e.target === rackConfirm) closeRackConfirm();
    });
}
