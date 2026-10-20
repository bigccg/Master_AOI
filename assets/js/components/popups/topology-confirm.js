/**
 * Confirm popup for disabling / deleting a zone or sheet (components/popups/topology-confirm.php), same flow as
 * delete-confirm.js: openTopologyConfirm(...) resolves true (Confirm) or false (Cancel, Esc, backdrop).
 * All text goes in with textContent; the key is shown in a gm-shelf-pill.
 */

const topologyConfirm = document.getElementById('topology-confirm');
let pendingTopologyResolver = null;
let topologyConfirmOpener   = null;

/**
 * @param {{title: string, icon: string, mode: string, seg: string, before: string, key: string, after: string, en: string, ok: string}} c
 */
function openTopologyConfirm(c) {
    if (!topologyConfirm) {
        return Promise.resolve(confirm(`${c.before}${c.key}${c.after}\n${c.en}`));
    }
    if (pendingTopologyResolver) {
        pendingTopologyResolver(false);
        pendingTopologyResolver = null;
    }
    topologyConfirmOpener = document.activeElement;
    document.getElementById('tm-confirm-title').textContent = c.title;
    document.getElementById('tm-confirm-icon').className = `fa-solid ${c.icon}`;
    document.getElementById('tm-confirm-mode').textContent = c.mode;
    document.getElementById('tm-confirm-seg').textContent = c.seg;
    document.getElementById('tm-confirm-ok-label').textContent = c.ok;

    const text = document.getElementById('tm-confirm-text');
    const pill = document.createElement('span');
    pill.className = 'gm-shelf-pill is-danger';
    pill.textContent = c.key;
    const en = document.createElement('span');
    en.className = 'en';
    en.textContent = c.en;
    text.replaceChildren(document.createTextNode(c.before), pill, document.createTextNode(c.after), document.createElement('br'), en);

    gmShow(topologyConfirm);
    // Cancel gets focus: Enter / Space on it never confirms by accident
    setTimeout(() => document.getElementById('tm-confirm-cancel')?.focus({ preventScroll: true }), 80);
    return new Promise((resolve) => { pendingTopologyResolver = resolve; });
}
window.openTopologyConfirm = openTopologyConfirm;

function settleTopologyConfirm(ok) {
    gmHide(topologyConfirm);
    if (pendingTopologyResolver) {
        const resolve = pendingTopologyResolver;
        pendingTopologyResolver = null;
        resolve(ok);
    }
    topologyConfirmOpener?.focus?.({ preventScroll: true });
}

// Listeners: buttons, backdrop, Esc (cancel) and Tab kept inside the popup
function setupTopologyConfirmEvents() {
    if (!topologyConfirm) return;
    document.getElementById('tm-confirm-cancel')?.addEventListener('click', () => settleTopologyConfirm(false));
    document.getElementById('tm-confirm-ok')?.addEventListener('click', () => settleTopologyConfirm(true));
    topologyConfirm.addEventListener('click', (e) => { if (e.target === topologyConfirm) settleTopologyConfirm(false); });
    document.addEventListener('keydown', (e) => {
        if (!gmIsOpen(topologyConfirm)) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();   // the manager underneath must not close too
            settleTopologyConfirm(false);
        } else {
            gmTrapTab(e, topologyConfirm);
        }
    }, true);
}
setupTopologyConfirmEvents();
