/**
 * AOI Program Tracker - Main Entry Script
 */

document.addEventListener('DOMContentLoaded', () => {
  initProgState();
  initUserIdentity();
  initProgKpis();
  initProgCommandBar();
  initProgQuickAdd();
  initProgTableEvents();
  initTableSortingEvents();
  initProgKeyboardShortcuts();

  loadProgramsData();
});

function initProgKeyboardShortcuts() {
  document.addEventListener('keydown', (e) => {
    const active = document.activeElement;
    const isTyping = active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT');

    // / -> Focus Search
    if (e.key === '/' && !isTyping) {
      e.preventDefault();
      const s = document.getElementById('prog-search-input');
      s?.focus();
      s?.select();
      return;
    }

    // N -> Focus Quick Add
    if ((e.key === 'n' || e.key === 'N') && !isTyping) {
      e.preventDefault();
      const q = document.getElementById('quick-add-model');
      q?.focus();
      q?.select();
      return;
    }

    // R -> Refresh Data
    if ((e.key === 'r' || e.key === 'R') && !isTyping && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      loadProgramsData();
      return;
    }

    // X -> Toggle Selection on active/focused row
    if ((e.key === 'x' || e.key === 'X') && !isTyping) {
      const row = active?.closest('tr.prog-row');
      if (row) {
        e.preventDefault();
        const chk = row.querySelector('.prog-row-checkbox');
        if (chk) {
          chk.checked = !chk.checked;
          const id = parseInt(chk.getAttribute('data-id'), 10);
          if (chk.checked) progState.selectedIds.add(id);
          else progState.selectedIds.delete(id);
          row.classList.toggle('is-selected', chk.checked);
          updateProgBulkBar();
        }
      }
      return;
    }

    // 1-4 -> If row is focused and activeElement is tr, advance that stage
    if (['1', '2', '3', '4'].includes(e.key) && !isTyping) {
      const row = active?.closest('tr.prog-row');
      if (row && active.tagName === 'TR') {
        const stageNum = parseInt(e.key, 10);
        const cell = row.querySelector(`.prog-step-cell[data-stage-num="${stageNum}"]`);
        if (cell) {
          e.preventDefault();
          const progId = parseInt(cell.getAttribute('data-prog-id'), 10);
          const currVal = parseInt(cell.getAttribute('data-status-val'), 10) || 0;
          const nextVal = getNextStageStatus(currVal);
          applyProgStageUpdate(progId, stageNum, nextVal);
        }
      }
      return;
    }

    // Escape -> Clear selection, close modals/menus
    if (e.key === 'Escape') {
      if (active && (active.tagName === 'INPUT')) {
        active.blur();
      }
      if (progState.selectedIds.size > 0) {
        progState.selectedIds.clear();
        document.querySelectorAll('.prog-row-checkbox').forEach(c => {
          c.checked = false;
          c.closest('tr')?.classList.remove('is-selected');
        });
        updateProgBulkBar();
      }
    }
  });
}

function initTableSortingEvents() {
  document.querySelectorAll('.prog-th.is-sortable').forEach(th => {
    th.addEventListener('click', () => {
      const col = th.getAttribute('data-sort');
      if (progState.sort === col) {
        progState.dir = (progState.dir === 'asc') ? 'desc' : 'asc';
      } else {
        progState.sort = col;
        progState.dir = 'asc';
      }

      // Update ARIA sort and icons
      document.querySelectorAll('.prog-th.is-sortable').forEach(h => {
        h.setAttribute('aria-sort', 'none');
        const ico = h.querySelector('.prog-th-sort-icon');
        if (ico) ico.className = 'fa-solid fa-sort prog-th-sort-icon';
      });

      th.setAttribute('aria-sort', progState.dir === 'asc' ? 'ascending' : 'descending');
      const curIco = th.querySelector('.prog-th-sort-icon');
      if (curIco) {
        curIco.className = `fa-solid fa-sort-${progState.dir === 'asc' ? 'up' : 'down'} prog-th-sort-icon text-emerald-600 opacity-100`;
      }

      loadProgramsData();
    });
  });
}

function initUserIdentity() {
  const btn = document.getElementById('whoami-btn');
  const dropdown = document.getElementById('whoami-dropdown');
  const nameEl = document.getElementById('whoami-name');
  const avatarEl = document.getElementById('user-avatar-badge');
  const optionsEl = document.getElementById('whoami-options');

  if (!btn || !dropdown) return;

  const defaultUsers = ['Thanapol', 'SONGSREM', 'CHATCHAI', 'AUTTHAPON', 'Nuttaphong', 'WARAPORN', 'METEE', 'ADISAK'];

  function updateUserDisplay(name) {
    if (nameEl) nameEl.textContent = name;
    if (avatarEl) avatarEl.textContent = name.substring(0, 2).toUpperCase();
    progState.currentUser = name;
    setStoredItem('aoi_prog_user', name);
  }

  updateUserDisplay(progState.currentUser || 'Thanapol');

  if (optionsEl) {
    optionsEl.innerHTML = defaultUsers.map(u => `
      <button type="button" class="w-full text-left px-3 py-1.5 text-xs text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 flex items-center justify-between" data-user="${h(u)}">
        <span>${h(u)}</span>
        ${u === progState.currentUser ? '<i class="fa-solid fa-check text-emerald-600"></i>' : ''}
      </button>
    `).join('');

    optionsEl.querySelectorAll('[data-user]').forEach(b => {
      b.addEventListener('click', () => {
        const u = b.getAttribute('data-user');
        updateUserDisplay(u);
        dropdown.classList.add('hidden');
        initUserIdentity(); // re-render checkmarks
      });
    });
  }

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    dropdown.classList.toggle('hidden');
  });

  document.addEventListener('click', (e) => {
    if (!dropdown.contains(e.target) && e.target !== btn) {
      dropdown.classList.add('hidden');
    }
  });
}
