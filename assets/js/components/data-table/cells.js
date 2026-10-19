/**
 * Data table: cell renderers.
 */

const cellText = (v) => `<span>${h(v)}</span>`;

const cellShelf = (v) =>
    `<span class="inline-block px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200 font-mono font-medium">${h(v)}</span>`;

function cellStatus(v) {
    const map = {
        G:  'bg-emerald-100 border-emerald-300 text-emerald-800 badge-glow-good',
        D:  'bg-red-100 border-red-300 text-red-700 badge-glow-defect',
        GD: 'bg-amber-100 border-amber-300 text-amber-800',
    };
    const cls = map[v];
    if (!cls) return '<span>-</span>';
    const label = v === 'GD' ? 'G+D' : v;
    return `<span class="inline-flex items-center justify-center min-w-[1.5rem] px-1.5 py-0.5 rounded-md border font-semibold ${cls}">${h(label)}</span>`;
}

function cellPcs(v) {
    if (v === null || v === undefined || v === '') return '<span>-</span>';
    if (String(v).toUpperCase() === 'OK') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 border border-emerald-300 font-medium">OK</span>';
    }
    return cellText(v);
}
