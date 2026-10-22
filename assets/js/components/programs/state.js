/**
 * AOI Program Tracker - State Management
 */

const progState = {
  search: '',
  machine: 'ALL',
  owner: 'ALL',
  status: 'ALL',
  stage: 'ALL',
  density: 'standard',
  sort: 'updated_at',
  dir: 'desc',
  page: 1,
  pageSize: 50,
  currentUser: 'Thanapol',
  rows: [],
  kpi: {
    total: 0,
    complete: 0,
    pct_complete: 0,
    wait_master: 0,
    queued: 0,
    overdue: 0,
    omron: 0,
    yamaha: 0,
    tri: 0,
    unlinked: 0,
  },
  total: 0,
  pages: 1,
  viewStatus: 'loading', // loading, ready, error, empty
};

// Safe LocalStorage helpers
function getStoredItem(key, fallback) {
  try {
    const v = localStorage.getItem(key);
    return v !== null ? v : fallback;
  } catch (e) {
    return fallback;
  }
}

function setStoredItem(key, value) {
  try {
    localStorage.setItem(key, String(value));
  } catch (e) {
    // ignore quota/private mode errors
  }
}

function removeStoredPrefix(prefix) {
  try {
    const keysToRemove = [];
    for (let i = 0; i < localStorage.length; i++) {
      const k = localStorage.key(i);
      if (k && k.startsWith(prefix)) {
        keysToRemove.push(k);
      }
    }
    keysToRemove.forEach(k => localStorage.removeItem(k));
  } catch (e) {
    // ignore storage errors
  }
}

// Initialise state from URL & localStorage
function initProgState() {
  // Purge legacy Quick Add keys
  removeStoredPrefix('aoi_quick_');

  const url = new URL(window.location.href);

  progState.search = url.searchParams.get('q') || url.searchParams.get('search') || '';
  progState.machine = url.searchParams.get('machine') || 'ALL';
  progState.owner = url.searchParams.get('owner') || 'ALL';
  progState.status = url.searchParams.get('status') || 'ALL';
  progState.stage = url.searchParams.get('stage') || 'ALL';
  progState.sort = url.searchParams.get('sort') || 'updated_at';
  progState.dir = (url.searchParams.get('dir') || 'desc').toLowerCase() === 'asc' ? 'asc' : 'desc';
  progState.page = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10));

  const rawLimit = url.searchParams.get('limit') || '50';
  progState.pageSize = rawLimit.toLowerCase() === 'all' ? 'all' : (parseInt(rawLimit, 10) || 50);

  progState.density = getStoredItem('aoi_prog_density', 'standard');
  progState.currentUser = getStoredItem('aoi_prog_user', 'Thanapol');
}

// Sync state to browser URL and header Export button without reload
function syncProgUrl() {
  const url = new URL(window.location.href);

  url.searchParams.delete('select');
  url.searchParams.delete('search');

  if (progState.search) url.searchParams.set('q', progState.search);
  else url.searchParams.delete('q');

  if (progState.machine !== 'ALL') url.searchParams.set('machine', progState.machine);
  else url.searchParams.delete('machine');

  if (progState.owner !== 'ALL') url.searchParams.set('owner', progState.owner);
  else url.searchParams.delete('owner');

  if (progState.status !== 'ALL') url.searchParams.set('status', progState.status);
  else url.searchParams.delete('status');

  if (progState.stage !== 'ALL') url.searchParams.set('stage', progState.stage);
  else url.searchParams.delete('stage');

  if (progState.sort !== 'updated_at') url.searchParams.set('sort', progState.sort);
  else url.searchParams.delete('sort');

  if (progState.dir !== 'desc') url.searchParams.set('dir', progState.dir);
  else url.searchParams.delete('dir');

  if (progState.page > 1) url.searchParams.set('page', String(progState.page));
  else url.searchParams.delete('page');

  if (progState.pageSize !== 50) url.searchParams.set('limit', String(progState.pageSize));
  else url.searchParams.delete('limit');

  window.history.replaceState({}, '', url.toString());

  // Sync header Export CSV link with active filters & sort
  const exportBtn = document.getElementById('prog-export-btn');
  if (exportBtn) {
    const expUrl = new URL(url.toString());
    expUrl.searchParams.set('export', '1');
    exportBtn.href = expUrl.toString();
  }
}
