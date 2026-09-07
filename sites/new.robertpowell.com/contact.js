/* Slide-to-send. The drag itself is the "are you human" check: the server wants a real
   drag (elapsed time + a stream of pointer moves) plus a single-use token issued with the page. */
(function () {
  'use strict';
  var form = document.getElementById('cf'), slide = document.getElementById('slide'),
      knob = document.getElementById('knob'), fill = slide.querySelector('.fill'),
      hint = slide.querySelector('.hint .txt'), status = document.getElementById('status');
  var dragging = false, startX = 0, startT = 0, moves = 0, pos = 0, max = 0, done = false, holdT = 0, holdTimer = null;
  /* Telemetry: sampled [ms since drag start, knob x px] pairs plus how the drag was made. Sent with the
     message purely as evidence of what the "human check" actually saw. */
  var path = [], input = '', maxSamples = 400;
  function sample(t, x) { if (path.length < maxSamples) path.push([Math.round(t), Math.round(x)]); }

  /* ---- "encrypt on the fly": overlay the typed text with churning glyphs as the lock moves right ---- */
  var CIPHER_FIELDS = ['name', 'email', 'subject', 'message'];
  var GLYPHS = '0123456789ABCDEF#%&@?*+=/\\|<>~^!$§¤¶Ø∆λ∑πΩ¥£€';
  var cipher = { on: false, items: [] };
  function cipherStart() {
    if (cipher.on) return; cipher.on = true; cipher.items = [];
    CIPHER_FIELDS.forEach(function (n) {
      var el = form.elements[n]; if (!el || !el.value) return;
      var cs = getComputedStyle(el), ov = document.createElement('div');
      ov.className = 'cipher'; ov.setAttribute('aria-hidden', 'true');
      ov.style.left = el.offsetLeft + 'px'; ov.style.top = el.offsetTop + 'px';
      ov.style.width = el.offsetWidth + 'px'; ov.style.height = el.offsetHeight + 'px';
      ['paddingLeft', 'paddingRight', 'paddingTop', 'paddingBottom', 'borderTopWidth', 'borderBottomWidth', 'borderLeftWidth', 'borderRightWidth',
       'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'borderRadius', 'textAlign'].forEach(function (k) { ov.style[k] = cs[k]; });
      if (el.tagName === 'TEXTAREA') { ov.classList.add('multi'); el.scrollTop = 0; ov.scrollTop = 0; }   /* start the reveal from the top */
      ov.style.borderStyle = 'solid'; ov.style.borderColor = 'transparent';
      el.parentNode.appendChild(ov); el.classList.add('ciphered');
      var mask = [], i; for (i = 0; i < el.value.length; i++) mask.push(GLYPHS.charAt(Math.floor(Math.random() * GLYPHS.length)));
      cipher.items.push({ el: el, ov: ov, text: el.value, mask: mask });
    });
  }
  function cipherStop() {
    if (!cipher.on) return; cipher.on = false;
    cipher.items.forEach(function (it) { it.el.classList.remove('ciphered'); if (it.ov.parentNode) it.ov.parentNode.removeChild(it.ov); });
    cipher.items = [];
  }
  /* p in 0..1: characters up to p*len show their fixed random glyph; a character keeps the first glyph it was given */
  function cipherRender(p) {
    if (!cipher.on) return;
    cipher.items.forEach(function (it) {
      var t = it.text, cut = Math.ceil(t.length * Math.min(1, Math.max(0, p))), out = '', i, c;
      for (i = 0; i < t.length; i++) {
        c = t.charAt(i);
        if (i < cut && c !== ' ') out += '<span class="on">' + it.mask[i] + '</span>';
        else out += c.replace(/&/g, '&amp;').replace(/</g, '&lt;');
      }
      it.ov.innerHTML = '<span class="t">' + out + '</span>'; if (it.ov.classList.contains('multi')) it.ov.scrollTop = it.el.scrollTop;
    });
  }
  var churn = null;
  function churnStart() { /* glyphs are fixed per drag; nothing to animate between knob moves */ }
  function churnStop() { if (churn) { clearInterval(churn); churn = null; } }

  function travel() { return slide.clientWidth - knob.offsetWidth - 6; }
  function setPos(x) {
    max = travel(); pos = Math.max(0, Math.min(max, x));
    knob.style.transform = 'translateX(' + pos + 'px)';
    fill.style.width = (pos + knob.offsetWidth) + 'px';
    cipherRender(pos / Math.max(1, max));
  }
  function reset(msg, cls) {
    churnStop(); cipherStop();
    slide.classList.remove('dragging', 'done', 'busy', 'locked'); setPos(0); moves = 0; done = false;
    hint.textContent = 'Slide to send';
    if (msg) { status.textContent = msg; status.className = 'status ' + (cls || 'bad'); }
  }
  function clearErrors() {
    form.querySelectorAll('.err').forEach(function (e) { e.textContent = ''; });
    status.className = 'status'; status.textContent = '';
  }
  function formOk() {
    clearErrors();
    if (form.checkValidity()) return true;
    var bad = form.querySelector(':invalid');
    form.querySelectorAll(':invalid').forEach(function (el) {
      var p = form.querySelector('.err[data-for="' + el.name + '"]'); if (p) p.textContent = el.validationMessage;
    });
    if (bad) bad.focus();
    return false;
  }

  function begin(x) {
    if (done || slide.classList.contains('busy')) return false;
    if (!formOk()) return false;
    dragging = true; startX = x - pos; startT = performance.now(); moves = 0; path = []; sample(0, pos);
    cipherStart(); churnStart();
    slide.classList.add('dragging'); return true;
  }
  function move(x) { if (!dragging) return; moves++; setPos(x - startX); sample(performance.now() - startT, pos); }
  function end() {
    if (!dragging) return; dragging = false; slide.classList.remove('dragging');
    if (pos >= travel() * 0.96) complete(performance.now() - startT, moves);
    else { knob.style.transition = 'transform .25s ease'; setPos(0); churnStop(); setTimeout(function () { knob.style.transition = ''; cipherStop(); }, 260); }
  }

  knob.addEventListener('pointerdown', function (e) {
    if (e.button !== 0) return;
    if (begin(e.clientX)) { input = e.pointerType || 'pointer'; knob.setPointerCapture(e.pointerId); e.preventDefault(); }
  });
  knob.addEventListener('pointermove', function (e) { move(e.clientX); });
  knob.addEventListener('pointerup', end);
  knob.addEventListener('pointercancel', end);
  knob.addEventListener('lostpointercapture', end);

  /* Keyboard: hold Space for a second; auto-repeat supplies the "moves". */
  knob.addEventListener('keydown', function (e) {
    if (e.key !== ' ' && e.key !== 'Enter') return; e.preventDefault();
    if (done || slide.classList.contains('busy')) return;
    if (!holdT) { if (!formOk()) return; holdT = performance.now(); moves = 0; path = []; input = 'keyboard'; cipherStart(); churnStart(); slide.classList.add('dragging'); }
    moves++; setPos(travel() * Math.min(1, (performance.now() - holdT) / 1000)); sample(performance.now() - holdT, pos);
    if (performance.now() - holdT >= 1000) { var ms = performance.now() - holdT, m = moves; holdT = 0; complete(ms, Math.max(m, 8)); }
  });
  knob.addEventListener('keyup', function (e) {
    if ((e.key === ' ' || e.key === 'Enter') && holdT) { holdT = 0; slide.classList.remove('dragging'); setPos(0); churnStop(); cipherStop(); }
  });

  function complete(ms, m) {
    done = true; setPos(travel()); cipherRender(1);
    slide.classList.remove('dragging'); slide.classList.add('done');
    hint.textContent = 'Sending…';
    form.slide_ms.value = Math.round(ms); form.slide_moves.value = m;
    sample(ms, pos);
    form.slide_path.value = JSON.stringify({ input: input, travel: Math.round(travel()), w: slide.clientWidth,
      dpr: window.devicePixelRatio || 1, touch: ('ontouchstart' in window) ? 1 : 0, path: path });
    slide.classList.add('busy');
    fetch('/contact.php', { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (res) {
        var j = res.j || {};
        if (j.ok) {
          churnStop(); cipherStop();
          hint.textContent = 'Sent';
          form.classList.add('sent'); slide.classList.add('locked');
          status.textContent = (j.message || 'Your message has been sent.') + ' Taking you back to the front page in 5 seconds.';
          status.className = 'status ok';
          setTimeout(function () { location.replace('/'); }, 5000);   /* replace: Back won't return to a spent form */
          return;
        }
        if (j.fields) {
          reset('Please check the highlighted fields.');
          Object.keys(j.fields).forEach(function (k) {
            var p = form.querySelector('.err[data-for="' + k + '"]'); if (p) p.textContent = j.fields[k];
          });
          var first = form.querySelector('[name="' + Object.keys(j.fields)[0] + '"]'); if (first) first.focus();
          return;
        }
        reset(j.error || 'Something went wrong. Please try again.');
        if (res.s === 403 && /token/i.test(j.error || '')) { setTimeout(function () { location.reload(); }, 2500); }
      })
      .catch(function () { reset('Could not reach the server. Check your connection and try again.'); });
  }

  window.addEventListener('resize', function () { if (done) setPos(travel()); });
  form.addEventListener('submit', function (e) { e.preventDefault(); });   /* the slide is the only way to send */
})();

/* One-visit QR address, slide to reveal. The page HTML carries no address: when the visitor drags the
   small knob across (or holds Space), we ask the server for one, bound to this page's form token and
   carrying the drag's timing, and draw it in. Scrapers, and scripts that merely load the page, get nothing. */
(function () {
  'use strict';
  var card = document.getElementById('qrc'), box = document.getElementById('qrbox'), link = document.getElementById('qraddr'),
      form = document.getElementById('cf'), rv = document.getElementById('rv'), knob = document.getElementById('rvknob');
  if (!card || !box || !link || !form || !form.token || !rv || !knob) return;
  var fill = rv.querySelector('.fill'), hint = rv.querySelector('.hint .txt');
  var dragging = false, startX = 0, startT = 0, moves = 0, pos = 0, done = false, holdT = 0;
  var path = [], input = '';
  function sample(t, x) { if (path.length < 400) path.push([Math.round(t), Math.round(x)]); }
  function travel() { return rv.clientWidth - knob.offsetWidth - 6; }
  function setPos(x) { pos = Math.max(0, Math.min(travel(), x)); knob.style.transform = 'translateX(' + pos + 'px)'; fill.style.width = (pos + knob.offsetWidth) + 'px'; }
  function springBack() { knob.style.transition = 'transform .25s ease'; setPos(0); setTimeout(function () { knob.style.transition = ''; }, 260); }
  function fail(msg) { done = false; rv.classList.remove('busy', 'dragging'); hint.textContent = msg || 'Slide to reveal'; springBack(); }
  function reveal(ms, m) {
    done = true; setPos(travel()); rv.classList.remove('dragging'); rv.classList.add('busy'); hint.textContent = 'One moment…';
    sample(ms, pos);
    var fd = new FormData();
    fd.append('qr', '1'); fd.append('token', form.token.value); fd.append('slide_ms', Math.round(ms)); fd.append('slide_moves', m);
    fd.append('slide_path', JSON.stringify({ input: input, travel: Math.round(travel()), w: rv.clientWidth,
      dpr: window.devicePixelRatio || 1, touch: ('ontouchstart' in window) ? 1 : 0, path: path }));
    fetch('/contact.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok || !j.address || !j.svg) { fail('Try sliding again'); return; }
        box.innerHTML = j.svg;
        var at = j.address.indexOf('@'), local = j.address.slice(0, at).split('-'), dom = j.address.slice(at);
        link.textContent = '';
        local.forEach(function (w, i) { var b = document.createElement('b'); b.textContent = w; link.appendChild(b); if (i < local.length - 1) link.appendChild(document.createTextNode('-')); });
        link.appendChild(document.createTextNode(dom));
        link.href = 'mailto:' + j.address;
        link.setAttribute('aria-label', 'Email ' + j.address);
        card.classList.add('shown');
      })
      .catch(function () { fail('Try sliding again'); });
  }
  function begin(x) { if (done) return false; dragging = true; startX = x - pos; startT = performance.now(); moves = 0; path = []; sample(0, pos); rv.classList.add('dragging'); return true; }
  function move(x) { if (!dragging) return; moves++; setPos(x - startX); sample(performance.now() - startT, pos); }
  function end() {
    if (!dragging) return; dragging = false; rv.classList.remove('dragging');
    if (pos >= travel() * 0.96) reveal(performance.now() - startT, moves); else springBack();
  }
  knob.addEventListener('pointerdown', function (e) { if (e.button !== 0) return; if (begin(e.clientX)) { input = e.pointerType || 'pointer'; knob.setPointerCapture(e.pointerId); e.preventDefault(); } });
  knob.addEventListener('pointermove', function (e) { move(e.clientX); });
  knob.addEventListener('pointerup', end);
  knob.addEventListener('pointercancel', end);
  knob.addEventListener('lostpointercapture', end);
  knob.addEventListener('keydown', function (e) {
    if (e.key !== ' ' && e.key !== 'Enter') return; e.preventDefault();
    if (done) return;
    if (!holdT) { holdT = performance.now(); moves = 0; path = []; input = 'keyboard'; rv.classList.add('dragging'); }
    moves++; setPos(travel() * Math.min(1, (performance.now() - holdT) / 1000)); sample(performance.now() - holdT, pos);
    if (performance.now() - holdT >= 1000) { var ms = performance.now() - holdT, m = moves; holdT = 0; reveal(ms, Math.max(m, 8)); }
  });
  knob.addEventListener('keyup', function (e) { if ((e.key === ' ' || e.key === 'Enter') && holdT) { holdT = 0; rv.classList.remove('dragging'); springBack(); } });
  window.addEventListener('resize', function () { if (done) setPos(travel()); });
})();
