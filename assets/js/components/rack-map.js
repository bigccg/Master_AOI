/**
 * Shelf Rack Map + Add Magazine (components/rack-map.php).
 */

const RACK_PER_PAGE = 6;

let currentRackPage = 1;

function renderRackMap(rack, sp) {
    const rackSection = document.getElementById('rack-map');
    if (!rackSection) return;

    // Header counter
    const counter = rackSection.querySelector('#rack-header-counter');
    if (counter) counter.textContent = `${rack.used_shelves}/${rack.capacity}`;

    const titleEl = rackSection.querySelector('#rack-header-title');
    if (titleEl) titleEl.textContent = `Shelf Rack Physical Map: Rack ${sp} (ตารางชั้นวางจริง)`;

    // Max Magazine banner
    let maxBanner = document.getElementById('rack-max-banner');
    if (rack.is_max) {
        if (!maxBanner) {
            maxBanner = document.createElement('div');
            maxBanner.id = 'rack-max-banner';
            rackSection.insertBefore(maxBanner, document.getElementById('rack-cards-container'));
        }
        maxBanner.className = 'no-print mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 flex flex-wrap items-center justify-between gap-2 text-xs';
        maxBanner.innerHTML = `
            <span class="font-bold text-red-700 flex items-center gap-1.5">
              <i class="fa-solid fa-triangle-exclamation"></i> Magazine is Max
              <span class="font-normal text-red-600">— Shelf ${h(sp)}1-${h(sp + rack.capacity)} เต็มทั้งหมด ไม่สามารถเลือก Shelf ใหม่ได้</span>
            </span>
            ${rack.can_add_mag ? `
              <div class="flex items-center gap-2">
                <button type="button" id="add-mag-btn" onclick="toggleAddMag(true)"
                        class="px-3 py-1 rounded-md bg-white border border-red-300 text-red-700 font-semibold hover:bg-red-100 cursor-pointer">
                  <i class="fa-solid fa-plus mr-1"></i>Add Magazine
                </button>
                <form id="add-mag-confirm" onsubmit="handleAddMagazine(event)" class="hidden items-center gap-2">
                  <input type="hidden" name="csrf" value="${h(getCsrf())}"/>
                  <input type="hidden" name="action" value="add_magazine"/>
                  <input type="hidden" name="magazines" value="${rack.mag_count}"/>
                  <span class="text-slate-700">เพิ่ม <b>Magazine ${ordinal(rack.mag_count + 1)}</b> (Shelf ${rack.next_range[0]}-${rack.next_range[1]}) อีก 50 Shelf ?</span>
                  <button type="submit" class="px-3 py-1 rounded-md bg-red-600 text-white font-semibold hover:bg-red-700 cursor-pointer">Confirm</button>
                  <button type="button" onclick="toggleAddMag(false)" class="px-3 py-1 rounded-md bg-white border border-slate-300 text-slate-600 hover:bg-slate-100 cursor-pointer">Cancel</button>
                </form>
              </div>` : `<span class="text-slate-600">ครบจำนวนสูงสุดแล้ว</span>`}
        `;
    } else if (maxBanner) {
        maxBanner.remove();
    }

    // Render Magazines Cards
    const cardsContainer = document.getElementById('rack-cards-container');
    if (!cardsContainer) return;

    // Other = Bare / Bad Mark has no G / D / G+D, so it gets its own color (keyed by pcs_status)
    const cellCls = {
        G:  'bg-emerald-600 text-white',
        D:  'bg-red-600 text-white',
        GD: 'bg-amber-400 text-amber-950',
        'Bare':     'bg-sky-500 text-white',
        'Bad Mark': 'bg-violet-600 text-white'
    };
    const statusLabel = { G: 'G', D: 'D', GD: 'G+D' };

    let cardsHtml = '';
    rack.magazines.forEach(m => {
        let gridHtml = '';
        m.shelves.forEach(s => {
            if (s.occupied) {
                const d = s.data;
                const key = (window.OTHER_NO_CLASS || []).includes(d.pcs_status) ? d.pcs_status : s.status;
                const tip = `${s.shelf} · ${d.code} · ${d.product} · ${statusLabel[key] || key}`;
                gridHtml += `
                    <button type="button" data-action="edit" data-id="${d.id}" title="${h(tip)}"
                            class="h-7 flex items-center justify-center rounded text-[11px] font-semibold ${cellCls[key] || 'bg-slate-500 text-white'} hover:ring-2 hover:ring-blue-400 hover:ring-offset-1 cursor-pointer transition">${h(s.shelf)}</button>
                `;
            } else {
                gridHtml += `
                    <button type="button" data-shelf="${h(s.shelf)}" title="${h(s.shelf)} · Empty"
                            class="h-7 flex items-center justify-center rounded text-[11px] bg-slate-100 text-slate-400 border border-slate-200 hover:border-blue-300 hover:text-blue-500 cursor-pointer transition">${h(s.shelf)}</button>
                `;
            }
        });

        cardsHtml += `
            <div data-mag="${m.mag}" class="rack-card bg-white rounded-md border ${m.full ? 'border-red-200' : 'border-slate-200'} p-2.5 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-[11px] font-bold text-slate-700">${h(m.title)}</h3>
                    <span class="text-[10px] font-mono ${m.full ? 'text-red-600 font-bold' : 'text-slate-500'}">${m.used}/50${m.full ? ' · Full' : ''}</span>
                </div>
                <div class="grid grid-cols-10 gap-1 font-mono text-center">
                    ${gridHtml}
                </div>
            </div>
        `;
    });

    cardsContainer.innerHTML = cardsHtml;
    renderRackPager(rack.mag_count);
}

