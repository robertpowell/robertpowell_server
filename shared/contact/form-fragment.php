<?php /* Contact form fragment. Needs $token and $privacy_url. Colours come from CSS vars set by the page:
   --cf-fg --cf-muted --cf-line --cf-card --cf-field --cf-accent --cf-ok --cf-bad --cf-track --cf-knob --cf-knobfg */ ?>
<style>
  .cf { background:var(--cf-card,#fff); border:1px solid var(--cf-line,#cbd5e1); border-radius:14px; padding:1.5rem; color:var(--cf-fg,#0f172a); font-size:1rem; line-height:1.5; text-align:left; }
  .cf label { display:block; font-size:.8rem; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:var(--cf-muted,#64748b); margin:0 0 .35rem; }
  .cf .f { margin-bottom:1.1rem; position:relative; }
  /* "encrypting" overlay drawn over a field while the lock is dragged; the real value is untouched */
  .cf .cipher { position:absolute; display:flex; align-items:center; pointer-events:none; overflow:hidden;
    white-space:pre; box-sizing:border-box; color:var(--cf-fg,#0f172a); font-variant-ligatures:none; }
  /* scrambled glyphs: amber on a light ground, phosphor green on a dark one */
  .cf .cipher .on { color:var(--cf-cipher,#d97706); text-shadow:0 0 6px var(--cf-cipher-glow,rgba(217,119,6,.35)); }
  @media (prefers-color-scheme: dark) { .cf .cipher .on { color:var(--cf-cipher-dark,#33ff33); text-shadow:0 0 6px var(--cf-cipher-dark-glow,rgba(51,255,51,.45)); } }
  :root[data-theme="dark"] .cf .cipher .on { color:var(--cf-cipher-dark,#33ff33); text-shadow:0 0 6px var(--cf-cipher-dark-glow,rgba(51,255,51,.45)); }
  :root[data-theme="light"] .cf .cipher .on { color:var(--cf-cipher,#d97706); text-shadow:0 0 6px var(--cf-cipher-glow,rgba(217,119,6,.35)); }
  .cf .cipher .t { display:inline; white-space:inherit; }
  .cf .cipher.multi { display:block; white-space:pre-wrap; word-wrap:break-word; overflow-wrap:anywhere; }
  .cf textarea.ciphered { color:transparent !important; caret-color:transparent; }
  .cf input.ciphered { color:transparent !important; caret-color:transparent; }
  .cf input, .cf textarea { width:100%; font:inherit; font-family:ui-monospace,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace; font-size:.95rem; color:var(--cf-fg,#0f172a); background:var(--cf-field,#fff); border:1px solid var(--cf-line,#cbd5e1);
    border-radius:8px; padding:.65rem .8rem; outline:none; transition:border-color .15s, box-shadow .15s; box-sizing:border-box; }
  .cf input:focus, .cf textarea:focus { border-color:var(--cf-accent,#0f172a); box-shadow:0 0 0 3px color-mix(in srgb, var(--cf-accent,#0f172a) 18%, transparent); }
  .cf textarea { min-height:9rem; resize:vertical; }
  .cf .err { color:var(--cf-bad,#b91c1c); font-size:.85rem; margin:.3rem 0 0; min-height:1em; }
  .cf .hp { position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden; }
  .cf .privacy-note { font-size:.8rem; color:var(--cf-muted,#64748b); margin:0 0 1rem; }
  .cf .privacy-note a { color:var(--cf-fg,#0f172a); }
  .cf .slide { position:relative; height:56px; border-radius:28px; background:var(--cf-track,#e2e8f0); border:1px solid var(--cf-line,#cbd5e1);
    user-select:none; -webkit-user-select:none; touch-action:pan-y; overflow:hidden; margin-top:.5rem; }
  .cf .slide .fill { position:absolute; inset:0; width:0; background:var(--cf-accent,#0f172a); opacity:.14; transition:width .05s linear; }
  .cf .slide .hint { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; gap:.5rem;
    font-weight:600; letter-spacing:.02em; color:var(--cf-muted,#64748b); pointer-events:none; transition:opacity .2s; }
  .cf .slide .hint .chev { display:inline-block; animation:cf-nudge 1.4s ease-in-out infinite; }
  @keyframes cf-nudge { 0%,100% { transform:translateX(0); opacity:.5 } 50% { transform:translateX(6px); opacity:1 } }
  .cf .slide .knob { position:absolute; top:3px; left:3px; width:48px; height:48px; border-radius:50%; background:var(--cf-knob,var(--cf-accent,#0f172a));
    color:var(--cf-knobfg,#fff); display:grid; place-items:center; cursor:grab; box-shadow:0 2px 8px rgba(0,0,0,.25);
    touch-action:none; outline:none; transition:transform .05s linear; }
  .cf .slide .knob:focus-visible { box-shadow:0 0 0 3px color-mix(in srgb, var(--cf-accent,#0f172a) 40%, transparent), 0 2px 8px rgba(0,0,0,.25); }
  .cf .slide.dragging .knob { cursor:grabbing; transition:none; }
  .cf .slide.dragging .hint { opacity:.35; }
  .cf .slide.done .knob { cursor:default; }
  .cf .slide.done .hint, .cf .slide.busy .hint { opacity:1; color:var(--cf-fg,#0f172a); }
  .cf .slide.done .hint .chev, .cf .slide.busy .hint .chev { display:none; }
  .cf .slide.locked { pointer-events:none; opacity:.6; }
  .cf .knob svg { width:22px; height:22px; }
  .cf .knob .shackle { transition:transform .25s ease; transform-origin:20px 50%; }
  .cf .slide.done .knob .shackle { transform:translateY(-3px) rotate(-30deg); }
  .cf .kbd { font-size:.78rem; color:var(--cf-muted,#64748b); margin:.5rem 0 0; }
  .cf .status { margin-top:1rem; padding:.9rem 1rem; border-radius:10px; font-size:.95rem; display:none; }
  .cf .status.ok  { display:block; background:color-mix(in srgb, var(--cf-ok,#15803d) 12%, transparent); color:var(--cf-ok,#15803d); }
  .cf .status.bad { display:block; background:color-mix(in srgb, var(--cf-bad,#b91c1c) 12%, transparent); color:var(--cf-bad,#b91c1c); }
  .cf.sent .f, .cf.sent .slide, .cf.sent .kbd, .cf.sent .privacy-note { display:none; }
</style>
<form id="cf" class="cf" method="post" action="/contact.php" novalidate autocomplete="on">
  <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
  <input type="hidden" name="slide_ms" value="0">
  <input type="hidden" name="slide_moves" value="0">
  <input type="hidden" name="slide_path" value="">
  <div class="hp" aria-hidden="true"><label for="company">Company</label><input id="company" name="company" tabindex="-1" autocomplete="off"></div>

  <div class="f"><label for="name">Your name</label>
    <input id="name" name="name" required minlength="2" maxlength="120" autocomplete="name"><p class="err" data-for="name"></p></div>
  <div class="f"><label for="email">Your email</label>
    <input id="email" name="email" type="email" required maxlength="254" autocomplete="email" inputmode="email"><p class="err" data-for="email"></p></div>
  <div class="f"><label for="subject">Subject</label>
    <input id="subject" name="subject" required minlength="2" maxlength="160"><p class="err" data-for="subject"></p></div>
  <div class="f"><label for="message">Message</label>
    <textarea id="message" name="message" required minlength="10" maxlength="5000"></textarea><p class="err" data-for="message"></p></div>

  <p class="privacy-note">Your IP address, approximate location and browser details are sent with the message. See the <a href="<?= htmlspecialchars($privacy_url, ENT_QUOTES) ?>">privacy policy</a>.</p>

  <div class="slide" id="slide" aria-label="Slide to send">
    <div class="fill"></div>
    <div class="hint"><span class="txt">Slide to send</span><span class="chev">&rsaquo;&rsaquo;</span></div>
    <div class="knob" id="knob" role="button" tabindex="0" aria-describedby="kbdhint" aria-label="Slide to send. Drag to the right, or hold Space.">
      <svg viewBox="0 0 40 40" aria-hidden="true">
        <path class="shackle" d="M14 18v-4a6 6 0 0 1 12 0v4" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <rect x="10" y="18" width="20" height="15" rx="3.5" fill="currentColor"/>
        <circle cx="20" cy="24.5" r="2.4" fill="var(--cf-knob,var(--cf-accent,#0f172a))"/><rect x="18.9" y="25.5" width="2.2" height="5" rx=".8" fill="var(--cf-knob,var(--cf-accent,#0f172a))"/>
      </svg>
    </div>
  </div>
  <p class="kbd" id="kbdhint">Drag the lock all the way across to send. Keyboard: focus the lock and hold <kbd>Space</kbd>.</p>
  <div class="status" id="status" role="status" aria-live="polite"></div>
</form>
