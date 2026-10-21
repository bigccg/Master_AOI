/**
 * Zone & Sheet manager (administrators): zones as groups with their sheets, record counts and status; add / edit
 * forms with instant checks; disable / delete through the topology confirm popup; everything through api/topology.php.
 * The server re-checks every rule; field rules, copy and the reasons on disabled buttons come from its response.
 * After each save applyTopology() (sheet-tabs.js) updates the zone selector, tabs, KPI and rack map without a reload.
 * Markup: components/topology-manager.php · CSS: components/topology-manager.css · Docs: docs/zone-sheet-manager.md
 */

const tmOverlay = document.getElementById('topology-manager');
const tm = { data: null, view: 'list', form: null, busy: false, opener: null, notice: '', seq: 0 };

// UI copy (Thai / English); rule / error messages come from the server (data.copy)
const TM_COPY = {
    loading:       'กำลังโหลดโซนและ Shelf... / Loading zones and shelves...',
    loadError:     'โหลดรายการโซนและ Shelf ไม่สำเร็จ / Could not load zones and shelves',
    loadErrorHint: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบเครือข่ายแล้วลองใหม่ / The server could not be reached. Check the network and retry.',
    authError:     'ต้องเข้าสู่ระบบผู้ดูแลก่อน / Administrator sign-in needed',
    retry:         'ลองโหลดใหม่ / Retry',
    signIn:        'เข้าสู่ระบบอีกครั้ง / Sign in again',
    close:         'ปิด / Close',
    cancel:        'ยกเลิก / Cancel',
    save:          'บันทึก / Save',
    saving:        'กำลังบันทึก / Saving...',
    back:          'กลับไปรายการ / Back to list',
    addZone:       'เพิ่มโซน / Add zone',
    addSheet:      'เพิ่ม Shelf ในโซนนี้ / Add shelf',
    addSheetOff:   'เพิ่ม Shelf ไม่ได้ เพราะโซนนี้ปิดใช้งานอยู่ เปิดใช้งานโซนก่อน / Cannot add a shelf: the zone is disabled. Enable it first.',
    noSheets:      'ยังไม่มี Shelf ในโซนนี้ / No shelves yet',
    noSheetsHint:  'ผู้ใช้จะเห็นข้อความ "ยังไม่มี Shelf ในระบบ" จนกว่าจะเพิ่ม Shelf แรก / Users see "No shelves configured" until the first shelf is added.',
    active:        'เปิดใช้งาน / Active',
    disabled:      'ปิดใช้งาน / Disabled',
    isDefault:     'โซนเริ่มต้น / Default',
    newZone:       'เพิ่มโซนใหม่ / Add zone',
    newSheet:      'เพิ่ม Shelf ใหม่ / Add shelf',
    editZone:      (k) => `แก้ไขโซน ${k} / Edit zone ${k}`,
    editSheet:     (k) => `แก้ไข Shelf ${k} / Edit shelf ${k}`,
    keyLocked:     'แก้ไม่ได้หลังสร้าง เพราะข้อมูลบอร์ดและลิงก์ที่บันทึกไว้อ้างอิงรหัสนี้ / Cannot change after creation: board records and saved links refer to it.',
    prefixLocked:  'แก้ไม่ได้หลังสร้าง เพราะทุกรายการเก็บรหัสชั้นวางเป็น prefix + เลข / Cannot change after creation: every record stores its shelf as prefix + number.',
    zoneKeyHint:   'ตัวอักษรอังกฤษ ตัวเลข หรือ _ ยาว 1–30 ตัว เช่น Plant2_SMT / Letters, digits or _, 1–30 characters, e.g. Plant2_SMT',
    sheetKeyHint:  'ตัวอักษรอังกฤษ ตัวเลข หรือ _ ยาว 1–30 ตัว ห้ามซ้ำทั้งระบบ เช่น D400_Gen / Letters, digits or _, 1–30, unique system-wide, e.g. D400_Gen',
    prefixHint:    'ตัวอักษรพิมพ์ใหญ่ 1–3 ตัว ห้ามซ้ำทั้งระบบ ระบบเติมเลขชั้นวางให้เอง / 1–3 capital letters, unique system-wide; the system adds the shelf number',
    labelHint:     'แสดงในตัวเลือกโซน แท็บ และข้อความ / Shown in the zone selector, tabs and messages',
    shortHint:     'ไม่บังคับ ใช้ในปุ่มและข้อความสั้น เช่น "สลับไปโซน …" / Optional, used in buttons and short texts',
    orderHint:     'เลขน้อยแสดงก่อน เว้นว่าง = ต่อท้าย / Lower shows first; empty = add at the end',
    zoneHint:      'เลือกได้เฉพาะโซนที่เปิดใช้งาน / Only active zones can be chosen',
    createZoneWarn:  'ตรวจสอบก่อนบันทึก: รหัสโซนแก้ภายหลังไม่ได้ เพราะลิงก์และข้อมูลบอร์ดอ้างอิงรหัสนี้ / Check before saving: the zone key cannot be changed later, because links and board records refer to it.',
    createSheetWarn: 'ตรวจสอบก่อนบันทึก: รหัส Shelf และ Shelf prefix แก้ภายหลังไม่ได้ เพราะทุกรายการเก็บรหัสชั้นวางเป็น prefix + เลข Shelf ใหม่เริ่มต้นมี 1 Magazine / Check before saving: the shelf key and shelf prefix cannot be changed later, because every record stores its shelf as prefix + number. A new shelf starts with 1 Magazine.',
    shelfPreview:  'ตัวอย่าง Shelf / Shelf preview:',
    tabPreview:    'ตัวอย่างแท็บ / Tab preview:',
    confirm: {
        sheetDisable: (k, n) => ({ title: 'ปิดใช้งาน Shelf / Disable Shelf', icon: 'fa-power-off', mode: 'DISABLE', seg: 'shelf → disable', ok: 'ปิดใช้งาน / Disable',
            before: 'ปิดใช้งาน Shelf ', key: k,
            after: ` หรือไม่? Shelf จะหายจากแท็บ และเพิ่มข้อมูลหรือ Magazine ใหม่ไม่ได้ ข้อมูลเดิม ${n} รายการยังอยู่ครบ ดู แก้ไข และส่งออกได้ เปิดใช้งานกลับได้ทุกเมื่อ`,
            en: `Disable shelf ${k}? It leaves the tabs and takes no new records or Magazines. Its ${n} record(s) stay intact and can still be viewed, edited and exported. You can enable it again at any time.` }),
        sheetDelete: (k) => ({ title: 'ลบ Shelf ถาวร / Delete Shelf', icon: 'fa-trash-can', mode: 'DELETE', seg: 'shelf → delete', ok: 'ลบถาวร / Delete',
            before: 'ลบ Shelf ', key: k, after: ' ถาวรหรือไม่? Shelf นี้ยังไม่มีข้อมูลบอร์ด การลบไม่สามารถย้อนกลับได้ (สร้างใหม่ด้วยรหัสเดิมได้)',
            en: `Delete shelf ${k} permanently? It has no board records. This cannot be undone (you can create it again with the same key).` }),
        zoneDisable: (k, n) => ({ title: 'ปิดใช้งานโซน / Disable Zone', icon: 'fa-power-off', mode: 'DISABLE', seg: 'zone → disable', ok: 'ปิดใช้งาน / Disable',
            before: 'ปิดใช้งานโซน ', key: k,
            after: ` หรือไม่? โซนจะหายจากตัวเลือกโซนและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิม ${n} รายการยังอยู่ครบ และเปิดดูจากลิงก์เดิมได้ เปิดใช้งานกลับได้ทุกเมื่อ`,
            en: `Disable zone ${k}? It leaves the zone selector and takes no new records. Its ${n} record(s) stay intact and still open from existing links. You can enable it again at any time.` }),
        zoneDelete: (k) => ({ title: 'ลบโซนถาวร / Delete Zone', icon: 'fa-trash-can', mode: 'DELETE', seg: 'zone → delete', ok: 'ลบถาวร / Delete',
            before: 'ลบโซน ', key: k, after: ' ถาวรหรือไม่? โซนนี้ไม่มี Shelf และไม่มีข้อมูลบอร์ด การลบไม่สามารถย้อนกลับได้',
            en: `Delete zone ${k} permanently? It has no shelves and no board records. This cannot be undone.` }),
    },
};