function renderRackPager(totalMagazines) {
    const box = document.getElementById('rack-pager');
    if (!box) return;

    const per = RACK_PER_PAGE;
    const pages = Math.ceil(totalMagazines / per);

    if (pages <= 1) {
        box.classList.add('hidden');
        document.querySelectorAll('#rack-cards-container .rack-card').forEach(c => c.classList.remove('hidden'));
        return;
    }

    if (currentRackPage > pages) currentRackPage = pages;
    const page = currentRackPage;
    const a = (page - 1) * per + 1;
    const b = Math.min(page * per, totalMagazines);

    document.querySelectorAll('#rack-cards-container .rack-card').forEach(c => {
        const magNo = +c.dataset.mag;
        c.classList.toggle('hidden', magNo < a || magNo > b);
    });

    const btn = 'relative inline-flex items-center px-3.5 py-2 text-xs font-semibold ring-1 ring-inset focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer';
    const arrow = (d) => `<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="${d ? 'M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z' : 'M12.79 5.23a.75.75 0 0 1-.02 1.06L8.83 10l3.94 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z'}" clip-rule="evenodd"/></svg>`;

    let nums = '';
    for (let i = 1; i <= pages; i++) {
        const cur = i === page;
        nums += `<button type="button" data-p="${i}" ${cur ? 'aria-current="page"' : ''} aria-label="Magazine page ${i}" class="${btn} ${cur ? 'z-10 bg-emerald-600 text-white ring-emerald-600' : 'text-slate-900 hover:bg-slate-50 ring-slate-300'}">${i}</button>`;
    }

    box.innerHTML = `
        <div class="flex items-center justify-between border-t border-slate-200 pt-3">
            <p class="text-xs text-slate-700">Showing Magazine <b class="font-semibold">${a}</b> to <b class="font-semibold">${b}</b> of <b class="font-semibold">${totalMagazines}</b></p>
            <nav aria-label="Rack map pagination" class="isolate inline-flex -space-x-px rounded-md shadow-sm">
                <button type="button" data-p="${page - 1}" ${page <= 1 ? 'disabled' : ''} aria-label="Previous page" class="${btn} rounded-l-md text-slate-500 ring-slate-300 hover:bg-slate-50">${arrow(0)}</button>${nums}
                <button type="button" data-p="${page + 1}" ${page >= pages ? 'disabled' : ''} aria-label="Next page" class="${btn} rounded-r-md text-slate-500 ring-slate-300 hover:bg-slate-50">${arrow(1)}</button>
            </nav>
        </div>
    `;
    box.classList.remove('hidden');
}

function toggleAddMag(show) {
    const btn = document.getElementById('add-mag-btn');
    const box = document.getElementById('add-mag-confirm');
    if (!btn || !box) return;
    btn.classList.toggle('hidden', show);
    box.classList.toggle('hidden', !show);
    box.classList.toggle('flex', show);
}
window.toggleAddMag = toggleAddMag;

async function handleAddMagazine(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    const formData = new FormData(form);
    formData.set('sheet', state.sheet);

    try {
        const res = await fetch('api/magazine.php', {
            method: 'POST',
            body: formData
        });
        const result = await res.json();

        if (result.success) {
            showFlash('ok', result.message);
            toggleAddMag(false);
            await Promise.all([
                loadTable(),
                refreshSheetData(state.sheet)
            ]);
        } else {
            showFlash('err', result.message || 'ไม่สามารถเพิ่ม Magazine ได้');
        }
    } catch (err) {
        showFlash('err', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}
window.handleAddMagazine = handleAddMagazine;

// Listeners: empty shelf -> confirm insert, occupied shelf -> edit, magazine pager
function setupRackMapEvents() {
    // Rack Map clicks (empty shelf opens confirm, occupied shelf opens edit)
    document.getElementById('rack-map')?.addEventListener('click', (e) => {
        const emptyBtn = e.target.closest('button[data-shelf]');
        if (emptyBtn) {
            openRackConfirm(emptyBtn.dataset.shelf);
            return;
        }
        const editBtn = e.target.closest('button[data-action="edit"]');
        if (editBtn) {
            openEditModal(editBtn.dataset.id);
            return;
        }
        const pagerBtn = e.target.closest('#rack-pager button[data-p]');
        if (pagerBtn && !pagerBtn.disabled) {
            currentRackPage = +pagerBtn.dataset.p;
            if (currentSheetData) {
                renderRackPager(currentSheetData.rack.mag_count);
            }
        }
    });
}
