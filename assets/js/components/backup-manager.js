/**
 * Data Backup & Restore Manager ("Data Time-Machine", administrators only) — Lite-Epic Glass · HyperOS Edition
 * Provides Git-like snapshot commit, diff inspection, atomic restore (with auto pre-restore safety backup),
 * configurable monthly auto-backup retention (1..60 months, default 12 months), and signed .json export/import.
 *
 * Markup: components/backup-manager.php · CSS: assets/css/components/backup-manager.css · API: api/backup.php
 */

const bmOverlay = document.getElementById('backup-manager');
const bm = {
    data: null,
    view: 'list', // 'list' | 'diff'
    diffData: null,
    filter: 'all', // 'all' | 'monthly_auto' | 'manual' | 'pre_restore'
    confirmDeleteId: 0,
    busy: false,
    opener: null,
    notice: '',
};

const bmBody = () => document.getElementById('bm-body');
const bmActions = () => document.getElementById('bm-actions');

function bmAnnounce(text) {
    const live = document.getElementById('bm-live');
    if (live) {
        live.textContent = '';
        setTimeout(() => { live.textContent = text; }, 30);
    }
}

function bmSetStatus(mode, info) {
    const m = document.getElementById('bm-mode-text');
    const i = document.getElementById('bm-st-info');
    if (m) m.textContent = mode;
    if (i) i.textContent = info;
}

function bmUpdateHeaderStepper() {
    const sList = document.getElementById('bm-step-list');
    const sDiff = document.getElementById('bm-step-diff');
    if (!sList || !sDiff) return;

    if (bm.view === 'diff' && bm.diffData) {
        sList.classList.remove('is-active');
        sDiff.classList.add('is-active');
        sDiff.disabled = false;
    } else {
        sList.classList.add('is-active');
        sDiff.classList.remove('is-active');
        sDiff.disabled = !bm.diffData;
    }
}

function bmUpdateLivePill(settings) {
    const pill = document.getElementById('bm-live-pill');
    const txt = document.getElementById('bm-live-pill-text');
    if (!pill || !txt || !settings) return;
    const enabled = !!settings.auto_monthly_enabled;
    const months = Number(settings.retention_months || 12);
    pill.classList.toggle('is-off', !enabled);
    txt.textContent = enabled
        ? `Auto Monthly · Keep ${months}m`
        : 'Auto Monthly Paused';
}

function bmShowNotice(msg) {
    bm.notice = msg || '';
    const el = document.getElementById('bm-notice');
    if (!el) return;
    if (!bm.notice) {
        el.hidden = true;
        return;
    }
    const span = el.querySelector('span');
    if (span) span.textContent = bm.notice;
    el.hidden = false;
}

function openBackupManager() {
    if (!bmOverlay) return;
    bm.opener = document.activeElement;
    bm.notice = '';
    bm.view = 'list';
    bm.confirmDeleteId = 0;
    gmShow(bmOverlay);
    bmUpdateHeaderStepper();
    bmLoad();
}

function closeBackupManager() {
    if (!bmOverlay || !gmIsOpen(bmOverlay)) return;
    gmHide(bmOverlay);
    bm.view = 'list';
    bm.diffData = null;
    bm.confirmDeleteId = 0;
    bm.opener?.focus?.({ preventScroll: true });
}

async function bmLoad() {
    const body = bmBody();
    const act = bmActions();
    if (!body || !act) return;

    bm.view = 'list';
    bm.diffData = null;
    bm.confirmDeleteId = 0;
    bmUpdateHeaderStepper();
    bmSetStatus('DATA TIME-MACHINE', 'syncing snapshots...');
    body.setAttribute('aria-busy', 'true');
    body.innerHTML = `
        <div class="bm-loading-state" role="status">
            <div class="bm-loading-orb">
                <i class="fa-solid fa-clock-rotate-left fa-spin" aria-hidden="true"></i>
            </div>
            <b>กำลังซิงค์ไทม์ไลน์จุดสำรองข้อมูล...</b>
            <span>Loading Data Time-Machine snapshots &amp; retention policy</span>
        </div>
    `;
    act.innerHTML = `
        <button type="button" class="gm-btn ghost" data-bm-action="close">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i><span>ปิดหน้าต่าง / Close</span>
        </button>
    `;

    try {
        const res = await fetch('api/backup.php?mode=list', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.success) {
            const msg = (json.errors && json.errors[0]) || 'ไม่สามารถโหลดข้อมูลจุดสำรองได้ กรุณาลองใหม่อีกครั้ง';
            body.innerHTML = `
                <div class="gm-error" role="alert">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <div><b>โหลดข้อมูลไม่สำเร็จ</b><p style="margin:3px 0 0">${h(msg)}</p></div>
                </div>
            `;
            act.innerHTML = `
                <button type="button" class="gm-btn ghost" data-bm-action="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>ปิด / Close</span></button>
                <button type="button" class="gm-btn primary" data-bm-action="reload"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>ลองใหม่ / Retry</span></button>
            `;
            body.setAttribute('aria-busy', 'false');
            return;
        }

        bm.data = json.data;
        body.setAttribute('aria-busy', 'false');
        bmUpdateLivePill(bm.data?.settings);
        bmRenderList();
    } catch (err) {
        console.error(err);
        body.setAttribute('aria-busy', 'false');
        body.innerHTML = `
            <div class="gm-error" role="alert">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div><b>เชื่อมต่อเซิร์ฟเวอร์ไม่ได้</b><p style="margin:3px 0 0">กรุณาตรวจสอบการเชื่อมต่อเครือข่ายแล้วลองใหม่</p></div>
            </div>
        `;
    }
}

