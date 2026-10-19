/**
 * Glass search bar helpers (calm version, no animation logic):
 *  - clear button, Esc to clear, "/" or Ctrl/⌘+K to focus
 *  - result count chip
 */
(() => {
    'use strict';

    const input = document.getElementById('barcode-search-input');
    const glass = input?.closest('.aoi-search-glass');
    if (!input || !glass) return;

    const clearBtn  = glass.querySelector('.aoi-search-clear');
    const countChip = glass.querySelector('.aoi-search-count');
    const countNum  = countChip?.querySelector('b');

    const syncValue = () => {
        const has = input.value !== '';
        glass.classList.toggle('has-value', has);
        if (clearBtn) clearBtn.tabIndex = has ? 0 : -1;
        if (!has && countChip) countChip.hidden = true;
    };

    function clearSearch() {
        if (input.value === '') return;
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));   // filters.js debounce reloads the table
        input.focus();
    }

    input.addEventListener('input', syncValue);
    clearBtn?.addEventListener('click', clearSearch);
    input.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (input.value) { e.preventDefault(); clearSearch(); } else input.blur();
    });

    document.addEventListener('keydown', (e) => {
        const t = e.target;
        const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
        const slash = e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing;
        const kbar  = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k';
        if (!slash && !kbar) return;
        e.preventDefault();
        input.focus();
        input.select();
    });

    document.addEventListener('aoi:loaded', (e) => {
        if (!countChip || !countNum || input.value.trim() === '') { if (countChip) countChip.hidden = true; return; }
        const to = Number(e.detail?.total) || 0;
        countNum.textContent = to.toLocaleString();
        countChip.classList.toggle('is-zero', to === 0);
        countChip.hidden = false;
    });

    syncValue();
})();
