/**
 * AOI Program Tracker - API Client
 */

function buildProgApiUrl(overrides = {}) {
  const url = new URL('api/programs.php', window.location.href);

  if (progState.search) url.searchParams.set('q', progState.search);
  if (progState.machine !== 'ALL') url.searchParams.set('machine', progState.machine);
  if (progState.owner !== 'ALL') url.searchParams.set('owner', progState.owner);
  if (progState.status !== 'ALL') url.searchParams.set('status', progState.status);
  if (progState.stage !== 'ALL') url.searchParams.set('stage', progState.stage);

  url.searchParams.set('sort', progState.sort);
  url.searchParams.set('dir', progState.dir);

  const isAll = progState.pageSize === 'all';
  url.searchParams.set('limit', isAll ? 'all' : String(progState.pageSize));
  if (!isAll) {
    url.searchParams.set('offset', String((progState.page - 1) * progState.pageSize));
  }

  Object.entries(overrides).forEach(([k, v]) => {
    if (v === undefined || v === null) return;
    if (v === '') url.searchParams.delete(k);
    else url.searchParams.set(k, String(v));
  });

  return url.toString();
}

async function loadProgramsData() {
  progState.viewStatus = 'loading';
  renderProgTableSkeleton();

  try {
    const res = await fetch(buildProgApiUrl(), {
      headers: { 'Accept': 'application/json' }
    });

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }

    const json = await res.json();
    if (!json.success) {
      throw new Error(json.error || 'Failed to load programs data.');
    }

    progState.rows = Array.isArray(json.data) ? json.data : [];
    progState.kpi = json.kpi || progState.kpi;
    progState.total = json.total || 0;
    progState.pages = json.pages || 1;
    progState.viewStatus = progState.rows.length > 0 ? 'ready' : 'empty';

    // Populate owners dropdown if given
    if (Array.isArray(json.owners)) {
      populateProgOwners(json.owners);
    }

    renderProgKpis();
    renderProgTable();
    renderProgPagination();
    renderProgActiveChips();
    syncProgUrl();

    document.dispatchEvent(new CustomEvent('aoi:programs-loaded', { detail: { count: progState.rows.length, total: progState.total } }));
  } catch (err) {
    console.error('AOI Programs load error:', err);
    progState.viewStatus = 'error';
    renderProgTableError(err.message);
  }
}

/**
 * Update single stage with optimistic concurrency support
 */
async function apiUpdateStage(id, stage, status, expectedVersion, note = null) {
  const payload = {
    csrf: window.csrfToken,
    id: id,
    stage: stage,
    status: status,
    version: expectedVersion,
    operator: progState.currentUser || 'Thanapol',
    note: note
  };

  const res = await fetch('api/program_stage.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(payload)
  });

  const json = await res.json();
  if (!res.ok) {
    const err = new Error(json.errors ? json.errors.join(' ') : `HTTP ${res.status}`);
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}

/**
 * Revert previous stage change or creation
 */
async function apiUndoAction(programId, auditId) {
  const payload = {
    csrf: window.csrfToken,
    program_id: programId,
    audit_id: auditId,
    operator: progState.currentUser || 'Thanapol'
  };

  const res = await fetch('api/program_undo.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(payload)
  });

  const json = await res.json();
  if (!res.ok) {
    const err = new Error(json.errors ? json.errors.join(' ') : `HTTP ${res.status}`);
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}

/**
 * Save new program (Quick Add or Full Modal)
 */
async function apiSaveProgram(formData) {
  const payload = Object.assign({}, formData, {
    csrf: window.csrfToken,
    operator: progState.currentUser || 'Thanapol'
  });

  const res = await fetch('api/program_save.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(payload)
  });

  const json = await res.json();
  if (!res.ok) {
    const err = new Error(json.errors ? json.errors.join(' ') : `HTTP ${res.status}`);
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}

/**
 * Bulk updates on multiple IDs
 */
async function apiBulkAction(action, ids, payload = {}) {
  const body = {
    csrf: window.csrfToken,
    action: action,
    ids: ids,
    payload: payload,
    operator: progState.currentUser || 'Thanapol'
  };

  const res = await fetch('api/program_bulk.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(body)
  });

  const json = await res.json();
  if (!res.ok) {
    const err = new Error(json.errors ? json.errors.join(' ') : `HTTP ${res.status}`);
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}
