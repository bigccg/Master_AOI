/**
 * AOI Program Tracker - API Client (Read, Stage Update, Meta Update & Undo only)
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

// silent (live sync): no skeleton; unchanged data leaves the page alone, changed rows are patched in place
async function loadProgramsData({ silent = false } = {}) {
  if (!silent) {
    progState.viewStatus = 'loading';
    renderProgTableSkeleton();
  }

  try {
    const res = await fetch(buildProgApiUrl(), {
      headers: { 'Accept': 'application/json' }
    });

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`);
    }

    const json = await res.json();
    if (!json.success) {
      throw new Error(json.error || 'ไม่สามารถโหลดข้อมูลโปรแกรมได้');
    }

    const newRows = Array.isArray(json.data) ? json.data : [];
    if (silent && progState.viewStatus === 'ready') {
      const sameRows = JSON.stringify(newRows) === JSON.stringify(progState.rows);
      const sameKpi  = JSON.stringify(json.kpi || progState.kpi) === JSON.stringify(progState.kpi);
      if (sameRows && sameKpi && (json.total || 0) === progState.total) return;
      const tbody = document.getElementById('prog-table-body');
      if (tbody && newRows.length > 0 && progState.rows.length > 0) {
        const oldRows = progState.rows;
        progState.rows = newRows;
        progState.kpi = json.kpi || progState.kpi;
        progState.total = json.total || 0;
        progState.pages = json.pages || 1;
        if (!sameRows) livePatchRows(tbody, oldRows, newRows, r => r.id, renderSingleRowHtml);
        if (!sameKpi) renderProgKpis();
        renderProgPagination();
        document.dispatchEvent(new CustomEvent('aoi:loaded', { detail: { total: progState.total } }));
        return;
      }
    }

    progState.rows = Array.isArray(json.data) ? json.data : [];
    progState.kpi = json.kpi || progState.kpi;
    progState.total = json.total || 0;
    progState.pages = json.pages || 1;
    progState.viewStatus = progState.rows.length > 0 ? 'ready' : 'empty';

    if (Array.isArray(json.owners)) {
      progState.owners = json.owners;
      populateProgOwners(json.owners);
    }
    if (Array.isArray(json.operators)) {
      progState.operators = json.operators;
    }

    renderProgKpis();
    renderProgTable();
    renderProgPagination();
    renderProgActiveChips();
    syncSavedViewsPillState();
    syncProgUrl();

    // Notify shared search-fx.js (aoi:loaded) to update result count badge
    document.dispatchEvent(new CustomEvent('aoi:loaded', { detail: { total: progState.total } }));
    document.dispatchEvent(new CustomEvent('aoi:programs-loaded', { detail: { count: progState.rows.length, total: progState.total } }));
  } catch (err) {
    if (silent) throw err;   // live sync shows Offline; the table keeps what it has
    console.error('AOI Programs load error:', err);
    progState.viewStatus = 'error';
    renderProgTableError();
  }
}

/**
 * Update single stage with optimistic concurrency support
 */
async function apiUpdateStage(id, stage, status, expectedVersion, note = null) {
  const payload = {
    csrf: window.csrfToken,
    action: 'stage',
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
    const err = new Error(json.errors ? json.errors.join(' ') : 'ไม่สามารถบันทึกสถานะขั้นตอนได้ กรุณาลองใหม่อีกครั้ง');
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}

/**
 * Update existing program row metadata (owner or note)
 */
async function apiUpdateMeta(id, fields, expectedVersion = 0) {
  const payload = Object.assign({}, fields, {
    csrf: window.csrfToken,
    action: 'meta',
    id: id,
    version: expectedVersion,
    operator: progState.currentUser || 'Thanapol'
  });

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
    const err = new Error(json.errors ? json.errors.join(' ') : 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง');
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}

/**
 * Add a new operator option permanently to dropdown options
 */
async function apiAddOperator(name) {
  const payload = {
    csrf: window.csrfToken,
    action: 'add_operator',
    name: name,
    operator: progState.currentUser || name
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
    const err = new Error(json.errors ? json.errors.join(' ') : 'ไม่สามารถเพิ่มผู้ทำใหม่ได้');
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}

/**
 * Remove an operator from the dropdown options (rows already using the name are not changed)
 */
async function apiDeleteOperator(name) {
  const res = await fetch('api/program_stage.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({
      csrf: window.csrfToken,
      action: 'delete_operator',
      name: name,
      operator: progState.currentUser || ''
    })
  });

  const json = await res.json();
  if (!res.ok) {
    const err = new Error(json.errors ? json.errors.join(' ') : 'ไม่สามารถลบผู้ทำได้');
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}

/**
 * Revert previous stage or metadata change on an existing program row
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
    const err = new Error(json.errors ? json.errors.join(' ') : 'ไม่สามารถเลิกทำรายการได้ กรุณาลองใหม่อีกครั้ง');
    err.status = res.status;
    err.data = json;
    throw err;
  }
  return json;
}
