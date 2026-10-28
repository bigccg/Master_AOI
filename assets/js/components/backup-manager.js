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
    draft: '', // commit message being typed, kept across re-renders
    noticeT: 0,
};

const bmBody = () => document.getElementById('bm-body');
const bmActions = () => document.getElementById('bm-actions');
const bmTimeline = () => document.getElementById('bm-timeline');

// ── Motion (HyperOS-style): decelerate with no overshoot, transform/opacity only so the compositor runs it ──
const BM_EASE = 'cubic-bezier(.22, 1, .36, 1)';

function bmEnter(el, { dy = 8, delay = 0, duration = 340 } = {}) {
    if (!el || GM_REDUCED || !el.animate) return;
    el.animate(
        [{ opacity: 0, transform: `translateY(${dy}px)` }, { opacity: 1, transform: 'none' }],
        { duration, delay, easing: BM_EASE, fill: 'backwards' }
    );
}

function bmLeave(el) {
    if (!el || GM_REDUCED || !el.animate) return Promise.resolve();
    return el.animate(
        [{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'scale(.97)' }],
        { duration: 180, easing: 'cubic-bezier(.4, 0, 1, 1)', fill: 'forwards' }
    ).finished.catch(() => {});
}

// FLIP: re-render the timeline, then glide every card that survived from its old spot to its new one
function bmFlip(render) {
    const before = new Map();
    document.querySelectorAll('#bm-timeline .bm-item').forEach((el) => {
        before.set(el.dataset.bmId, el.getBoundingClientRect().top);
    });
    render();
    if (GM_REDUCED) return;
    document.querySelectorAll('#bm-timeline .bm-item').forEach((el) => {
        const top = before.get(el.dataset.bmId);
        if (top === undefined) {
            bmEnter(el, { dy: -6 });
            return;
        }
        const dy = top - el.getBoundingClientRect().top;
        if (Math.abs(dy) > 0.5) {
            el.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 380, easing: BM_EASE });
        }
    });
}

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

// Floating result pill over the body: never shifts the layout, fades out on its own
function bmShowNotice(msg, type = 'ok') {
    const el = document.getElementById('bm-notice');
    if (!el || !msg) return;
    el.querySelector('span').textContent = msg;
    el.querySelector('i').className = `fa-solid ${type === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check'}`;
    el.classList.toggle('is-err', type === 'err');
    el.classList.add('is-on');
    clearTimeout(bm.noticeT);
    bm.noticeT = setTimeout(() => el.classList.remove('is-on'), type === 'err' ? 5200 : 3200);
}

function bmHideNotice() {
    clearTimeout(bm.noticeT);
    document.getElementById('bm-notice')?.classList.remove('is-on');
}

function openBackupManager() {
    if (!bmOverlay) return;
    bm.opener = document.activeElement;
    // The timeline from the last visit is still in the DOM: show it as-is (no re-render on the opening frame),
    // then refresh quietly in the background and re-render only if the data changed
    const reuse = !!(bm.data && bm.view === 'list' && bmTimeline());
    bm.diffData = null;
    bmHideNotice();
    if (reuse && bm.confirmDeleteId) {
        bm.confirmDeleteId = 0;
        bmRenderTimeline();
    }
    gmShow(bmOverlay);
    if (!reuse && bm.data) bmRenderList({ enter: true }); // closed from the diff view
    bmUpdateHeaderStepper();
    bmLoad({ silent: !!bm.data });
}

function closeBackupManager() {
    if (!bmOverlay || !gmIsOpen(bmOverlay)) return;
    bmKeepDraft();
    gmHide(bmOverlay);
    bm.opener?.focus?.({ preventScroll: true });
}