// Server copy with {placeholders} (includes/topology-manager.php topologyCopy())
function tmMsg(code, params = {}) {
    let text = tm.data?.copy?.[code] ?? code;
    Object.entries(params).forEach(([k, v]) => { text = text.split(`{${k}}`).join(String(v)); });
    return text;
}

const tmBody    = () => document.getElementById('tm-body');
const tmActions = () => document.getElementById('tm-actions');
const tmZones   = () => tm.data?.zones || [];
const tmFindZone  = (key) => tmZones().find(z => z.key === key) || null;
const tmFindSheet = (key) => { for (const z of tmZones()) { const s = z.sheets.find(x => x.key === key); if (s) return [s, z]; } return [null, null]; };
const tmCount = (n, th, en) => `${n} ${th} / ${n} ${en}`;

function tmAnnounce(text) {
    const live = document.getElementById('tm-live');
    if (live) { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 30); }
}

function tmSetStatus(mode, info) {
    const m = document.getElementById('tm-mode-text');
    const i = document.getElementById('tm-st-info');
    if (m) m.textContent = mode;
    if (i) i.textContent = info;
}

// ── open / close / load ───────────────────────────────────────────────

function openTopologyManager() {
    if (!tmOverlay) return;
    tm.opener = document.activeElement;
    tm.view   = 'list';
    tm.notice = '';
    gmShow(tmOverlay);
    tmLoad();
}
window.openTopologyManager = openTopologyManager;

function closeTopologyManager() {
    gmHide(tmOverlay);
    tm.opener?.focus?.({ preventScroll: true });
}
window.closeTopologyManager = closeTopologyManager;

function tmStateHtml(kind, title, text, button = '') {
    const spin = kind === 'loading'
        ? '<svg class="h-5 w-5 animate-spin text-emerald-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>'
        : '<i class="fa-solid fa-circle-exclamation text-red-600" aria-hidden="true"></i>';
    return `<div class="tm-state${kind === 'error' ? ' is-error' : ''}" role="${kind === 'error' ? 'alert' : 'status'}">${spin}<b>${h(title)}</b>${text ? `<span>${h(text)}</span>` : ''}${button}</div>`;
}

function tmSignInUrl() {
    return `admin.php?zone=${encodeURIComponent(state.zone)}&sheet=${encodeURIComponent(state.sheet)}`;
}

// Session expired / not configured: the server's message + a way back in
function tmAuthErrorHtml(message) {
    return tmStateHtml('error', TM_COPY.authError, message,
        `<a href="${h(tmSignInUrl())}" class="gm-btn primary tm-btn-sm"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>${h(TM_COPY.signIn)}</a>`);
}

async function tmLoad() {
    const body = tmBody();
    body.setAttribute('aria-busy', 'true');
    body.innerHTML = tmStateHtml('loading', TM_COPY.loading, '');
    tmActions().innerHTML = `<button type="button" class="gm-btn ghost" data-tm-action="close">${h(TM_COPY.close)}</button>`;
    tmSetStatus('ADMIN', '…');
    try {
        const res  = await fetch('api/topology.php', { headers: { Accept: 'application/json' }, cache: 'no-store' });
        const json = await res.json();
        if (res.status === 401 || res.status === 503) {
            body.innerHTML = tmAuthErrorHtml((json.errors || [])[0] || '');
            return;
        }
        if (!res.ok || !json.success) throw new Error(`HTTP ${res.status}`);
        tm.data = json.data;
        tmRender();
    } catch (err) {
        console.error(err);
        body.innerHTML = tmStateHtml('error', TM_COPY.loadError, TM_COPY.loadErrorHint,
            `<button type="button" class="gm-btn ghost tm-btn-sm" data-tm-action="reload"><i class="fa-solid fa-arrow-rotate-right" aria-hidden="true"></i>${h(TM_COPY.retry)}</button>`);
    } finally {
        body.setAttribute('aria-busy', 'false');
    }
}

