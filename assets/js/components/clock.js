/**
 * Live clock in the header (components/header.php).
 */

function updateClock() {
    const n = new Date();
    const p = v => String(v).padStart(2, '0');
    const hm = document.getElementById('clock-hm');
    const sc = document.getElementById('clock-s');
    const dt = document.getElementById('clock-date');
    if (!hm) return;
    hm.textContent = `${p(n.getHours())}:${p(n.getMinutes())}`;
    sc.textContent = `:${p(n.getSeconds())}`;
    let d = `${n.getFullYear()}-${p(n.getMonth()+1)}-${p(n.getDate())}`;
    try {
        d = n.toLocaleDateString('th-TH-u-ca-gregory', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    } catch (e) {}
    if (dt && dt.textContent !== d) dt.textContent = d;
}