// Placeholder blocks shaped like the real timeline, so the window never resizes when the data lands
function bmSkeletonHtml() {
    const item = `
        <li class="bm-item is-sk">
            <span class="bm-rail"></span>
            <div class="bm-item-main"><i class="bm-sk-line" style="width:46%"></i><i class="bm-sk-line" style="width:72%"></i></div>
        </li>`;
    return `
        <div class="bm-skeleton" aria-hidden="true">
            <div class="bm-top-grid">
                <section class="bm-card is-sk"><i class="bm-sk-line" style="width:52%"></i><i class="bm-sk-block"></i></section>
                <section class="bm-card is-sk"><i class="bm-sk-line" style="width:44%"></i><i class="bm-sk-block"></i></section>
            </div>
            <div class="bm-action-bar is-sk"><i class="bm-sk-line" style="width:100%"></i></div>
            <div class="bm-timeline"><ul class="bm-list">${item.repeat(3)}</ul></div>
        </div>
        <p class="sr-only" role="status">กำลังซิงค์ไทม์ไลน์จุดสำรองข้อมูล... / Loading snapshots</p>
    `;
}

async function bmLoad({ silent = false } = {}) {
    const body = bmBody();
    const act = bmActions();
    if (!body || !act) return;

    if (!silent) {
        bm.view = 'list';
        bm.diffData = null;
        bm.confirmDeleteId = 0;
        bmUpdateHeaderStepper();
        body.setAttribute('aria-busy', 'true');
        body.innerHTML = bmSkeletonHtml();
        act.innerHTML = `
            <button type="button" class="gm-btn ghost" data-bm-action="close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i><span>ปิดหน้าต่าง / Close</span>
            </button>
        `;
    }
    bmSetStatus('DATA TIME-MACHINE', 'syncing snapshots...');

    try {
        const res = await fetch('api/backup.php?mode=list', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.success) {
            const msg = (json.errors && json.errors[0]) || 'ไม่สามารถโหลดข้อมูลจุดสำรองได้ กรุณาลองใหม่อีกครั้ง';
            if (silent) {
                bmShowNotice(msg, 'err');
                bmSetStatus('DATA TIME-MACHINE', 'offline · showing last snapshots');
                return;
            }
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

        const changed = JSON.stringify(json.data) !== JSON.stringify(bm.data);
        bm.data = json.data;
        body.setAttribute('aria-busy', 'false');
        bmUpdateLivePill(bm.data?.settings);
        if (!silent) {
            bmRenderList({ enter: true });
        } else if (bm.view === 'list') {
            if (changed) bmFlip(() => bmRenderList());
            else bmRenderStatus();
        }
    } catch (err) {
        console.error(err);
        body.setAttribute('aria-busy', 'false');
        if (silent) {
            bmSetStatus('DATA TIME-MACHINE', 'offline · showing last snapshots');
            return;
        }
        body.innerHTML = `
            <div class="gm-error" role="alert">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div><b>เชื่อมต่อเซิร์ฟเวอร์ไม่ได้</b><p style="margin:3px 0 0">กรุณาตรวจสอบการเชื่อมต่อเครือข่ายแล้วลองใหม่</p></div>
            </div>
        `;
        act.innerHTML = `
            <button type="button" class="gm-btn ghost" data-bm-action="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>ปิด / Close</span></button>
            <button type="button" class="gm-btn primary" data-bm-action="reload"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>ลองใหม่ / Retry</span></button>
        `;
    }
}

function bmKeepDraft() {
    const inp = document.getElementById('bm-checkpoint-title');
    if (inp) bm.draft = inp.value;
}

function bmClearTitle() {
    const inp = document.getElementById('bm-checkpoint-title');
    if (!inp) return;
    inp.value = '';
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    inp.focus();
}

function bmSelectOpen(sel) {
    try {
        return sel.matches(':open');
    } catch {
        return false; // browsers without :open keep the native picker, which never sees page key events
    }
}

function bmRenderStatus() {
    const counts = bm.data?.counts || { total: 0 };
    const retMonths = Number(bm.data?.settings?.retention_months || 12);
    bmSetStatus('DATA TIME-MACHINE', `${counts.total} snapshots · auto keep ${retMonths}m`);
}

// Slide the segmented-control highlight under the active tab. It is sized instantly and glides with a
// transform-only FLIP (old spot + width ratio -> new spot), so the compositor runs it without repaints.
// Measured in rAF: reading layout right after innerHTML would force an extra full style pass while the
// Tailwind Play CDN is re-scanning the new markup.
function bmSyncTabs(animate = false) {
    const tabs = document.querySelector('#bm-body .bm-tabs');
    if (!tabs) return;
    let active = null;
    tabs.querySelectorAll('.bm-tab').forEach((t) => {
        const on = t.dataset.filter === bm.filter;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) active = t;
    });
    const glider = tabs.querySelector('.bm-tab-glider');
    if (!glider || !active) return;
    requestAnimationFrame(() => {
        if (!glider.isConnected) return;
        const to = { x: active.offsetLeft, y: active.offsetTop, w: active.offsetWidth, h: active.offsetHeight };
        const from = glider._bmAt;
        glider.style.width = `${to.w}px`;
        glider.style.height = `${to.h}px`;
        glider.style.transform = `translate3d(${to.x}px, ${to.y}px, 0)`;
        glider._bmAt = to;
        if (!animate || !from || GM_REDUCED || !glider.animate) return;
        glider.animate(
            [{ transform: `translate3d(${from.x}px, ${from.y}px, 0) scaleX(${from.w / to.w})` }, { transform: `translate3d(${to.x}px, ${to.y}px, 0)` }],
            { duration: 420, easing: BM_EASE }
        );
    });
}

