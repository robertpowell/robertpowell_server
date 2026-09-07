/* Post editor: image upload via button, drag-and-drop onto the body, or paste.
   Uploads go to the URL in the textarea's data-upload-url; the server returns
   {saved:[{markdown,...}], errors:[...]} and the Markdown is inserted at the cursor. */
(function () {
  var ta = document.getElementById('body');
  if (!ta) return;
  var url = ta.getAttribute('data-upload-url');
  var postId = ta.getAttribute('data-post-id') || '';
  var input = document.getElementById('upl-files');
  var btn = document.getElementById('upl-btn');
  var status = document.getElementById('upl-status');
  var busy = false;

  function say(msg, err) {
    status.textContent = msg;
    status.className = 'upl-status' + (err ? ' err' : '');
  }

  function insert(text) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var before = v.slice(0, s), after = v.slice(e);
    if (before && !/\n$/.test(before)) text = '\n' + text;
    if (after && !/^\n/.test(after)) text = text + '\n';
    ta.value = before + text + after;
    var pos = before.length + text.length;
    ta.setSelectionRange(pos, pos);
    ta.focus();
  }

  function send(files) {
    files = Array.prototype.filter.call(files, function (f) { return /^image\//.test(f.type); });
    if (!files.length || busy) return;
    busy = true;
    say('Uploading ' + files.length + ' image' + (files.length > 1 ? 's' : '') + '…');
    var fd = new FormData();
    files.forEach(function (f) { fd.append('images', f, f.name); });
    if (postId) fd.append('post_id', postId);
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        var j = res.j || {};
        if (j.saved && j.saved.length) {
          insert(j.saved.map(function (s) { return s.markdown; }).join('\n\n'));
        }
        var n = (j.saved || []).length;
        var msg = n ? n + ' added' : '';
        if (j.errors && j.errors.length) msg += (msg ? '; ' : '') + j.errors.join('; ');
        if (j.error) msg = j.error;
        say(msg || 'Nothing uploaded', !!(j.error || (j.errors && j.errors.length)));
      })
      .catch(function () { say('Upload failed: network error', true); })
      .then(function () { busy = false; input.value = ''; });
  }

  btn.addEventListener('click', function () { input.click(); });
  input.addEventListener('change', function () { send(input.files); });

  ta.addEventListener('dragover', function (e) { e.preventDefault(); ta.classList.add('drop'); });
  ta.addEventListener('dragleave', function () { ta.classList.remove('drop'); });
  ta.addEventListener('drop', function (e) {
    ta.classList.remove('drop');
    if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); send(e.dataTransfer.files); }
  });
  ta.addEventListener('paste', function (e) {
    var items = e.clipboardData && e.clipboardData.items, files = [];
    if (!items) return;
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind === 'file' && /^image\//.test(items[i].type)) files.push(items[i].getAsFile());
    }
    if (files.length) { e.preventDefault(); send(files); }
  });
})();