function tmRender() {
    if (!tm.data) return;
    if (tm.view === 'list') tmRenderList();
    else tmRenderForm();
}

// ── list view ─────────────────────────────────────────────────────────

// Icon button; a reason makes it aria-disabled (still focusable: tooltip, screen reader text, click shows the reason)
function tmBtn(action, key, icon, label, reason = null, danger = false) {
    const id = `tm-why-${++tm.seq}`;
    const off = reason ? ` aria-disabled="true" aria-describedby="${id}" data-reason="${h(reason)}"` : '';
    return `<button type="button" class="tm-icon-btn${danger ? ' is-danger' : ''}" data-tm-action="${action}" data-key="${h(key)}"
                aria-label="${h(label)}" title="${h(reason || label)}"${off}><i class="fa-solid ${icon}" aria-hidden="true"></i></button>`
        + (reason ? `<span id="${id}" class="sr-only">${h(reason)}</span>` : '');
}

function tmChip(kind, text) {
    return `<span class="tm-chip is-${kind}">${h(text)}</span>`;
}

function tmSheetHtml(s, z) {
    const toggle = s.is_active
        ? tmBtn('sheet-disable', s.key, 'fa-power-off', `ปิดใช้งาน Shelf ${s.key} / Disable shelf ${s.key}`, s.guards.deactivate)
        : tmBtn('sheet-enable', s.key, 'fa-toggle-on', `เปิดใช้งาน Shelf ${s.key} / Enable shelf ${s.key}`, s.guards.activate);
    return `
        <li class="tm-sheet-item">
          <div class="tm-row tm-sheet-row${s.is_active ? '' : ' is-off'}" data-tm-row data-kind="sheet" data-key="${h(s.key)}" tabindex="-1"
               aria-label="${h(`Shelf ${s.label} (${s.key}) · ${s.records} รายการ · ${s.is_active ? TM_COPY.active : TM_COPY.disabled}`)}">
            <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block shrink-0 ${h(s.dot)}" aria-hidden="true"></span>
            <div class="tm-main">
              <p class="tm-name"><span class="tm-label">${h(s.label)}</span>${s.is_active ? '' : tmChip('off', TM_COPY.disabled)}</p>
              <p class="tm-meta"><code>${h(s.key)}</code> · Shelf <b>${h(s.prefix)}</b>1… · ${h(tmCount(s.records, 'รายการ', 'records'))} · ${h(`${s.magazines} Mag`)}</p>
            </div>
            <div class="tm-actions">
              ${tmBtn('sheet-edit', s.key, 'fa-pen', `แก้ไข Shelf ${s.key} / Edit shelf ${s.key}`)}
              ${toggle}
              ${tmBtn('sheet-delete', s.key, 'fa-trash-can', `ลบ Shelf ${s.key} / Delete shelf ${s.key}`, s.guards.delete, true)}
            </div>
          </div>
        </li>`;
}

function tmZoneHtml(z) {
    const toggle = z.is_active
        ? tmBtn('zone-disable', z.key, 'fa-power-off', `ปิดใช้งานโซน ${z.key} / Disable zone ${z.key}`, z.guards.deactivate)
        : tmBtn('zone-enable', z.key, 'fa-toggle-on', `เปิดใช้งานโซน ${z.key} / Enable zone ${z.key}`, z.guards.activate);
    const sheets = z.sheets.length
        ? z.sheets.map(s => tmSheetHtml(s, z)).join('')
        : `<li class="tm-empty-item"><div class="tm-empty"><b>${h(TM_COPY.noSheets)}</b>${h(TM_COPY.noSheetsHint)}</div></li>`;
    const addId = `tm-why-${++tm.seq}`;
    const add = z.is_active
        ? `<button type="button" class="tm-add-sheet-btn" data-tm-action="sheet-add" data-key="${h(z.key)}" title="${h(TM_COPY.addSheet)}"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>${h(TM_COPY.addSheet)}</span></button>`
        : `<button type="button" class="tm-add-sheet-btn" data-tm-action="sheet-add" data-key="${h(z.key)}" aria-disabled="true" aria-describedby="${addId}" data-reason="${h(TM_COPY.addSheetOff)}" title="${h(TM_COPY.addSheetOff)}"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>${h(TM_COPY.addSheet)}</span></button><span id="${addId}" class="sr-only">${h(TM_COPY.addSheetOff)}</span>`;
    return `
      <li class="tm-zone${z.is_active ? '' : ' is-off'}">
        <div class="tm-row tm-zone-row${z.is_active ? '' : ' is-off'}" data-tm-row data-kind="zone" data-key="${h(z.key)}" tabindex="-1"
             aria-label="${h(`โซน ${z.label} (${z.key}) · ${z.sheets.length} Shelf · ${z.records} รายการ · ${z.is_active ? TM_COPY.active : TM_COPY.disabled}`)}">
          <span class="tm-zone-ico" aria-hidden="true"><i class="fa-solid fa-layer-group"></i></span>
          <div class="tm-main">
            <p class="tm-name"><span class="tm-label">${h(z.label)}</span>${z.is_default ? tmChip('default', TM_COPY.isDefault) : ''}${tmChip(z.is_active ? 'on' : 'off', z.is_active ? TM_COPY.active : TM_COPY.disabled)}</p>
            <p class="tm-meta"><code>${h(z.key)}</code> · ${h(tmCount(z.sheets.length, 'Shelf', 'shelves'))} · ${h(tmCount(z.records, 'รายการ', 'records'))} · ${h(`ลำดับ / order ${z.sort_order}`)}</p>
          </div>
          <div class="tm-actions">
            ${add}
            ${tmBtn('zone-edit', z.key, 'fa-pen', `แก้ไขโซน ${z.key} / Edit zone ${z.key}`)}
            ${toggle}
            ${tmBtn('zone-delete', z.key, 'fa-trash-can', `ลบโซน ${z.key} / Delete zone ${z.key}`, z.guards.delete, true)}
          </div>
        </div>
        <ul class="tm-sheets" aria-label="${h(`Shelf ในโซน ${z.key} / Shelves in ${z.key}`)}">
          ${sheets}
        </ul>
      </li>`;
}

