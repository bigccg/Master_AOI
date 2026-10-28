/**
 * Smart Live AJAX Auto-Sync Engine (Zero Page Refreshes)
 * Keeps Master Boards and AOI Programs up to date in the background.
 * Eliminates the need for operators and supervisors to press F5 / reload.
 */

(function () {
    let syncTimer = null;
    let isSyncing = false;
    let lastMasterChecksum = null;
    const SYNC_INTERVAL_MS = 20000; // 20 seconds

    function isUserBusy() {
        // Don't auto-sync while user has a modal open or is actively typing in an input
        const openModal = document.querySelector('.gm-overlay:not(.hidden)');
        if (openModal) return true;

        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) {
            return true;
        }
        return false;
    }

    function setSyncStatus(status, text) {
        const badge = document.getElementById('live-sync-indicator');
        const label = document.getElementById('live-sync-text');
        const icon = document.getElementById('live-sync-icon');
        if (!badge) return;

        badge.classList.remove('hidden');

        if (status === 'syncing') {
            if (icon) icon.classList.add('fa-spin');
            if (label && text) label.textContent = text;
        } else if (status === 'ok') {
            if (icon) icon.classList.remove('fa-spin');
            badge.className = 'hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium bg-emerald-50/90 text-emerald-700 border border-emerald-200/80 shrink-0 select-none shadow-xs transition-all';
            if (label) label.textContent = 'Live Sync';
            badge.title = 'ระบบซิงค์ข้อมูลอัตโนมัติแบบเรียลไทม์ (Live AJAX Auto-Sync Active) หน้าเว็บจะอัปเดตตลอดเวลาโดยไม่ต้องกดรีเฟรช';
        } else if (status === 'offline') {
            if (icon) icon.classList.remove('fa-spin');
            badge.className = 'hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium bg-amber-50/90 text-amber-700 border border-amber-200/80 shrink-0 select-none shadow-xs transition-all';
            if (label) label.textContent = 'Offline';
            badge.title = 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ชั่วคราว ระบบจะลองใหม่โดยอัตโนมัติ';
        }
    }

    async function performSync(manual = false) {
        if (isSyncing) return;
        if (!manual && isUserBusy()) return;
        if (!manual && document.visibilityState === 'hidden') return;

        isSyncing = true;
        setSyncStatus('syncing', manual ? 'กำลังซิงค์...' : 'Live Sync');

        try {
            // Case 1: On Master Board page
            if (typeof state !== 'undefined' && typeof loadTable === 'function') {
                // Fetch lightweight sheet_data to see if total or counts changed
                const url = new URL('api/sheet_data.php', window.location.href);
                url.searchParams.set('sheet', state.sheet);
                url.searchParams.set('zone', state.zone);
                url.searchParams.set('period', state.period || 'ALL');

                const res = await fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (res.ok) {
                    const data = await res.json();
                    if (data.success) {
                        const newChecksum = `${data.total_boards}_${data.tab_counts?.[state.sheet] || 0}_${data.summary?.total_defect_boards || 0}`;
                        const countsChanged = manual || newChecksum !== lastMasterChecksum;
                        if (countsChanged) {
                            lastMasterChecksum = newChecksum;
                            if (typeof applySheetData === 'function') {
                                applySheetData(data, state.sheet);
                            }
                        }
                        // Every tick: edits that keep the counts still reach the table; only changed rows flash
                        await loadTable(countsChanged, { silent: true });
                    }
                    setSyncStatus('ok');
                } else {
                    setSyncStatus('offline');
                }
            }
            // Case 2: On Programs page
            else if (typeof loadProgramsData === 'function') {
                await loadProgramsData({ silent: true });
                setSyncStatus('ok');
            }
        } catch (err) {
            console.warn('Live sync check paused:', err);
            setSyncStatus('offline');
        } finally {
            isSyncing = false;
        }
    }

    window.triggerManualSync = function () {
        performSync(true);
    };

    function startAutoSync() {
        if (syncTimer) clearInterval(syncTimer);
        syncTimer = setInterval(() => performSync(false), SYNC_INTERVAL_MS);
        setSyncStatus('ok');
    }

    document.addEventListener('DOMContentLoaded', () => {
        startAutoSync();

        // Immediate sync when tab regains focus
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                performSync(false);
            }
        });
    });
})();
