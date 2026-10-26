/**
 * Glass HyperModern — tiny effects layer (no dependencies, no layout work).
 *  1. Cursor light: eases --gx/--gy on <html> (page glow) and --lx/--ly on the hovered glass card.
 *  2. Header gains a deeper shadow once the page scrolls.
 * Pure progressive enhancement: with JS off, reduced motion, or touch-only input, the page looks the same, just static.
 */
(function () {
    'use strict';
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fine = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;
    var root = document.documentElement;

    /* ---- header shadow on scroll (the scroll container is <main>) ---- */
    function bindHeader() {
        var header = document.querySelector('body > header');
        var main = document.querySelector('main');
        if (!header || !main) return;
        var on = false;
        function tick() {
            var s = (main.scrollTop || window.scrollY || 0) > 4;
            if (s !== on) { on = s; header.classList.toggle('is-scrolled', s); }
        }
        main.addEventListener('scroll', tick, { passive: true });
        window.addEventListener('scroll', tick, { passive: true });
        tick();
    }

    /* ---- cursor light ---- */
    function bindLight() {
        if (reduce || !fine) return;
        var tx = 50, ty = 0, cx = 50, cy = 0, raf = 0, card = null;
        var CARD = 'main > section.grid > div, main > #filters';

        function frame() {
            cx += (tx - cx) * 0.12;
            cy += (ty - cy) * 0.12;
            root.style.setProperty('--gx', cx.toFixed(2) + '%');
            root.style.setProperty('--gy', cy.toFixed(2) + '%');
            raf = (Math.abs(tx - cx) > 0.05 || Math.abs(ty - cy) > 0.05) ? requestAnimationFrame(frame) : 0;
        }
        document.addEventListener('pointermove', function (e) {
            tx = e.clientX / window.innerWidth * 100;
            ty = e.clientY / window.innerHeight * 100;
            if (!raf) raf = requestAnimationFrame(frame);

            var el = e.target && e.target.closest ? e.target.closest(CARD) : null;
            if (el) {
                var r = el.getBoundingClientRect();
                el.style.setProperty('--lx', (e.clientX - r.left) + 'px');
                el.style.setProperty('--ly', (e.clientY - r.top) + 'px');
                card = el;
            }
        }, { passive: true });
    }

    function init() { bindHeader(); bindLight(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