function tmRenderList(focusKey = null) {
    const d = tm.data;
    tm.seq = 0;
    tmBody().innerHTML = `
        <div class="tm-toolbar">
          <p class="tm-summary">${h(`${d.totals.zones} โซน · ${d.totals.sheets} Shelf · ${d.totals.records} รายการ / ${d.totals.zones} zones · ${d.totals.sheets} shelves · ${d.totals.records} records`)}</p>
          <button type="button" class="gm-btn ghost tm-btn-sm" data-tm-action="zone-add"><i class="fa-solid fa-plus" aria-hidden="true"></i>${h(TM_COPY.addZone)}</button>
        </div>
        <div id="tm-notice" class="tm-notice" role="status"${tm.notice ? '' : ' hidden'}><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>${h(tm.notice)}</span></div>
        <ul class="tm-zones" aria-label="โซนและ Shelf / Zones and shelves">${d.zones.map(tmZoneHtml).join('')}</ul>`;
    tmActions().innerHTML = `<button type="button" class="gm-btn ghost" data-tm-action="close">${h(TM_COPY.close)}</button>`;
    tmSetStatus('ADMIN', `${d.totals.zones} zones · ${d.totals.sheets} sheets`);

    const rows = [...tmBody().querySelectorAll('[data-tm-row]')];
    if (rows.length) rows[0].tabIndex = 0;   // roving tabindex: one row in the Tab order, arrows move between rows
    const target = focusKey && rows.find(r => r.dataset.key === focusKey);
    if (target) {
        rows.forEach(r => { r.tabIndex = -1; });
        target.tabIndex = 0;
        target.focus({ preventScroll: false });
    } else if (!tmBody().contains(document.activeElement)) {
        setTimeout(() => tmBody().querySelector('[data-tm-action="zone-add"]')?.focus({ preventScroll: true }), 60);
    }
}

function tmShowNotice(text) {
    tm.notice = text;
    const box = document.getElementById('tm-notice');
    if (box) {
        box.hidden = false;
        box.querySelector('span').textContent = text;
    }
    tmAnnounce(text);
}

// ── auto-generation & suggestions ──────────────────────────────────────

let tmAutoSync = { zone_key: true, sheet_key: true, shelf_prefix: true };