// Timeline cards only (filter switch, delete confirm): leaves the cards, inputs and tabs above untouched
function bmRenderTimeline({ stagger = false, resetScroll = false } = {}) {
    const box = bmTimeline();
    if (!box || !bm.data) return;

    const counts = bm.data.counts || { total: 0 };
    const allSnapshots = bm.data.snapshots || [];
    const filtered = allSnapshots.filter((s) => {
        if (bm.filter === 'all') return true;
        if (bm.filter === 'manual') return s.snapshot_type === 'manual' || s.snapshot_type === 'uploaded';
        return s.snapshot_type === bm.filter;
    });
    const newestId = allSnapshots.length > 0 ? Number(allSnapshots[0].id) : 0;

    // reset while the layout is still clean: touching scrollTop after the new markup forces a sync layout
    if (resetScroll && box.scrollTop) box.scrollTop = 0;

    const counter = document.getElementById('bm-list-counter');
    if (counter) counter.innerHTML = `แสดง <b>${filtered.length}</b> จาก <b>${counts.total}</b> จุดสำรอง`;

    box.innerHTML = filtered.length === 0
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
                <li class="bm-item is-${h(s.snapshot_type)}${isConfirmingDelete ? ' is-confirming' : ''}" data-bm-id="${s.id}">
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

    if (stagger) {
        box.querySelectorAll('.bm-item, .bm-empty-card').forEach((el, i) => {
            if (i < 8) bmEnter(el, { dy: 10, delay: i * 28, duration: 360 });
        });
    }
}