function bmRenderList() {
    const body = bmBody();
    const act = bmActions();
    if (!body || !act || !bm.data) return;

    bm.view = 'list';
    bmUpdateHeaderStepper();

    const cur = bm.data.current || {};
    const statusCounts = cur.status_counts || { G: 0, D: 0, GD: 0 };
    const totalBoards = Number(cur.board_count || 0);
    const pctG = totalBoards > 0 ? Math.round((Number(statusCounts.G || 0) / totalBoards) * 100) : 0;
    const pctD = totalBoards > 0 ? Math.round((Number(statusCounts.D || 0) / totalBoards) * 100) : 0;
    const pctGD = totalBoards > 0 ? Math.max(0, 100 - pctG - pctD) : 0;

    const settings = bm.data.settings || { retention_months: 12, auto_monthly_enabled: true, options: [1, 3, 6, 12, 18, 24, 36, 60] };
    const counts = bm.data.counts || { total: 0, monthly_auto: 0, manual: 0, pre_restore: 0 };
    const allSnapshots = bm.data.snapshots || [];

    bmUpdateLivePill(settings);

    const filtered = allSnapshots.filter((s) => {
        if (bm.filter === 'all') return true;
        if (bm.filter === 'manual') return s.snapshot_type === 'manual' || s.snapshot_type === 'uploaded';
        return s.snapshot_type === bm.filter;
    });

    const retMonths = Number(settings.retention_months || 12);
    const optionValues = Array.isArray(settings.options) && settings.options.length
        ? [...settings.options]
        : [1, 3, 6, 12, 18, 24, 36, 60];
    if (!optionValues.includes(retMonths)) {
        optionValues.push(retMonths);
        optionValues.sort((a, b) => a - b);
    }

    const retOptionsHtml = optionValues.map((m) => {
        const sel = m === retMonths ? ' selected' : '';
        const rec = m === 12 ? ' (แนะนำ)' : '';
        return `<option value="${m}"${sel}>เก็บย้อนหลัง ${m} เดือน${rec} / Keep ${m} mo</option>`;
    }).join('');

    const quickPills = [3, 6, 12, 24, 36].map((m) => {
        const active = m === retMonths ? ' is-active' : '';
        return `<button type="button" class="bm-quick-pill${active}" data-bm-action="quick-retention" data-months="${m}">${m} เดือน</button>`;
    }).join('');

    const autoUsagePct = Math.min(100, Math.round((Number(counts.monthly_auto || 0) / Math.max(1, retMonths)) * 100));

    bmSetStatus(
        'DATA TIME-MACHINE',
        `${counts.total} snapshots · auto keep ${retMonths}m`
    );

    const newestId = allSnapshots.length > 0 ? Number(allSnapshots[0].id) : 0;

    const listHtml = filtered.length === 0
        ? `<div class="bm-empty-card">
             <div class="bm-empty-ico"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></div>
             <b>ยังไม่มีจุดสำรองข้อมูลในหมวดนี้ / No snapshots in this category</b>
             <span>คุณสามารถพิมพ์ชื่อและกดปุ่ม <b>"สร้างจุดสำรองตอนนี้ (Commit)"</b> ด้านบนเพื่อถ่ายสำเนาฐานข้อมูลปัจจุบันได้ทันที</span>
           </div>`
        : `<ul class="bm-list">
            ${filtered.map((s) => {
                const codeText = s.last_number_code ? h(s.last_number_code) : '—';
                const badgeIcon = s.snapshot_type === 'monthly_auto'
                    ? 'fa-calendar-check'
                    : (s.snapshot_type === 'pre_restore' ? 'fa-shield-heart' : (s.snapshot_type === 'uploaded' ? 'fa-file-import' : 'fa-bookmark'));
                const isLatest = Number(s.id) === newestId;
                const isConfirmingDelete = bm.confirmDeleteId === Number(s.id);

                return `
                <li class="bm-item is-${h(s.snapshot_type)}" data-bm-id="${s.id}">
                    <span class="bm-rail" aria-hidden="true">
                        <i class="fa-solid ${badgeIcon}"></i>
                    </span>
                    <div class="bm-item-main">
                        <div class="bm-item-title-row">
                            <span class="bm-badge is-${h(s.snapshot_type)}">
                                <i class="fa-solid ${badgeIcon}" aria-hidden="true"></i>
                                ${h(s.type_label)}
                            </span>
                            ${isLatest ? '<span class="bm-latest-tag"><i class="fa-solid fa-sparkles" aria-hidden="true"></i> ล่าสุด (LATEST)</span>' : ''}
                            <span class="bm-item-title">${h(s.title)}</span>
                            <span class="bm-hash" title="HMAC-SHA256 Checksum: ${h(s.checksum)}"><i class="fa-solid fa-fingerprint" aria-hidden="true"></i> ${h(s.short_hash)}</span>
                        </div>
                        <div class="bm-item-meta">
                            <span class="bm-meta-chip"><i class="fa-regular fa-clock" aria-hidden="true"></i> <b>${h(s.created_at)}</b> · ${h(s.period_label)}</span>
                            <span class="bm-meta-chip is-emerald"><i class="fa-solid fa-microchip" aria-hidden="true"></i> บอร์ด: <b>${Number(s.board_count).toLocaleString()}</b></span>
                            <span class="bm-meta-chip is-indigo"><i class="fa-solid fa-barcode" aria-hidden="true"></i> รหัสล่าสุด: <b>${codeText}</b></span>
                            <span class="bm-meta-chip"><i class="fa-solid fa-list-check" aria-hidden="true"></i> โปรแกรม: <b>${Number(s.program_count).toLocaleString()}</b></span>
                        </div>
                    </div>
                    <div class="bm-item-actions">
                        ${isConfirmingDelete ? `
                            <div class="bm-inline-confirm" role="group" aria-label="ยืนยันการลบจุดสำรอง">
                                <span class="bm-inline-confirm-text">ยืนยันลบ?</span>
                                <button type="button" class="gm-btn danger tm-btn-sm" data-bm-action="confirm-delete" data-id="${s.id}">
                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span>ลบจริง</span>
                                </button>
                                <button type="button" class="gm-btn ghost tm-btn-sm" data-bm-action="cancel-delete">
                                    <span>ยกเลิก</span>
                                </button>
                            </div>
                        ` : `
                            <button type="button" class="bm-diff-cta" data-bm-action="diff" data-id="${s.id}" title="เปรียบเทียบความต่าง (Diff) และย้อนข้อมูลกลับไปยังจุดนี้">
                                <i class="fa-solid fa-code-compare" aria-hidden="true"></i>
                                <span>เปรียบเทียบ / กู้คืน</span>
                            </button>
                            <button type="button" class="tm-icon-btn bm-dl-btn" data-bm-action="download" data-id="${s.id}" title="ดาวน์โหลดไฟล์สำรองข้อมูล (.json) เก็บไว้ในเครื่อง">
                                <i class="fa-solid fa-download" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tm-icon-btn is-danger" data-bm-action="ask-delete" data-id="${s.id}" title="ลบจุดสำรองนี้">
                                <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                            </button>
                        `}
                    </div>
                </li>`;
            }).join('')}
           </ul>`;

    body.innerHTML = `
        <div id="bm-notice" class="tm-notice bm-notice-banner" role="status"${bm.notice ? '' : ' hidden'}>
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>${h(bm.notice)}</span>
        </div>

        <div class="bm-top-grid">
            <!-- Left Bento Card: Live Database Telemetry -->
            <section class="bm-card is-telemetry" aria-label="สถานะข้อมูลปัจจุบัน">
                <div class="bm-card-head">
                    <h4 class="bm-card-title">
                        <span class="bm-title-ico is-emerald"><i class="fa-solid fa-database" aria-hidden="true"></i></span>
                        <span>ข้อมูลในระบบปัจจุบัน (Live Database)</span>
                    </h4>
                    <span class="bm-head-chip"><i class="bm-pulse" aria-hidden="true"></i>HEAD · ปัจจุบัน</span>
                </div>
                <div class="bm-stats-row">
                    <div class="bm-stat-box is-emerald">
                        <div class="bm-stat-label"><i class="fa-solid fa-microchip" aria-hidden="true"></i> บอร์ดทั้งหมด</div>
                        <div class="bm-stat-val">${totalBoards.toLocaleString()}</div>
                    </div>
                    <div class="bm-stat-box is-indigo">
                        <div class="bm-stat-label"><i class="fa-solid fa-barcode" aria-hidden="true"></i> รหัสล่าสุด</div>
                        <div class="bm-stat-val is-code" title="${h(cur.last_number_code || '—')}">${h(cur.last_number_code || '—')}</div>
                    </div>
                    <div class="bm-stat-box is-amber">
                        <div class="bm-stat-label"><i class="fa-solid fa-list-check" aria-hidden="true"></i> โปรแกรม AOI</div>
                        <div class="bm-stat-val">${Number(cur.program_count || 0).toLocaleString()}</div>
                    </div>
                    <div class="bm-stat-box is-sky">
                        <div class="bm-stat-label"><i class="fa-solid fa-sitemap" aria-hidden="true"></i> โซน / ชีต</div>
                        <div class="bm-stat-val">${Number(cur.zone_count || 0)} / ${Number(cur.sheet_count || 0)}</div>
                    </div>
                </div>
                <div class="bm-mini-bar-wrap" title="สัดส่วนสถานะบอร์ดปัจจุบัน: G ${statusCounts.G || 0} · D ${statusCounts.D || 0} · G+D ${statusCounts.GD || 0}">
                    <div class="bm-mini-bar">
                        <span class="bm-seg-g" style="width:${pctG}%"></span>
                        <span class="bm-seg-gd" style="width:${pctGD}%"></span>
                        <span class="bm-seg-d" style="width:${pctD}%"></span>
                    </div>
                    <div class="bm-mini-legend">
                        <span><i class="bm-dot is-g"></i>G: <b>${Number(statusCounts.G || 0).toLocaleString()}</b></span>
                        <span><i class="bm-dot is-gd"></i>G+D: <b>${Number(statusCounts.GD || 0).toLocaleString()}</b></span>
                        <span><i class="bm-dot is-d"></i>D: <b>${Number(statusCounts.D || 0).toLocaleString()}</b></span>
                    </div>
                </div>
            </section>

            <!-- Right Bento Card: Monthly Auto-Backup & Retention Engine -->
            <section class="bm-card is-retention" aria-label="ตั้งค่าจำนวนเดือนสูงสุดที่เก็บ Auto Backup">
                <div class="bm-card-head">
                    <h4 class="bm-card-title">
                        <span class="bm-title-ico is-indigo"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                        <span>ตั้งค่าเก็บ Auto Backup รายเดือน</span>
                    </h4>
                    <label class="bm-switch-label" for="bm-auto-enabled">
                        <input type="checkbox" id="bm-auto-enabled" class="bm-switch-input" ${settings.auto_monthly_enabled ? 'checked' : ''}>
                        <span class="bm-switch-track" aria-hidden="true"><span class="bm-switch-thumb"></span></span>
                        <span class="bm-switch-text">สำรองอัตโนมัติทุกเดือน</span>
                    </label>
                </div>

                <div class="bm-policy-controls">
                    <div class="bm-select-wrap">
                        <i class="fa-solid fa-clock-rotate-left bm-select-ico" aria-hidden="true"></i>
                        <select id="bm-retention-months" class="bm-select" aria-label="จำนวนเดือนสูงสุดที่เก็บ Auto Backup">
                            ${retOptionsHtml}
                        </select>
                    </div>
                    <button type="button" class="gm-btn primary tm-btn-sm bm-save-policy-btn" data-bm-action="save-settings">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        <span>บันทึกตั้งค่า</span>
                    </button>
                </div>

                <div class="bm-quick-row">
                    <span class="bm-quick-label">เลือกด่วน:</span>
                    <div class="bm-quick-pills">${quickPills}</div>
                    <span class="bm-slot-usage" title="จำนวน Auto Backup รายเดือนที่เก็บอยู่ในขณะนี้เทียบกับเพดานสูงสุด">
                        เก็บอยู่ <b>${counts.monthly_auto} / ${retMonths}</b> เดือน (${autoUsagePct}%)
                    </span>
                </div>

                <p class="bm-policy-hint">
                    <i class="fa-solid fa-shield-check" aria-hidden="true"></i>
                    ถ่ายสำเนาอัตโนมัติเดือนละ 1 ครั้ง และลบเฉพาะ Auto Backup ที่เก่าเกินกำหนด (Manual Checkpoint จะไม่ถูกลบ)
                </p>
            </section>
        </div>

        <!-- Spotlight Command Bar: Manual Commit + JSON Import -->
        <div class="bm-action-bar">
            <div class="bm-commit-group">
                <div class="bm-input-shell">
                    <i class="fa-solid fa-code-commit bm-input-ico" aria-hidden="true"></i>
                    <input type="text" id="bm-checkpoint-title" class="bm-text-input" maxlength="120"
                           placeholder="ตั้งชื่อจุดสำรอง (Commit Message) เช่น ก่อน Import ล็อตใหญ่ หรือ ก่อนปรับปรุงข้อมูลประจำเดือน...">
                </div>
                <button type="button" class="gm-btn primary tm-btn-sm bm-commit-btn" data-bm-action="create-snapshot">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>สร้างจุดสำรองตอนนี้ (Commit)</span>
                </button>
            </div>
            <div class="bm-upload-group">
                <button type="button" class="gm-btn ghost tm-btn-sm bm-upload-btn" data-bm-action="trigger-upload" title="อัปโหลดไฟล์สำรองข้อมูล (.json) ที่เคยดาวน์โหลดไว้เข้าสู่ไทม์ไลน์">
                    <i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i>
                    <span>นำเข้าไฟล์ .json</span>
                </button>
            </div>
        </div>

        <!-- Segmented Filter Bar -->
        <div class="bm-filter-bar">
            <div class="bm-tabs" role="tablist" aria-label="ตัวกรองประเภทจุดสำรอง">
                <button type="button" class="bm-tab ${bm.filter === 'all' ? 'is-active' : ''}" data-bm-action="filter" data-filter="all">
                    <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                    <span>ทั้งหมด</span>
                    <b class="bm-tab-cnt">${counts.total}</b>
                </button>
                <button type="button" class="bm-tab ${bm.filter === 'monthly_auto' ? 'is-active' : ''}" data-bm-action="filter" data-filter="monthly_auto">
                    <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
                    <span>Auto รายเดือน</span>
                    <b class="bm-tab-cnt">${counts.monthly_auto}</b>
                </button>
                <button type="button" class="bm-tab ${bm.filter === 'manual' ? 'is-active' : ''}" data-bm-action="filter" data-filter="manual">
                    <i class="fa-solid fa-bookmark" aria-hidden="true"></i>
                    <span>สร้างเอง / นำเข้า</span>
                    <b class="bm-tab-cnt">${counts.manual}</b>
                </button>
                <button type="button" class="bm-tab ${bm.filter === 'pre_restore' ? 'is-active' : ''}" data-bm-action="filter" data-filter="pre_restore">
                    <i class="fa-solid fa-shield-heart" aria-hidden="true"></i>
                    <span>สำรองก่อนกู้คืน</span>
                    <b class="bm-tab-cnt">${counts.pre_restore}</b>
                </button>
            </div>
            <span class="bm-list-counter">แสดง <b>${filtered.length}</b> จาก <b>${counts.total}</b> จุดสำรอง</span>
        </div>

        ${listHtml}
    `;

    act.innerHTML = `
        <button type="button" class="gm-btn ghost" data-bm-action="close">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i><span>ปิดหน้าต่าง / Close</span>
        </button>
    `;
}