function tmSuggestZoneKey(label) {
    const existing = new Set(tmZones().map(z => z.key.toLowerCase()));
    const raw = String(label || '').trim();
    let clean = raw.replace(/[^A-Za-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
    if (/[A-Za-z]/.test(clean)) {
        clean = clean.slice(0, 26);
        if (!existing.has(clean.toLowerCase())) return clean;
        for (let i = 1; i <= 99; i++) {
            const cand = `${clean}_${i}`.slice(0, 30);
            if (!existing.has(cand.toLowerCase())) return cand;
        }
    }
    for (let i = 1; i <= 999; i++) {
        const cand = `Zone_${i}`;
        if (!existing.has(cand.toLowerCase())) return cand;
    }
    return `Zone_${Date.now().toString(36).slice(-4)}`;
}

function tmSuggestSheetKey(label) {
    const existing = new Set();
    for (const z of tmZones()) {
        for (const s of (z.sheets || [])) {
            existing.add(s.key.toLowerCase());
        }
    }
    const reserved = new Set((tm.data?.rules?.reserved_sheet_keys || ['ALL']).map(k => k.toLowerCase()));
    const raw = String(label || '').trim();
    let clean = raw.replace(/[^A-Za-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
    if (/[A-Za-z]/.test(clean)) {
        clean = clean.slice(0, 26);
        if (!existing.has(clean.toLowerCase()) && !reserved.has(clean.toLowerCase())) return clean;
        for (let i = 1; i <= 99; i++) {
            const cand = `${clean}_${i}`.slice(0, 30);
            if (!existing.has(cand.toLowerCase()) && !reserved.has(cand.toLowerCase())) return cand;
        }
    }
    for (let i = 1; i <= 999; i++) {
        const cand = `Sheet_${i}`;
        if (!existing.has(cand.toLowerCase()) && !reserved.has(cand.toLowerCase())) return cand;
    }
    return `Sheet_${Date.now().toString(36).slice(-4)}`;
}

function tmSuggestShelfPrefix(label) {
    const raw = String(label || '').trim();
    const lead = raw.match(/^[A-Za-z]{1,3}/);
    if (lead) {
        return lead[0].toUpperCase();
    }
    const words = raw.toUpperCase().split(/[^A-Z0-9]+/).filter(w => /^[A-Z]/.test(w));
    if (words.length >= 2) {
        const acronym = words.map(w => w[0]).join('').slice(0, 3);
        if (acronym.length >= 1) return acronym;
    }
    const letters = raw.toUpperCase().replace(/[^A-Z]/g, '');
    if (letters.length > 0) {
        return letters.slice(0, 2);
    }
    return 'A';
}

// ── forms ─────────────────────────────────────────────────────────────

function tmInput(f) {
    const hintId = `${f.id}-hint`;
    const errId  = `${f.id}-err`;
    const badgeHtml = f.badge
        ? `<span class="tm-auto-badge text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-1.5 py-0.5 rounded font-normal ml-1.5 shrink-0"><i class="fa-solid fa-wand-magic-sparkles mr-0.5"></i>${h(f.badge)}</span>`
        : '';
    return `
        <div class="gm-field" data-field="${f.name}">
          <label class="gm-label" for="${f.id}"><span class="k">${f.k}</span><span>${h(f.label)} <span class="th">(${h(f.en)})</span>${f.required ? '<i class="gm-req">*</i>' : ''}</span>${badgeHtml}</label>
          <div class="gm-input-wrap">
            <input id="${f.id}" name="${f.name}" type="text" value="${h(f.value ?? '')}" class="gm-input has-ico${f.cls ? ' ' + f.cls : ''}"
                   ${f.max ? `maxlength="${f.max}"` : ''} ${f.readonly ? 'readonly aria-readonly="true"' : ''} ${f.required && !f.readonly ? 'aria-required="true"' : ''}
                   ${f.inputmode ? `inputmode="${f.inputmode}"` : ''} autocomplete="off" spellcheck="false" aria-describedby="${hintId} ${errId}"/>
            <i class="fa-solid ${f.readonly ? 'fa-lock' : f.icon} gm-ico" aria-hidden="true"></i>
          </div>
          <p class="gm-hint" id="${hintId}">${h(f.hint)}</p>
          <p class="gm-hint tm-field-error" id="${errId}" hidden></p>
          ${f.after || ''}
        </div>`;
}

function tmZoneFormHtml(z) {
    const isNew = !z;
    const defaultKey = isNew ? tmSuggestZoneKey(z?.label || '') : (z?.key || '');
    return `
        <form id="tm-form" class="tm-form" novalidate aria-labelledby="tm-form-title">
          <div class="tm-form-head">
            <button type="button" class="tm-back" data-tm-action="back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>${h(TM_COPY.back)}</button>
            <h4 id="tm-form-title" class="tm-form-title">${h(isNew ? TM_COPY.newZone : TM_COPY.editZone(z.key))}</h4>
          </div>
          <div id="tm-form-error" class="gm-error" role="alert" hidden></div>
          <input type="hidden" id="tm-f-zone_key" name="zone_key" value="${h(defaultKey)}" />
          ${tmInput({ k: '01', id: 'tm-f-label', name: 'label', label: 'ชื่อโซนที่แสดง', en: 'Zone name', required: true, max: tm.data.rules.label_max,
                      icon: 'fa-tag', value: z?.label, hint: 'พิมพ์ชื่อโซน เช่น Line 1 หรือ โรงงาน 2' })}
          ${tmInput({ k: '02', id: 'tm-f-short_label', name: 'short_label', label: 'ชื่อย่อ', en: 'Short name (optional)', max: tm.data.rules.short_max,
                      icon: 'fa-font', value: z?.short_label, hint: TM_COPY.shortHint })}
        </form>`;
}

function tmSheetFormHtml(s, zone) {
    const isNew   = !s;
    const rules   = tm.data.rules;
    const moveWhy = s?.guards.move || null;
    const zoneOpts = tmZones()
        .filter(z => z.is_active || z.key === zone.key)
        .map(z => `<option value="${h(z.key)}"${z.key === zone.key ? ' selected' : ''}${z.is_active ? '' : ' disabled'}>${h(`${z.label} (${z.key})${z.is_active ? '' : ' · ' + TM_COPY.disabled}`)}</option>`)
        .join('');
    const used    = new Set(zone.sheets.map(x => x.dot));
    const dot     = s?.dot || (tm.data.palette.find(p => !used.has(p.value)) || tm.data.palette[0]).value;
    const swatches = tm.data.palette.map(p => `
        <label class="tm-swatch" title="${h(p.label)}">
          <input type="radio" name="dot_color" value="${h(p.value)}" class="sr-only"${p.value === dot ? ' checked' : ''}/>
          <span class="tm-swatch-dot ${h(p.value)}" aria-hidden="true"></span><span class="sr-only">${h(p.label)}</span>
        </label>`).join('');

    const defaultKey = isNew ? tmSuggestSheetKey(s?.label || '') : (s?.key || '');
    const defaultPrefix = isNew ? tmSuggestShelfPrefix(s?.label || '') : (s?.prefix || '');

    return `
        <form id="tm-form" class="tm-form" novalidate aria-labelledby="tm-form-title">
          <div class="tm-form-head">
            <button type="button" class="tm-back" data-tm-action="back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>${h(TM_COPY.back)}</button>
            <h4 id="tm-form-title" class="tm-form-title">${h(isNew ? TM_COPY.newSheet : TM_COPY.editSheet(s.key))}</h4>
          </div>
          <div id="tm-form-error" class="gm-error" role="alert" hidden></div>
          <input type="hidden" id="tm-f-sheet_key" name="sheet_key" value="${h(defaultKey)}" />
          <input type="hidden" id="tm-f-shelf_prefix" name="shelf_prefix" value="${h(defaultPrefix)}" />
          <div class="gm-field" data-field="zone_key">
            <label class="gm-label" for="tm-f-zone_key"><span class="k">01</span><span>โซนที่สังกัด <span class="th">(Zone)</span><i class="gm-req">*</i></span></label>
            <select id="tm-f-zone_key" name="zone_key" class="gm-input"${moveWhy ? ' disabled aria-disabled="true"' : ''} aria-describedby="tm-f-zone_key-hint tm-f-zone_key-err">${zoneOpts}</select>
            <p class="gm-hint" id="tm-f-zone_key-hint">${h(moveWhy || 'โซนที่ Shelf นี้สังกัดอยู่')}</p>
            <p class="gm-hint tm-field-error" id="tm-f-zone_key-err" hidden></p>
          </div>
          ${tmInput({ k: '02', id: 'tm-f-label', name: 'label', label: 'ชื่อ Shelf ที่แสดง', en: 'Shelf name', required: true, max: rules.label_max,
                      icon: 'fa-tag', value: s?.label, hint: 'พิมพ์ชื่อ Shelf เช่น CD200_Gen, CD250_Gen หรือ A150' })}
          <fieldset class="gm-field tm-swatch-field" data-field="dot_color" aria-describedby="tm-f-dot_color-err">
            <legend class="gm-label"><span class="k">03</span><span>สีของแท็บ <span class="th">(Tab colour)</span><i class="gm-req">*</i></span></legend>
            <div class="tm-swatches">${swatches}</div>
            <p class="gm-hint tm-field-error" id="tm-f-dot_color-err" hidden></p>
            <div class="tm-preview"><span>${h(TM_COPY.tabPreview)}</span><span class="tm-tab-preview"><span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block" id="tm-tab-dot" aria-hidden="true"></span><span id="tm-tab-label"></span></span></div>
          </fieldset>
        </form>`;
}

function tmRenderForm() {
    const f = tm.form;
    if (f.kind === 'zone') {
        tmBody().innerHTML = tmZoneFormHtml(f.key ? tmFindZone(f.key) : null);
    } else {
        const [sheet, zone] = f.key ? tmFindSheet(f.key) : [null, tmFindZone(f.zone)];
        tmBody().innerHTML = tmSheetFormHtml(sheet, zone);
        tmSyncSheetPreview();
    }
    tmActions().innerHTML = `
        <button type="button" class="gm-btn ghost" data-tm-action="back">${h(TM_COPY.cancel)}</button>
        <button type="submit" form="tm-form" id="tm-submit" class="gm-btn primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span>${h(TM_COPY.save)}</span><kbd>Enter ↵</kbd></button>`;
    tmSetStatus(f.key ? 'EDIT' : 'NEW', `${f.kind} → ${f.key || 'new'}`);
    document.getElementById('tm-window')?.classList.toggle('is-edit', !!f.key);

    const form = document.getElementById('tm-form');
    const isNew = !f.key;
    form.addEventListener('submit', tmSubmit);
    form.addEventListener('input', (e) => {
        const name = e.target.name;
        if (name === 'label' && isNew) {
            const val = e.target.value;
            if (f.kind === 'zone') {
                if (form.elements.zone_key) {
                    form.elements.zone_key.value = tmSuggestZoneKey(val);
                }
            } else if (f.kind === 'sheet') {
                if (form.elements.sheet_key) {
                    form.elements.sheet_key.value = tmSuggestSheetKey(val);
                }
                if (form.elements.shelf_prefix) {
                    form.elements.shelf_prefix.value = tmSuggestShelfPrefix(val);
                }
            }
        }
        if (f.kind === 'sheet') tmSyncSheetPreview();
        if (name) tmShowFieldError(name, tmCheckField(name));
    });
    form.addEventListener('change', (e) => { if (e.target.name === 'dot_color') tmSyncSheetPreview(); });
    setTimeout(() => {
        const target = (isNew && form.elements.label)
            ? form.elements.label
            : form.querySelector('input:not([readonly]):not([type="radio"]), select:not([disabled])');
        target?.focus({ preventScroll: true });
    }, 60);
}

function tmOpenForm(kind, key = null, zone = null) {
    tm.view = 'form';
    tm.form = { kind, key, zone };
    tm.notice = '';
    tmAutoSync = { zone_key: true, sheet_key: true, shelf_prefix: true };
    tmRenderForm();
}

function tmBackToList(focusKey = null) {
    tm.view = 'list';
    tm.form = null;
    document.getElementById('tm-window')?.classList.remove('is-edit');
    tmRenderList(focusKey);
}

function tmSyncSheetPreview() {
    const form = document.getElementById('tm-form');
    if (!form) return;
    const prefix = (form.elements.shelf_prefix?.value || '').trim().toUpperCase();
    const box = document.getElementById('tm-shelf-preview');
    if (box) {
        const size = tm.data.rules.magazine_size;
        box.innerHTML = new RegExp(tm.data.rules.prefix_pattern).test(prefix)
            ? `${[1, 2, 3].map(n => `<span class="gm-shelf-pill">${h(prefix + n)}</span>`).join(' ')} … <span class="gm-shelf-pill">${h(prefix + size)}</span>`
            : '<span>—</span>';
    }
    const dot = form.querySelector('input[name="dot_color"]:checked')?.value || '';
    const dotEl = document.getElementById('tm-tab-dot');
    if (dotEl) dotEl.className = `tab-dot w-2.5 h-2.5 rounded-sm inline-block ${dot}`;
    const labelEl = document.getElementById('tm-tab-label');
    if (labelEl) labelEl.textContent = tmClean(form.elements.label?.value || '') || form.elements.sheet_key?.value || '…';
}

// ── instant checks (the same rules and copy as the server) ───────────

const tmClean = (v) => String(v || '').replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/\s+/g, ' ').trim();
const tmLen   = (v) => [...v].length;

function tmCheckField(name) {
    const form = document.getElementById('tm-form');
    if (!form || !tm.form) return '';
    const r     = tm.data.rules;
    const isNew = !tm.form.key;
    const value = (form.elements[name]?.value ?? '');
    const kind  = tm.form.kind;

    if (name === 'zone_key' && kind === 'zone' && isNew) {
        let v = value.trim();
        if (v === '' && form.elements.label?.value.trim()) {
            v = tmSuggestZoneKey(form.elements.label.value);
            form.elements.zone_key.value = v;
        }
        if (v === '') return tmMsg('zone_key_required');
        if (!new RegExp(r.key_pattern).test(v)) return tmMsg('key_format', { key: v });
        const taken = tmZones().find(z => z.key.toLowerCase() === v.toLowerCase());
        if (taken) return tmMsg('zone_key_taken', { key: v, existing: taken.key });
    }
    if (name === 'zone_key' && kind === 'sheet' && value === '') return tmMsg('zone_required');
    if (name === 'sheet_key' && isNew) {
        let v = value.trim();
        if (v === '' && form.elements.label?.value.trim()) {
            v = tmSuggestSheetKey(form.elements.label.value);
            form.elements.sheet_key.value = v;
        }
        if (v === '') return tmMsg('sheet_key_required');
        if (!new RegExp(r.key_pattern).test(v)) return tmMsg('key_format', { key: v });
        if (r.reserved_sheet_keys.includes(v.toUpperCase())) return tmMsg('sheet_key_reserved', { key: v });
        for (const z of tmZones()) {
            const s = z.sheets.find(x => x.key.toLowerCase() === v.toLowerCase());
            if (s) return tmMsg('sheet_key_taken', { key: v, existing: s.key, zone: z.key });
        }
    }
    if (name === 'shelf_prefix' && isNew) {
        let v = value.trim().toUpperCase();
        if (v === '' && form.elements.label?.value.trim()) {
            v = tmSuggestShelfPrefix(form.elements.label.value);
            form.elements.shelf_prefix.value = v;
        }
        if (v === '') return tmMsg('prefix_required');
        if (!new RegExp(r.prefix_pattern).test(v)) return tmMsg('prefix_format', { prefix: v });
        // Shelf prefix is allowed to be shared across multiple sheets (e.g. CD200_Gen, CD250_Gen, CD300)
    }
    if (name === 'label') {
        const v = tmClean(value);
        if (v === '') return tmMsg('label_required', { max: r.label_max });
        if (tmLen(v) > r.label_max) return tmMsg('label_too_long', { length: tmLen(v), max: r.label_max });
    }
    if (name === 'short_label') {
        const v = tmClean(value);
        if (tmLen(v) > r.short_max) return tmMsg('short_too_long', { length: tmLen(v), max: r.short_max });
    }
    if (name === 'sort_order') {
        const v = value.trim();
        if (v !== '' && (!/^\d{1,4}$/.test(v) || Number(v) > r.order_max)) return tmMsg('order_invalid', { max: r.order_max });
    }
    if (name === 'dot_color') {
        const v = form.querySelector('input[name="dot_color"]:checked')?.value || '';
        if (!tm.data.palette.some(p => p.value === v)) return tmMsg('dot_invalid');
    }
    return '';
}

function tmShowFieldError(name, message) {
    const err = document.getElementById(`tm-f-${name}-err`);
    if (!err) return;
    const input = document.getElementById(`tm-f-${name}`);
    err.hidden = !message;
    err.innerHTML = message ? `<span class="font-semibold text-red-600">${h(message)}</span>` : '';
    if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
}

function tmFormErrors(errors) {
    const box = document.getElementById('tm-form-error');
    if (!box) return;
    box.hidden = !errors.length;
    box.innerHTML = errors.map(e => `<div><i class="fa-solid fa-circle-exclamation mr-1" aria-hidden="true"></i>${h(e)}</div>`).join('');
}

// ── saving ────────────────────────────────────────────────────────────

async function tmPost(payload) {
    const res = await fetch('api/topology.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ csrf: getCsrf(), ...payload }),
    });
    let json = {};
    try { json = await res.json(); } catch (_) { json = {}; }
    return { status: res.status, json };
}

// After a successful write: new manager data, page topology, toast; failures return the response for the caller
async function tmAfterWrite(status, json) {
    if (json.success) {
        tm.data = json.data;
        showFlash('ok', json.message);
        tmAnnounce(json.message);
        // the manager re-renders right away; the page behind it (tabs, table, KPI, rack map) catches up on its own
        applyTopology(json.topology).catch(err => console.error('applyTopology failed:', err));
        return true;
    }
    if (status === 401 || status === 503) {
        tmBody().innerHTML = tmAuthErrorHtml((json.errors || [])[0] || '');
        tmActions().innerHTML = `<button type="button" class="gm-btn ghost" data-tm-action="close">${h(TM_COPY.close)}</button>`;
        return false;
    }
    return false;
}

async function tmSubmit(e) {
    e.preventDefault();
    if (tm.busy) return;
    const form  = e.target;
    const f     = tm.form;
    const isNew = !f.key;

    // Auto-generate keys right before validation if left empty
    if (isNew) {
        if (f.kind === 'zone') {
            if (!form.elements.zone_key?.value.trim() && form.elements.label?.value.trim()) {
                form.elements.zone_key.value = tmSuggestZoneKey(form.elements.label.value);
            }
        } else {
            if (!form.elements.sheet_key?.value.trim() && form.elements.label?.value.trim()) {
                form.elements.sheet_key.value = tmSuggestSheetKey(form.elements.label.value);
            }
            if (!form.elements.shelf_prefix?.value.trim()) {
                form.elements.shelf_prefix.value = tmSuggestShelfPrefix(form.elements.label?.value);
                tmSyncSheetPreview();
            }
        }
    }

    const names = f.kind === 'zone'
        ? (isNew ? ['label', 'zone_key', 'short_label'] : ['label', 'short_label'])
        : (isNew ? ['zone_key', 'label', 'sheet_key', 'shelf_prefix', 'dot_color'] : ['zone_key', 'label', 'dot_color']);

    let firstBad = null;
    names.forEach(n => {
        const msg = tmCheckField(n);
        tmShowFieldError(n, msg);
        if (msg && !firstBad) firstBad = n;
    });
    tmFormErrors([]);
    if (firstBad) {
        const badEl = document.getElementById(`tm-f-${firstBad}`) || form.querySelector(`[name="${firstBad}"]`);
        if (badEl && badEl.type !== 'hidden') badEl.focus();
        else form.elements.label?.focus();
        return;
    }

    const payload = { action: `${f.kind}_${isNew ? 'create' : 'update'}` };
    names.forEach(n => {
        const el = n === 'dot_color' ? form.querySelector('input[name="dot_color"]:checked') : form.elements[n];
        if (el && !el.disabled) payload[n] = n === 'shelf_prefix' ? el.value.trim().toUpperCase() : el.value;
    });
    if (!isNew) payload[f.kind === 'zone' ? 'zone_key' : 'sheet_key'] = f.key;
    const key = f.key || String((f.kind === 'zone' ? payload.zone_key : payload.sheet_key) || '').trim();

    const btn = document.getElementById('tm-submit');
    const orig = btn?.innerHTML;
    tm.busy = true;
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>${h(TM_COPY.saving)}</span>`; }
    try {
        const { status, json } = await tmPost(payload);
        if (await tmAfterWrite(status, json)) {
            tmBackToList(key);
            return;
        }
        if (status !== 401 && status !== 503) {
            // field errors next to their field; anything else (state rules, network) in the box above the form
            const fe = json.field_errors || {};
            Object.entries(fe).forEach(([n, msg]) => tmShowFieldError(n, msg));
            const inline = new Set(Object.entries(fe).filter(([n]) => document.getElementById(`tm-f-${n}-err`)).map(([, msg]) => msg));
            const rest = (json.errors || []).filter(m => !inline.has(m));
            tmFormErrors(rest.length || inline.size ? rest : [MSG.networkError]);
            const bad = Object.keys(fe).find(n => document.getElementById(`tm-f-${n}`));
            document.getElementById(bad ? `tm-f-${bad}` : 'tm-submit')?.focus();
        }
    } catch (err) {
        console.error(err);
        tmFormErrors([MSG.networkError]);
    } finally {
        tm.busy = false;
        if (btn && document.body.contains(btn)) { btn.disabled = false; btn.innerHTML = orig; }
    }
}

// Disable / enable / delete from the list (disable + delete ask first)
async function tmRowAction(action, key) {
    const [sheet, sheetZone] = action.startsWith('sheet') ? tmFindSheet(key) : [null, null];
    const zone = action.startsWith('zone') ? tmFindZone(key) : sheetZone;
    if (!zone) return;

    let payload, ask = null;
    switch (action) {
        case 'zone-disable':  payload = { action: 'zone_update', zone_key: key, is_active: 0 }; ask = TM_COPY.confirm.zoneDisable(key, zone.records); break;
        case 'zone-enable':   payload = { action: 'zone_update', zone_key: key, is_active: 1 }; break;
        case 'zone-delete':   payload = { action: 'zone_delete', zone_key: key }; ask = TM_COPY.confirm.zoneDelete(key); break;
        case 'sheet-disable': payload = { action: 'sheet_update', sheet_key: key, is_active: 0 }; ask = TM_COPY.confirm.sheetDisable(key, sheet.records); break;
        case 'sheet-enable':  payload = { action: 'sheet_update', sheet_key: key, is_active: 1 }; break;
        case 'sheet-delete':  payload = { action: 'sheet_delete', sheet_key: key }; ask = TM_COPY.confirm.sheetDelete(key); break;
        default: return;
    }
    if (ask && !(await openTopologyConfirm(ask))) return;

    const body = tmBody();
    tm.busy = true;
    body.classList.add('is-busy');
    body.setAttribute('aria-busy', 'true');
    try {
        const { status, json } = await tmPost(payload);
        if (await tmAfterWrite(status, json)) {
            tm.notice = '';
            tmRenderList(action.endsWith('delete') ? null : key);
        } else if (status !== 401 && status !== 503) {
            tmShowNotice((json.errors || [MSG.networkError]).join(' '));
            showFlash('err', (json.errors || [MSG.networkError])[0]);
        }
    } catch (err) {
        console.error(err);
        tmShowNotice(MSG.networkError);
    } finally {
        tm.busy = false;
        body.classList.remove('is-busy');
        body.setAttribute('aria-busy', 'false');
    }
}

// ── events ────────────────────────────────────────────────────────────

function tmHandleClick(e) {
    if (e.target === tmOverlay) { closeTopologyManager(); return; }
    const btn = e.target.closest('[data-tm-action]');
    if (!btn || tm.busy) return;
    if (btn.getAttribute('aria-disabled') === 'true') {
        tmShowNotice(btn.dataset.reason || '');   // disabled on purpose: say why (also on touch screens)
        return;
    }
    const key = btn.dataset.key || '';
    switch (btn.dataset.tmAction) {
        case 'close':      closeTopologyManager(); break;
        case 'reload':     tmLoad(); break;
        case 'back':       tmBackToList(tm.form?.key || null); break;
        case 'zone-add':   tmOpenForm('zone'); break;
        case 'zone-edit':  tmOpenForm('zone', key); break;
        case 'sheet-add':  tmOpenForm('sheet', null, key); break;
        case 'sheet-edit': tmOpenForm('sheet', key); break;
        default:           tmRowAction(btn.dataset.tmAction, key);
    }
}

// Arrow keys move between zone / sheet rows (roving tabindex), Home / End jump, Enter on a row edits it
function tmHandleListKeys(e) {
    const row = e.target.closest?.('[data-tm-row]');
    if (!row || tm.view !== 'list') return;
    const rows = [...tmBody().querySelectorAll('[data-tm-row]')];
    const idx  = rows.indexOf(row);
    let next = -1;
    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = Math.min(rows.length - 1, idx + 1);
    else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = Math.max(0, idx - 1);
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = rows.length - 1;
    else if ((e.key === 'Enter' || e.key === ' ') && e.target === row) {
        e.preventDefault();
        tmOpenForm(row.dataset.kind, row.dataset.key);
        return;
    }
    if (next < 0) return;
    e.preventDefault();
    rows.forEach(r => { r.tabIndex = -1; });
    rows[next].tabIndex = 0;
    rows[next].focus();
}

function setupTopologyManager() {
    if (!tmOverlay) return;
    tmOverlay.addEventListener('click', tmHandleClick);
    tmOverlay.addEventListener('keydown', tmHandleListKeys);
    document.addEventListener('keydown', (e) => {
        if (!gmIsOpen(tmOverlay) || gmIsOpen(document.getElementById('topology-confirm'))) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            if (tm.view === 'form') tmBackToList(tm.form?.key || null);
            else closeTopologyManager();
        } else {
            gmTrapTab(e, tmOverlay);
        }
    });

    // admin.php sends the administrator back with ?manage=1: open the manager once, then drop the flag from the URL
    const url = new URL(window.location.href);
    if (url.searchParams.get('manage') === '1') {
        url.searchParams.delete('manage');
        window.history.replaceState(window.history.state, '', url.toString());
        openTopologyManager();
    }
}
document.addEventListener('DOMContentLoaded', setupTopologyManager);