function bmRenderList({ enter = false } = {}) {
    const body = bmBody();
    const act = bmActions();
    if (!body || !act || !bm.data) return;

    bmKeepDraft();
    const keepScroll = enter ? 0 : (bmTimeline()?.scrollTop || 0);
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

    bmUpdateLivePill(settings);

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
        return `<option value="${m}"${sel}>${m} เดือน${rec} / Keep ${m} mo</option>`;
    }).join('');

    const quickPills = [3, 6, 12, 24, 36].map((m) => {
        const active = m === retMonths ? ' is-active' : '';
        return `<button type="button" class="bm-quick-pill${active}" data-bm-action="quick-retention" data-months="${m}">${m} เดือน</button>`;
    }).join('');

    const autoUsagePct = Math.min(100, Math.round((Number(counts.monthly_auto || 0) / Math.max(1, retMonths)) * 100));

    bmRenderStatus();

    const tab = (filter, icon, label, n) => `
                <button type="button" class="bm-tab" role="tab" data-bm-action="filter" data-filter="${filter}" aria-controls="bm-timeline">
                    <i class="fa-solid ${icon}" aria-hidden="true"></i>
                    <span>${label}</span>
                    <b class="bm-tab-cnt">${n}</b>
                </button>`;

    body.innerHTML = `
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
                    <label for="bm-retention-months" class="bm-field-label">
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                        <span>เก็บย้อนหลัง</span>
                    </label>
                    <select id="bm-retention-months" aria-label="จำนวนเดือนสูงสุดที่เก็บ Auto Backup">
                        ${retOptionsHtml}
                    </select>
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
                <!-- Same glass bar as the page search (components/search.css) -->
                <div class="aoi-search-glass bm-commit-field${bm.draft ? ' has-value' : ''}">
                    <span class="aoi-search-icon" aria-hidden="true"><i class="fa-solid fa-code-commit"></i></span>
                    <input type="text" id="bm-checkpoint-title" maxlength="120" value="${h(bm.draft)}"
                           autocomplete="off" spellcheck="false" enterkeyhint="done"
                           aria-label="ชื่อจุดสำรอง (Commit Message)"
                           placeholder="ตั้งชื่อจุดสำรอง (Commit Message) เช่น ก่อน Import ล็อตใหญ่ หรือ ก่อนปรับปรุงข้อมูลประจำเดือน…">
                    <button type="button" class="aoi-search-clear" data-bm-action="clear-title" aria-label="ล้างชื่อจุดสำรอง" title="ล้างข้อความ (Esc) / Clear (Esc)" tabindex="${bm.draft ? 0 : -1}">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                    <kbd class="aoi-search-kbd" aria-hidden="true" title="กด / เพื่อพิมพ์ชื่อจุดสำรอง">/</kbd>
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
                <span class="bm-tab-glider" aria-hidden="true"></span>
                ${tab('all', 'fa-layer-group', 'ทั้งหมด', counts.total)}
                ${tab('monthly_auto', 'fa-calendar-check', 'Auto รายเดือน', counts.monthly_auto)}
                ${tab('manual', 'fa-bookmark', 'สร้างเอง / นำเข้า', counts.manual)}
                ${tab('pre_restore', 'fa-shield-heart', 'สำรองก่อนกู้คืน', counts.pre_restore)}
            </div>
            <span class="bm-list-counter" id="bm-list-counter"></span>
        </div>

        <div class="bm-timeline" id="bm-timeline" role="tabpanel" aria-label="ไทม์ไลน์จุดสำรอง"></div>
    `;

    act.innerHTML = `
        <button type="button" class="gm-btn ghost" data-bm-action="close">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i><span>ปิดหน้าต่าง / Close</span>
        </button>
    `;

    bmRenderTimeline({ stagger: enter });
    bmSyncTabs(false);
    const box = bmTimeline();
    if (box && keepScroll) box.scrollTop = keepScroll;
    if (enter) bmEnter(body, { dy: 6, duration: 300 });
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
            bmShowNotice(err, 'err');
            return;
        }
        bmKeepDraft();
        bm.diffData = json.data;
        bm.view = 'diff';
        bmUpdateHeaderStepper();
        bmRenderDiff();
        bmEnter(body, { dy: 6, duration: 300 });
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
            showFlash('ok', json.message);
            bmAnnounce(json.message);
            bmRenderList();
            bmShowNotice(json.message);
        } else {
            const err = (json.errors && json.errors[0]) || 'บันทึกตั้งค่าไม่สำเร็จ';
            showFlash('err', err);
            bmShowNotice(err, 'err');
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
            if (inp) inp.value = '';
            bm.draft = '';
            showFlash('ok', json.message);
            bmAnnounce(json.message);
            bmFlip(() => bmRenderList());
            bmShowNotice(json.message);
        } else {
            const err = (json.errors && json.errors[0]) || 'สร้างจุดสำรองไม่สำเร็จ';
            showFlash('err', err);
            bmShowNotice(err, 'err');
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
            // Refresh data in-place via AJAX with Zero Page Refreshes
            if (typeof fetchTopologyJs === 'function') fetchTopologyJs();
            if (typeof loadTable === 'function') loadTable();
            if (typeof refreshSheetData === 'function' && typeof state !== 'undefined') refreshSheetData(state.sheet);
            if (typeof loadProgramsData === 'function') loadProgramsData();
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
            await bmLeave(document.querySelector(`#bm-timeline .bm-item[data-bm-id="${Number(snapshotId)}"]`));
            bm.data = json.data;
            showFlash('ok', json.message);
            bmFlip(() => bmRenderList());
            bmShowNotice(json.message);
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
            showFlash('ok', json.message);
            bmFlip(() => bmRenderList());
            bmShowNotice(json.message);
        } else {
            const err = (json.errors && json.errors[0]) || 'นำเข้าไฟล์สำรองไม่สำเร็จ';
            showFlash('err', err);
            bmShowNotice(err, 'err');
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
            if (bm.view !== 'diff') break;
            bm.confirmDeleteId = 0;
            bmRenderList({ enter: true });
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
        case 'filter': {
            const next = btn.dataset.filter || 'all';
            if (next === bm.filter && !bm.confirmDeleteId) break;
            bm.filter = next;
            bm.confirmDeleteId = 0;
            bmSyncTabs(true);
            bmRenderTimeline({ stagger: true, resetScroll: true });
            break;
        }
        case 'clear-title':
            bmClearTitle();
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
                bmRenderTimeline();
                const confirm = bmTimeline()?.querySelector('.bm-inline-confirm');
                bmEnter(confirm, { dy: 0, duration: 260 });
                confirm?.querySelector('[data-bm-action="cancel-delete"]')?.focus({ preventScroll: true });
            }
            break;
        case 'cancel-delete': {
            const was = bm.confirmDeleteId;
            bm.confirmDeleteId = 0;
            bmRenderTimeline();
            bmTimeline()?.querySelector(`[data-bm-action="ask-delete"][data-id="${was}"]`)?.focus({ preventScroll: true });
            break;
        }
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

    // Commit field behaves like the page search: clear button / Esc clears, "/" focuses
    bmOverlay.addEventListener('input', (e) => {
        if (e.target.id !== 'bm-checkpoint-title') return;
        bm.draft = e.target.value;
        const has = e.target.value !== '';
        const glass = e.target.closest('.aoi-search-glass');
        glass?.classList.toggle('has-value', has);
        const clear = glass?.querySelector('.aoi-search-clear');
        if (clear) clear.tabIndex = has ? 0 : -1;
    });

    document.addEventListener('keydown', (e) => {
        if (!gmIsOpen(bmOverlay)) return;
        if (e.key === 'Escape') {
            const sel = e.target?.closest?.('select');
            if (sel && bmSelectOpen(sel)) return; // the open dropdown closes itself first
            e.preventDefault();
            if (e.target?.id === 'bm-checkpoint-title' && e.target.value) {
                bmClearTitle();
            } else if (bm.confirmDeleteId > 0) {
                bm.confirmDeleteId = 0;
                bmRenderTimeline();
            } else if (bm.view === 'diff') {
                bmRenderList({ enter: true });
            } else {
                closeBackupManager();
            }
        } else if (e.key === 'Enter' && e.target?.id === 'bm-checkpoint-title') {
            e.preventDefault();
            if (!bm.busy) bmCreateSnapshot();
        } else {
            gmTrapTab(e, bmOverlay);
        }
    });

    // Capture phase: otherwise the page search (search-fx.js) takes "/" and Ctrl/⌘+K and pulls focus behind the popup
    document.addEventListener('keydown', (e) => {
        if (!gmIsOpen(bmOverlay)) return;
        const t = e.target;
        const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
        const slash = e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing;
        const kbar = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k';
        if (!slash && !kbar) return;
        e.preventDefault();
        e.stopPropagation();
        const inp = document.getElementById('bm-checkpoint-title');
        if (inp) {
            inp.focus();
            inp.select();
        }
    }, true);

    window.addEventListener('resize', () => {
        if (gmIsOpen(bmOverlay) && bm.view === 'list') bmSyncTabs(false);
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
