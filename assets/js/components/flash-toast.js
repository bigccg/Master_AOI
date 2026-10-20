/**
 * Flash toast messages (top-right).
 */

function showFlash(type, message) {
    const container = document.getElementById('flash-container') ?? createFlashContainer();
    const styles = {
        ok:   ['border-emerald-300 bg-emerald-50 text-emerald-800', 'circle-check'],
        warn: ['border-amber-300 bg-amber-50 text-amber-800', 'triangle-exclamation'],
        err:  ['border-red-300 bg-red-50 text-red-800', 'circle-exclamation'],
    };
    const [cls, icon] = styles[type] ?? styles.err;
    const div = document.createElement('div');
    div.className = `no-print rounded-md border px-4 py-2.5 flex items-center gap-2 text-xs shadow-md transition-all duration-200 ${cls}`;
    div.setAttribute('role', type === 'err' ? 'alert' : 'status');
    div.innerHTML = `<i class="fa-solid fa-${icon}" aria-hidden="true"></i><span>${h(message)}</span>`;
    container.appendChild(div);
    setTimeout(() => {
        div.style.opacity = '0';
        setTimeout(() => div.remove(), 250);
    }, type === 'warn' ? 6000 : 4000);
}
window.showFlash = showFlash;

function createFlashContainer() {
    const div = document.createElement('div');
    div.id = 'flash-container';
    div.className = 'fixed top-20 right-4 z-50 flex flex-col gap-2 w-80 pointer-events-none [&>*]:pointer-events-auto';
    document.body.appendChild(div);
    return div;
}
