/* Theme and text-size controls for the blog. Loaded synchronously in <head> so the first part runs
   before the stylesheet paints (no flash of the wrong theme); the DOM-dependent part waits for
   DOMContentLoaded. Lives in a file so the CSP can be script-src 'self' with no inline hashes. */

/* 1. Pre-paint: apply the stored theme and size before anything renders. */
(function(){try{var t=localStorage.getItem('ropo_theme');
if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);
var z=localStorage.getItem('ropo_size');
if(z&&z!=='m')document.documentElement.setAttribute('data-size',z);}catch(e){}})();

/* 2. Forms with data-confirm ask before submitting (the CSP forbids inline onsubmit handlers). */
document.addEventListener('submit',function(e){var f=e.target;if(f&&f.dataset&&f.dataset.confirm&&!confirm(f.dataset.confirm))e.preventDefault();});

/* 3. Buttons: theme (light / auto / dark) and text size (s / m / l / xl). */
document.addEventListener('DOMContentLoaded',function(){
  var root=document.documentElement, btns=document.querySelectorAll('[data-theme-set]');
  function current(){try{return localStorage.getItem('ropo_theme')||'system';}catch(e){return 'system';}}
  function paint(v){
    if(v==='system'){root.removeAttribute('data-theme');}
    else{root.setAttribute('data-theme',v);}
    btns.forEach(function(b){b.setAttribute('aria-pressed', String(b.dataset.themeSet===v));});
  }
  btns.forEach(function(b){b.addEventListener('click',function(){
    var v=b.dataset.themeSet;
    try{ v==='system' ? localStorage.removeItem('ropo_theme') : localStorage.setItem('ropo_theme',v); }catch(e){}
    paint(v);
  });});
  paint(current());

  var STEPS=['s','m','l','xl'], DEF=1;
  var minus=document.querySelector('[data-size-step="-1"]'),
      plus =document.querySelector('[data-size-step="1"]'),
      reset=document.querySelector('[data-size-reset]');
  function sIndex(){
    var v; try{v=localStorage.getItem('ropo_size');}catch(e){}
    var i=STEPS.indexOf(v); return i<0?DEF:i;
  }
  function sPaint(i){
    var v=STEPS[i];
    if(v==='m'){root.removeAttribute('data-size');}else{root.setAttribute('data-size',v);}
    try{ v==='m' ? localStorage.removeItem('ropo_size') : localStorage.setItem('ropo_size',v); }catch(e){}
    if(minus) minus.disabled = (i===0);
    if(plus)  plus.disabled  = (i===STEPS.length-1);
    if(reset){
      reset.setAttribute('aria-pressed', String(i!==DEF));
      reset.title = (i===DEF) ? 'Normal text size' : 'Reset text size';
    }
  }
  [minus,plus].forEach(function(b){ if(!b) return;
    b.addEventListener('click',function(){
      var i=sIndex()+parseInt(b.dataset.sizeStep,10);
      sPaint(Math.max(0,Math.min(STEPS.length-1,i)));
    });
  });
  if(reset) reset.addEventListener('click',function(){ sPaint(DEF); });
  sPaint(sIndex());
});