async function bmOpenDiff(snapshotId) {
    const body = bmBody();
    const act = bmActions();
    if (!body || !act) return;

    bm.busy = true;
    body.classList.add('is-busy');
    body.setAttribute('aria-busy', 'true');

    try {
        const res = await fetch(`api/backup.php?mode=diff&id=${encodeURIComponent(snapshotId)}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.success) {
            const err = (json.errors && json.errors[0]) || 'ไม่สามารถเปรียบเทียบจุดสำรองได้';
            showFlash('err', err);
            bmShowNotice(err);
            return;
        }
        bm.diffData = json.data;
        bm.view = 'diff';
        bmUpdateHeaderStepper();
        bmRenderDiff();
    } catch (err) {
        console.error(err);
        showFlash('err', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่');
    } finally {
        bm.busy = false;
        body.classList.remove('is-busy');
        body.setAttribute('aria-busy', 'false');
    }
}

function bmRenderDiff() {
    const body = bmBody();
    const act = bmActions();
    if (!body || !act || !bm.diffData) return;

    bmUpdateHeaderStepper();

    const d = bm.diffData;
    const snap = d.snapshot;
    const cur = d.current;
    const bDiff = d.boards_diff || {};
    const pDiff = d.programs_diff || {};
    const magDiff = d.magazines_diff || [];

    const restoreBackCount = Number(bDiff.deleted_since_snapshot_count || 0);
    const revertEditCount = Number(bDiff.modified_since_snapshot_count || 0);
    const removeNewCount = Number(bDiff.added_since_snapshot_count || 0);

    bmSetStatus('DIFF & RESTORE', `snapshot #${snap.id} (${snap.period_ym})`);

    const rowsHtml = [];
    for (const r of (bDiff.deleted_since_snapshot || [])) {
        rowsHtml.push(`
            <tr>
                <td><span class="bm-badge is-monthly_auto"><i class="fa-solid fa-plus" aria-hidden="true"></i> กู้คืนกลับมา</span></td>
                <td><code class="bm-code-pill">${h(r.code)}</code></td>
                <td><b>${h(r.sheet)}</b> · <span class="bm-shelf-tag">${h(r.shelf)}</span></td>
                <td>${h(r.product)}</td>
                <td>ถูกลบไปหลังจุดสำรองนี้ → จะถูกนำกลับมา (${h(r.status || '—')} / ${h(r.side || '')})</td>
            </tr>
        `);
    }
    for (const r of (bDiff.modified_since_snapshot || [])) {
        const changeParts = Object.entries(r.changes || {}).map(([field, vals]) => {
            return `${field}: "${vals.current || '—'}" → "${vals.snapshot || '—'}"`;
        });
        rowsHtml.push(`
            <tr>
                <td><span class="bm-badge is-pre_restore"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> ย้อนค่ากลับ</span></td>
                <td><code class="bm-code-pill">${h(r.code)}</code></td>
                <td><b>${h(r.sheet)}</b> · <span class="bm-shelf-tag">${h(r.shelf)}</span></td>
                <td>${h(r.product)}</td>
                <td>${h(changeParts.join(' · '))}</td>
            </tr>
        `);
    }
    for (const r of (bDiff.added_since_snapshot || [])) {
        rowsHtml.push(`
            <tr>
                <td><span class="bm-badge is-rose"><i class="fa-solid fa-minus" aria-hidden="true"></i> ถอดบอร์ดใหม่ออก</span></td>
                <td><code class="bm-code-pill">${h(r.code)}</code></td>
                <td><b>${h(r.sheet)}</b> · <span class="bm-shelf-tag">${h(r.shelf)}</span></td>
                <td>${h(r.product)}</td>
                <td>ถูกเพิ่มหลังจุดสำรองนี้ → จะถูกถอดออกหากย้อนกลับไปจุดนี้</td>
            </tr>
        `);
    }

    const diffTableSection = rowsHtml.length > 0
        ? `<div class="bm-diff-table-wrap">
             <table class="bm-diff-table">
               <thead>
                 <tr>
                   <th>การเปลี่ยนแปลงเมื่อกู้คืน</th>
                   <th>Number Code</th>
                   <th>Sheet · Shelf</th>
                   <th>Product Model</th>
                   <th>รายละเอียดการย้อนค่า (ปัจจุบัน → จุดสำรอง)</th>
                 </tr>
               </thead>
               <tbody>${rowsHtml.join('')}</tbody>
             </table>
           </div>`
        : `<div class="bm-empty-card is-compact">
             <div class="bm-empty-ico is-emerald"><i class="fa-solid fa-check-double" aria-hidden="true"></i></div>
             <b>${d.is_identical ? 'ข้อมูลบอร์ดในระบบปัจจุบันตรงกับจุดสำรองนี้ทุกประการ (ไม่พบความต่าง)' : 'ข้อมูลบอร์ดตรงกันทั้งหมด (มีความต่างเฉพาะตารางโปรแกรมหรือ Magazine)'}</b>
             <span>คุณยังสามารถกดยืนยันย้อนข้อมูลเพื่อซิงค์ตารางทั้งหมดให้ตรงกับจุดสำรองนี้ได้</span>
           </div>`;

    const extraDiffNotes = [];
    if (magDiff.length > 0) {
        extraDiffNotes.push(`Magazine เปลี่ยนแปลง ${magDiff.length} ชีต`);
    }
    if (pDiff.added_since_snapshot_count || pDiff.deleted_since_snapshot_count || pDiff.modified_since_snapshot_count) {
        extraDiffNotes.push(`โปรแกรม AOI: กู้คืน +${pDiff.deleted_since_snapshot_count}, ย้อนค่า ~${pDiff.modified_since_snapshot_count}, ถอดออก -${pDiff.added_since_snapshot_count}`);
    }

    body.innerHTML = `
        <div class="bm-diff-box">
            <!-- Comparison Header Banner -->
            <div class="bm-diff-header">
                <div class="bm-diff-head-main">
                    <div class="bm-item-title-row">
                        <span class="bm-badge is-${h(snap.snapshot_type)}">${h(snap.type_label)}</span>
                        <span class="bm-hash"><i class="fa-solid fa-fingerprint" aria-hidden="true"></i> #${h(snap.short_hash)}</span>
                        <span class="bm-meta-chip"><i class="fa-regular fa-clock" aria-hidden="true"></i> ${h(snap.created_at)} (${h(snap.period_label)})</span>
                    </div>
                    <h4 class="bm-diff-title">${h(snap.title)}</h4>
                </div>

                <!-- HEAD -> Target Transition Pill -->
                <div class="bm-transition-box">
                    <div class="bm-trans-node">
                        <span class="bm-trans-lbl">ปัจจุบัน (HEAD)</span>
                        <b>${Number(cur.board_count || 0).toLocaleString()} บอร์ด</b>
                        <code>${h(cur.last_number_code || '—')}</code>
                    </div>
                    <div class="bm-trans-arrow" aria-hidden="true">
                        <i class="fa-solid fa-arrow-right-long"></i>
                    </div>
                    <div class="bm-trans-node is-target">
                        <span class="bm-trans-lbl">หลังย้อนข้อมูล (Target)</span>
                        <b>${Number(snap.board_count || 0).toLocaleString()} บอร์ด</b>
                        <code>${h(snap.last_number_code || '—')}</code>
                    </div>
                </div>
            </div>

            <!-- 3 Diff KPI Cards -->
            <div class="bm-diff-summary-grid">
                <div class="bm-diff-stat is-restore-back">
                    <div class="bm-diff-stat-top">
                        <span class="bm-diff-ico"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></span>
                        <span class="bm-diff-stat-num">+${restoreBackCount.toLocaleString()} บอร์ด</span>
                    </div>
                    <div class="bm-diff-stat-title">กู้คืนบอร์ดที่ถูกลบกลับมา</div>
                    <div class="bm-diff-stat-desc">มีอยู่ในจุดสำรองนี้ แต่ถูกลบไปในระบบปัจจุบัน</div>
                </div>
                <div class="bm-diff-stat is-revert-edit">
                    <div class="bm-diff-stat-top">
                        <span class="bm-diff-ico"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
                        <span class="bm-diff-stat-num">~${revertEditCount.toLocaleString()} บอร์ด</span>
                    </div>
                    <div class="bm-diff-stat-title">ย้อนค่าบอร์ดที่ถูกแก้ไข</div>
                    <div class="bm-diff-stat-desc">ย้อน Shelf, Status, Product หรือ Defects กลับตามจุดสำรอง</div>
                </div>
                <div class="bm-diff-stat is-remove-new">
                    <div class="bm-diff-stat-top">
                        <span class="bm-diff-ico"><i class="fa-solid fa-eraser" aria-hidden="true"></i></span>
                        <span class="bm-diff-stat-num">-${removeNewCount.toLocaleString()} บอร์ด</span>
                    </div>
                    <div class="bm-diff-stat-title">ถอดบอร์ดที่เพิ่งเพิ่มหลังจุดสำรอง</div>
                    <div class="bm-diff-stat-desc">บอร์ดที่ถูกบันทึกเพิ่มหลังเวลา ${h(snap.created_at)}</div>
                </div>
            </div>

            ${extraDiffNotes.length ? `<div class="bm-extra-diff"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <b>การเปลี่ยนแปลงตารางอื่น:</b> ${h(extraDiffNotes.join(' · '))}</div>` : ''}

            ${diffTableSection}

            <div class="bm-safety-banner">
                <div class="bm-safety-ico"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                <div>
                    <b>ระบบป้องกันข้อมูลสูญหายอัตโนมัติ (Auto Pre-Restore Safety Backup)</b>
                    <p style="margin:2px 0 0">ก่อนเขียนทับฐานข้อมูล ระบบจะถ่ายสำเนาข้อมูลปัจจุบันเก็บไว้ในหมวด <b>"สำรองก่อนกู้คืน"</b> ให้อัตโนมัติทุกครั้ง หากย้อนผิดสามารถกด Undo กลับมายังจุดเดิมได้ทันที</p>
                </div>
            </div>
        </div>
    `;

    act.innerHTML = `
        <button type="button" class="gm-btn ghost" data-bm-action="back">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span>กลับไปไทม์ไลน์ / Back to Timeline</span>
        </button>
        <button type="button" class="gm-btn primary" data-bm-action="confirm-restore" data-id="${snap.id}">
            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
            <span>ยืนยันย้อนข้อมูลกลับไปยังจุดนี้ / Restore Data Now</span>
        </button>
    `;
}

async function bmPost(payload) {
    const res = await fetch('api/backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ csrf: window.csrfToken, ...payload }),
    });
    const json = await res.json().catch(() => ({}));
    return { status: res.status, json };
}

async function bmSaveSettings(overrideMonths = null) {
    const sel = document.getElementById('bm-retention-months');
    const chk = document.getElementById('bm-auto-enabled');
    if (!sel && overrideMonths === null) return;

    const retentionMonths = overrideMonths !== null ? Number(overrideMonths) : (parseInt(sel.value, 10) || 12);
    const autoEnabled = chk ? chk.checked : true;

    const body = bmBody();
    bm.busy = true;
    body?.classList.add('is-busy');

    try {
        const { status, json } = await bmPost({
            action: 'update_settings',
            retention_months: retentionMonths,
            auto_monthly_enabled: autoEnabled,
        });
        if (status === 200 && json.success) {
            bm.data = json.data;
            bm.notice = json.message || '';
            showFlash('ok', json.message);
            bmAnnounce(json.message);
            bmRenderList();
        } else {
            const err = (json.errors && json.errors[0]) || 'บันทึกตั้งค่าไม่สำเร็จ';
            showFlash('err', err);
            bmShowNotice(err);
        }
    } catch (err) {
        console.error(err);
        showFlash('err', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    } finally {
        bm.busy = false;
        body?.classList.remove('is-busy');
    }
}

async function bmCreateSnapshot() {
    const inp = document.getElementById('bm-checkpoint-title');
    const title = inp ? inp.value.trim() : '';

    const body = bmBody();
    bm.busy = true;
    body?.classList.add('is-busy');

    try {
        const { status, json } = await bmPost({
            action: 'create',
            title,
        });
        if (status === 200 && json.success) {
            bm.data = json.data;
            bm.notice = json.message || '';
            showFlash('ok', json.message);
            bmAnnounce(json.message);
            bmRenderList();
        } else {
            const err = (json.errors && json.errors[0]) || 'สร้างจุดสำรองไม่สำเร็จ';
            showFlash('err', err);
            bmShowNotice(err);
        }
    } catch (err) {
        console.error(err);
        showFlash('err', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    } finally {
        bm.busy = false;
        body?.classList.remove('is-busy');
    }
}

async function bmConfirmRestore(snapshotId) {
    const body = bmBody();
    bm.busy = true;
    body?.classList.add('is-busy');

    try {
        const { status, json } = await bmPost({
            action: 'restore',
            id: Number(snapshotId),
        });
        if (status === 200 && json.success) {
            showFlash('ok', json.message);
            bmAnnounce(json.message);
            closeBackupManager();
            setTimeout(() => {
                window.location.reload();
            }, 650);
        } else {
            const err = (json.errors && json.errors[0]) || 'กู้คืนข้อมูลไม่สำเร็จ';
            showFlash('err', err);
        }
    } catch (err) {
        console.error(err);
        showFlash('err', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    } finally {
        bm.busy = false;
        body?.classList.remove('is-busy');
    }
}

async function bmDeleteSnapshot(snapshotId) {
    const body = bmBody();
    bm.busy = true;
    body?.classList.add('is-busy');

    try {
        const { status, json } = await bmPost({
            action: 'delete',
            id: Number(snapshotId),
        });
        bm.confirmDeleteId = 0;
        if (status === 200 && json.success) {
            bm.data = json.data;
            bm.notice = json.message || '';
            showFlash('ok', json.message);
            bmRenderList();
        } else {
            const err = (json.errors && json.errors[0]) || 'ลบจุดสำรองไม่สำเร็จ';
            showFlash('err', err);
        }
    } catch (err) {
        console.error(err);
        showFlash('err', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    } finally {
        bm.busy = false;
        body?.classList.remove('is-busy');
    }
}

async function bmHandleFileUpload(file) {
    if (!file) return;
    const body = bmBody();
    bm.busy = true;
    body?.classList.add('is-busy');

    try {
        const fd = new FormData();
        fd.append('csrf', window.csrfToken || '');
        fd.append('action', 'upload');
        fd.append('backup_file', file);

        const res = await fetch('api/backup.php', {
            method: 'POST',
            body: fd,
        });
        const json = await res.json().catch(() => ({}));
        if (res.ok && json.success) {
            bm.data = json.data;
            bm.filter = 'all';
            bm.notice = json.message || '';
            showFlash('ok', json.message);
            bmRenderList();
        } else {
            const err = (json.errors && json.errors[0]) || 'นำเข้าไฟล์สำรองไม่สำเร็จ';
            showFlash('err', err);
            bmShowNotice(err);
        }
    } catch (err) {
        console.error(err);
        showFlash('err', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    } finally {
        bm.busy = false;
        body?.classList.remove('is-busy');
    }
}

function bmHandleClick(e) {
    if (e.target === bmOverlay) {
        closeBackupManager();
        return;
    }
    const btn = e.target.closest('[data-bm-action]');
    if (!btn || bm.busy) return;

    const action = btn.dataset.bmAction;
    const id = btn.dataset.id ? Number(btn.dataset.id) : 0;

    switch (action) {
        case 'close':
            closeBackupManager();
            break;
        case 'reload':
            bmLoad();
            break;
        case 'back':
            bm.confirmDeleteId = 0;
            bmRenderList();
            break;
        case 'save-settings':
            bmSaveSettings();
            break;
        case 'quick-retention': {
            const m = Number(btn.dataset.months || 12);
            const sel = document.getElementById('bm-retention-months');
            if (sel) sel.value = String(m);
            bmSaveSettings(m);
            break;
        }
        case 'create-snapshot':
            bmCreateSnapshot();
            break;
        case 'trigger-upload': {
            const fileInput = document.getElementById('bm-upload-input');
            if (fileInput) {
                fileInput.value = '';
                fileInput.click();
            }
            break;
        }
        case 'filter':
            bm.filter = btn.dataset.filter || 'all';
            bm.confirmDeleteId = 0;
            bmRenderList();
            break;
        case 'diff':
            if (id > 0) bmOpenDiff(id);
            break;
        case 'confirm-restore':
            if (id > 0) bmConfirmRestore(id);
            break;
        case 'download':
            if (id > 0) {
                window.location.href = `api/backup.php?mode=download&id=${encodeURIComponent(id)}`;
            }
            break;
        case 'ask-delete':
            if (id > 0) {
                bm.confirmDeleteId = id;
                bmRenderList();
            }
            break;
        case 'cancel-delete':
            bm.confirmDeleteId = 0;
            bmRenderList();
            break;
        case 'confirm-delete':
            if (id > 0) bmDeleteSnapshot(id);
            break;
    }
}

function setupBackupManager() {
    if (!bmOverlay) return;
    bmOverlay.addEventListener('click', bmHandleClick);

    const uploadInput = document.getElementById('bm-upload-input');
    if (uploadInput) {
        uploadInput.addEventListener('change', (e) => {
            const file = e.target.files && e.target.files[0];
            if (file) bmHandleFileUpload(file);
        });
    }

    document.addEventListener('keydown', (e) => {
        if (!gmIsOpen(bmOverlay)) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            if (bm.confirmDeleteId > 0) {
                bm.confirmDeleteId = 0;
                bmRenderList();
            } else if (bm.view === 'diff') {
                bmRenderList();
            } else {
                closeBackupManager();
            }
        } else if (e.key === 'Enter' && e.target?.id === 'bm-checkpoint-title') {
            e.preventDefault();
            bmCreateSnapshot();
        } else {
            gmTrapTab(e, bmOverlay);
        }
    });

    const url = new URL(window.location.href);
    if (url.searchParams.get('backup') === '1') {
        url.searchParams.delete('backup');
        window.history.replaceState(window.history.state, '', url.toString());
        openBackupManager();
    }
}

document.addEventListener('DOMContentLoaded', setupBackupManager);
window.openBackupManager = openBackupManager;
window.closeBackupManager = closeBackupManager;
