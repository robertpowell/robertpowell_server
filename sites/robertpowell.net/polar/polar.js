/* /polar/polar.js — reports how much of the video was watched to beacon.php (same folder).
 * One session id per page load. Events: open, play, tick (every 10 s while playing), pause, seek, ended,
 * hide (tab hidden), close (page left), error. Each carries the current position, the furthest point reached,
 * the total seconds actually played (the union of the browser's played ranges, so skipping ahead does not
 * count) and the video length. Nothing is stored in the browser. */
(function () {
  'use strict';
  var v = document.getElementById('v');
  if (!v) return;
  var sid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
          : Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 10);
  var file = v.getAttribute('data-file') || '';
  var maxPos = 0, plays = 0, seeks = 0, timer = null;

  function covered() {
    var r = v.played, s = 0;
    for (var i = 0; i < r.length; i++) s += r.end(i) - r.start(i);
    return s;
  }
  function state(ev) {
    var tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
    return {
      sid: sid, ev: ev, file: file,
      pos: +v.currentTime.toFixed(1), max: +maxPos.toFixed(1),
      dur: isFinite(v.duration) ? +v.duration.toFixed(1) : 0, played: +covered().toFixed(1),
      plays: plays, seeks: seeks, muted: !!(v.muted || v.volume === 0),
      vis: document.visibilityState || '', w: screen.width || 0, h: screen.height || 0, tz: tz
    };
  }
  function send(ev) {
    var body = JSON.stringify(state(ev));
    try {
      if (navigator.sendBeacon && navigator.sendBeacon('beacon.php', new Blob([body], { type: 'application/json' }))) return;
    } catch (e) {}
    try {
      fetch('beacon.php', { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' },
                            keepalive: true, credentials: 'omit' }).catch(function () {});
    } catch (e) {}
  }

  v.addEventListener('timeupdate', function () { if (v.currentTime > maxPos) maxPos = v.currentTime; });
  v.addEventListener('play', function () {
    plays++; send('play');
    if (!timer) timer = setInterval(function () { if (!v.paused && !v.ended) send('tick'); }, 10000);
  });
  v.addEventListener('pause', function () { if (!v.ended) send('pause'); });
  v.addEventListener('seeked', function () { seeks++; send('seek'); });
  v.addEventListener('ended', function () { send('ended'); });
  v.addEventListener('error', function () { send('error'); });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') send('hide'); });
  window.addEventListener('pagehide', function () { send('close'); });
  send('open');
})();
