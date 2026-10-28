/**
 * Seamless SPA Router (Single Page Application — Zero Page Refreshes)
 * Enables instantaneous switching between Master Board (index.php) and AOI Programs (programs.php)
 * using AJAX Fetch, History API pushState/popstate, and smart DOM morphing.
 */

(function () {
    let isTransitioning = false;
    const pageCache = new Map();

    function getProgressBar() {
        let bar = document.getElementById('spa-progress-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'spa-progress-bar';
            bar.className = 'fixed top-0 left-0 h-0.5 bg-emerald-500 z-50 transition-all duration-300 pointer-events-none opacity-0';
            bar.style.width = '0%';
            document.body.appendChild(bar);
        }
        return bar;
    }

    function startProgress() {
        const bar = getProgressBar();
        bar.style.transition = 'width 200ms ease, opacity 100ms ease';
        bar.style.opacity = '1';
        bar.style.width = '35%';
        setTimeout(() => {
            if (isTransitioning) bar.style.width = '70%';
        }, 150);
    }

    function endProgress() {
        const bar = getProgressBar();
        bar.style.width = '100%';
        setTimeout(() => {
            bar.style.opacity = '0';
            setTimeout(() => {
                bar.style.width = '0%';
            }, 250);
        }, 120);
    }

    async function navigateTo(url, push = true) {
        if (isTransitioning) return;
        const targetUrl = new URL(url, window.location.href);

        // If clicking same page with exact same search, do nothing
        if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search) {
            return;
        }

        isTransitioning = true;
        startProgress();

        try {
            let html = pageCache.get(targetUrl.href);
            if (!html) {
                const res = await fetch(targetUrl.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!res.ok) {
                    // Fallback to normal navigation if fetch failed
                    window.location.href = targetUrl.href;
                    return;
                }
                html = await res.text();
            }

            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const currentMain = document.querySelector('main');
            const newMain = doc.querySelector('main');

            if (!currentMain || !newMain) {
                window.location.href = targetUrl.href;
                return;
            }

            // Update title
            document.title = doc.title;

            // Swap main content with smooth fade
            currentMain.style.opacity = '0';
            currentMain.style.transition = 'opacity 120ms ease-out';

            setTimeout(() => {
                currentMain.innerHTML = newMain.innerHTML;
                currentMain.className = newMain.className;

                // Sync header controls (Action buttons, zone tabs)
                syncHeaderState(doc, targetUrl);

                // Update URL in history
                if (push) {
                    window.history.pushState({ spa: true, url: targetUrl.href }, '', targetUrl.href);
                }

                // Bootstrap the target page's scripts
                bootstrapPage(targetUrl, doc);

                currentMain.style.opacity = '1';
                endProgress();
                isTransitioning = false;
            }, 120);

        } catch (err) {
            console.error('SPA navigation error:', err);
            endProgress();
            isTransitioning = false;
            window.location.href = targetUrl.href;
        }
    }

    function syncHeaderState(doc, targetUrl) {
        const isMaster = targetUrl.pathname.includes('index.php') || targetUrl.pathname.endsWith('/');
        const isPrograms = targetUrl.pathname.includes('programs.php');

        // Update active nav tab styling
        const masterTab = document.getElementById('nav-tab-master');
        const progTab = document.getElementById('nav-tab-programs');

        if (masterTab && progTab) {
            if (isMaster) {
                masterTab.className = 'px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 bg-white text-emerald-700 shadow-sm font-semibold';
                masterTab.querySelector('i')?.classList.replace('text-slate-400', 'text-emerald-600');
                progTab.className = 'px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 text-slate-600 hover:text-slate-900 hover:bg-slate-200/60';
                progTab.querySelector('i')?.classList.replace('text-emerald-600', 'text-slate-400');
            } else if (isPrograms) {
                progTab.className = 'px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 bg-white text-emerald-700 shadow-sm font-semibold';
                progTab.querySelector('i')?.classList.replace('text-slate-400', 'text-emerald-600');
                masterTab.className = 'px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 text-slate-600 hover:text-slate-900 hover:bg-slate-200/60';
                masterTab.querySelector('i')?.classList.replace('text-emerald-600', 'text-slate-400');
            }
        }

        // Show/hide zone tabs below header
        const zoneTabs = document.getElementById('sheet-tabs');
        if (zoneTabs) {
            zoneTabs.style.display = isMaster ? '' : 'none';
        }

        // Update page action buttons in header toolbar
        const currentActions = document.querySelector('header .border-l.border-slate-200');
        const newActions = doc.querySelector('header .border-l.border-slate-200');
        if (currentActions && newActions) {
            // Keep admin controls intact while swapping page actions
            const adminControls = document.getElementById('header-admin-controls');
            currentActions.innerHTML = newActions.innerHTML;
            if (adminControls && !document.getElementById('header-admin-controls')) {
                currentActions.appendChild(adminControls);
            }
        }
    }

    function bootstrapPage(targetUrl, doc) {
        const isMaster = targetUrl.pathname.includes('index.php') || targetUrl.pathname.endsWith('/');
        const isPrograms = targetUrl.pathname.includes('programs.php');

        // Dynamically load any missing styles from the fetched document
        doc.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
            const href = link.getAttribute('href');
            if (href && !document.querySelector(`link[href="${href}"]`)) {
                const newLink = document.createElement('link');
                newLink.rel = 'stylesheet';
                newLink.href = href;
                document.head.appendChild(newLink);
            }
        });

        // Dynamically load any missing scripts
        const scriptPromises = [];
        doc.querySelectorAll('script[src]').forEach(s => {
            const src = s.getAttribute('src');
            if (src && !document.querySelector(`script[src="${src}"]`)) {
                const p = new Promise(resolve => {
                    const newScript = document.createElement('script');
                    newScript.src = src;
                    newScript.onload = resolve;
                    newScript.onerror = resolve;
                    document.body.appendChild(newScript);
                });
                scriptPromises.push(p);
            }
        });

        Promise.all(scriptPromises).then(() => {
            if (isMaster) {
                if (typeof setupSearch === 'function') setupSearch();
                if (typeof setupFilters === 'function') setupFilters();
                if (typeof setupDelegation === 'function') setupDelegation();
                if (typeof syncShelfViewMode === 'function') syncShelfViewMode();
                if (typeof loadTable === 'function') loadTable();
                if (typeof refreshSheetData === 'function' && typeof state !== 'undefined') {
                    refreshSheetData(state.sheet);
                }
            } else if (isPrograms) {
                if (typeof initProgState === 'function') initProgState();
                if (typeof initProgKpis === 'function') initProgKpis();
                if (typeof initProgStageGuide === 'function') initProgStageGuide();
                if (typeof initProgCommandBar === 'function') initProgCommandBar();
                if (typeof initProgTableEvents === 'function') initProgTableEvents();
                if (typeof loadProgramsData === 'function') loadProgramsData();
            }
        });
    }

    // Attach SPA click delegation to navigation tabs
    function setupSpaNavListeners() {
        document.addEventListener('click', (e) => {
            const navLink = e.target.closest('#nav-tab-master, #nav-tab-programs');
            if (!navLink) return;

            const href = navLink.getAttribute('href');
            if (!href || href === '#') return;

            e.preventDefault();
            navigateTo(href);
        });

        window.addEventListener('popstate', (e) => {
            navigateTo(window.location.href, false);
        });
    }

    window.spaNavigate = navigateTo;

    document.addEventListener('DOMContentLoaded', () => {
        setupSpaNavListeners();
    });
})();
