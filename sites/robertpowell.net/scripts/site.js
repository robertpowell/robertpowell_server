/* robertpowell.net - page behaviour, 2026-11-06. Replaces jQuery 3.5.1 + bootstrap.bundle (170 KB) with the
   handful of things the footer script did: active nav item, hamburger, the Update modal (now a native <dialog>),
   contrast toggle, larger/smaller/reset text, and the icon hover animations. Loaded with defer, so the DOM is ready. */
(function () {
    'use strict';
    var q = function (s, c) { return (c || document).querySelector(s); };
    var qa = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
    var on = function (el, ev, fn) { if (el) { el.addEventListener(ev, fn); } };

    // "active" nav item: the <li> whose id matches the page name (index, privacy, contact)
    var page = document.body.getAttribute('data-page');
    var current = page && document.getElementById(page);
    if (current && current.classList.contains('nav-item')) { current.classList.add('active'); }

    // hamburger
    var toggler = q('.navbar-toggler'), nav = q('#navbarNav');
    on(toggler, 'click', function () {
        nav.classList.toggle('show');
        toggler.setAttribute('aria-expanded', nav.classList.contains('show') ? 'true' : 'false');
    });

    // Update modal: <dialog> gives Escape-to-close and the backdrop for free
    var modal = q('#myModal');
    function openModal(e) { if (e) { e.preventDefault(); } if (modal && !modal.open) { modal.showModal(); } }
    function closeModal() { if (modal && modal.open) { modal.close(); } }
    on(q('#covid19'), 'click', openModal);
    qa('[data-dismiss="modal"]').forEach(function (b) { on(b, 'click', closeModal); });
    on(modal, 'click', function (e) { if (e.target === modal) { closeModal(); } });   // click on the backdrop

    // high-contrast toggle
    on(q('#colortoggle'), 'click', function (e) {
        e.preventDefault();
        [document.body, q('#header')].forEach(function (el) {
            if (el) { el.classList.toggle('bg-dark'); el.classList.toggle('text-white'); }
        });
        var mb = q('#modalbody');
        if (mb) { mb.classList.toggle('text-light'); mb.classList.toggle('bg-dark'); }
    });

    // text size
    function resize(delta) {
        var main = q('#main'), mb = q('#modalbody');
        if (!main) { return; }
        var size = parseInt(window.getComputedStyle(main).fontSize, 10) + delta;
        if (delta > 0 ? size <= 30 : size >= 10) { main.style.fontSize = size + 'px'; }
        if (mb) { mb.style.fontSize = size + 'px'; }
    }
    on(q('#bigger'), 'click', function (e) { e.preventDefault(); resize(2); });
    on(q('#smaller'), 'click', function (e) { e.preventDefault(); resize(-2); });
    on(q('#reload'), 'click', function (e) { e.preventDefault(); location.reload(); });

    // icon hover animations
    function hover(sel, cls) {
        qa(sel).forEach(function (el) {
            on(el, 'mouseenter', function () { el.classList.add(cls); });
            on(el, 'mouseleave', function () { el.classList.remove(cls); });
        });
    }
    hover('#gear, #globe', 'fa-spin-pulse');
    hover('#location, #contact, #phone', 'fa-bounce');
}());
