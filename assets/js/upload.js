/**
 * Version 39: every file chosen for upload is made smaller first, in the browser, before it is sent and saved.
 *   - a picture (JPG, PNG, WEBP, BMP) or a PDF of 2 MB up to 6 MB becomes less than 1 MB;
 *   - one of 6 MB and above becomes less than 2 MB;
 *   - a smaller one that is still more than the server takes is brought under that limit.
 * A picture is drawn again smaller (a PNG with see-through parts stays a PNG); each page of a PDF is drawn as a picture
 * (pdf.js, in assets/vendor/pdfjs) and the pages are put back together as a new PDF. The new file keeps the name; when
 * it would not be smaller, the file stays as chosen. Other files (Word, Excel ...) are sent as they are; one larger than
 * the server takes is refused at once with a message. A form waits until the files are ready before it is sent.
 * An input marked data-no-shrink (the backup and the CSV import) is left alone.
 */
(function () {
  'use strict';
  const MB = 1024 * 1024;
  const me = document.currentScript ? document.currentScript.src : '';
  const vendor = me ? new URL('../vendor/pdfjs/', me).href : '';
  const serverMax = () => Number(document.body && document.body.dataset.uploadMax) || 0;

  /** the size a file is brought under, or 0 when it stays as it is */
  const targetFor = (size) => {
    let t = 0;
    if (size >= 6 * MB) t = 2 * MB - 48 * 1024;
    else if (size >= 2 * MB) t = MB - 24 * 1024;
    const max = serverMax();
    if (max && size > max * 0.97) t = Math.min(t || Infinity, Math.floor(max * 0.9));
    return t;
  };
  const kindOf = (f) => {
    if (/^image\/(jpeg|pjpeg|png|webp|bmp)$/i.test(f.type) || /\.(jpe?g|png|webp|bmp)$/i.test(f.name)) return 'image';
    if (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)) return 'pdf';
    return '';
  };
  const mb = (n) => (n >= MB ? (n / MB).toFixed(n >= 10 * MB ? 0 : 1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');
  const skip = (input) => input.hasAttribute('data-no-shrink') || /^(\s*(\.csv|\.sql|\.gz|text\/csv|text\/plain|application\/sql|application\/gzip)\s*,?)+$/i.test(input.accept || '');
  const toBlob = (canvas, type, q) => new Promise((res, rej) => canvas.toBlob((b) => (b ? res(b) : rej(new Error('the picture could not be made'))), type, q));

  // ------------------------------------------------------------------ pictures
  const loadImage = (file) => new Promise((res, rej) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => { URL.revokeObjectURL(url); res(img); };
    img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('the picture could not be read')); };
    img.src = url;
  });
  async function shrinkImage(file, target) {
    let src = null;
    try { src = await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { src = await loadImage(file); }
    const W = src.width;
    const H = src.height;
    // a PNG with see-through parts (a seal, a logo) stays a PNG, so it keeps them
    let alpha = false;
    if (/png$/i.test(file.type) || /\.png$/i.test(file.name)) {
      const s = Math.min(1, 256 / Math.max(W, H));
      const c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(W * s));
      c.height = Math.max(1, Math.round(H * s));
      const x = c.getContext('2d');
      x.drawImage(src, 0, 0, c.width, c.height);
      const d = x.getImageData(0, 0, c.width, c.height).data;
      for (let i = 3; i < d.length; i += 4) { if (d[i] < 250) { alpha = true; break; } }
    }
    let maxDim = Math.min(3200, Math.max(W, H));
    let q = 0.86;
    let best = null;
    for (let i = 0; i < 9; i++) {
      const s = Math.min(1, maxDim / Math.max(W, H));
      const c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(W * s));
      c.height = Math.max(1, Math.round(H * s));
      const x = c.getContext('2d');
      if (!alpha) { x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); }
      x.imageSmoothingQuality = 'high';
      x.drawImage(src, 0, 0, c.width, c.height);
      const b = await toBlob(c, alpha ? 'image/png' : 'image/jpeg', q);
      if (!best || b.size < best.size) best = b;
      if (b.size <= target) break;
      const r = Math.sqrt(target / b.size);
      if (!alpha && q > 0.66) q = Math.max(0.6, q - 0.1);
      else maxDim = Math.max(320, Math.round(maxDim * Math.max(0.55, Math.min(0.9, r * 0.97))));
    }
    if (src.close) src.close();
    const name = alpha ? file.name : file.name.replace(/\.(png|webp|bmp|jpe?g)$/i, '') + '.jpg';
    return new File([best], name, { type: alpha ? 'image/png' : 'image/jpeg', lastModified: Date.now() });
  }

  // ------------------------------------------------------------------ PDF
  let pdfjs = null;
  const loadPdfJs = async () => {
    if (pdfjs) return pdfjs;
    if (!vendor) throw new Error('the PDF reader is missing');
    const lib = await import(vendor + 'pdf.min.js');
    lib.GlobalWorkerOptions.workerSrc = vendor + 'pdf.worker.min.js';
    pdfjs = lib;
    return lib;
  };
  const enc = new TextEncoder();
  /** a PDF of one JPEG picture a page, each page the size it had */
  function buildPdf(pages) {
    const parts = [];
    const offsets = [];
    let pos = 0;
    const push = (x) => { const u = typeof x === 'string' ? enc.encode(x) : x; parts.push(u); pos += u.length; };
    const obj = (n, body, stream) => {
      offsets[n] = pos;
      push(n + ' 0 obj\n' + body + '\n');
      if (stream) { push('stream\n'); push(stream); push('\nendstream\n'); }
      push('endobj\n');
    };
    push('%PDF-1.4\n%âãÏÓ\n');
    const kids = pages.map((p, i) => (3 + i * 3) + ' 0 R').join(' ');
    obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
    obj(2, '<< /Type /Pages /Kids [' + kids + '] /Count ' + pages.length + ' >>');
    pages.forEach((p, i) => {
      const n = 3 + i * 3;
      const w = p.pw.toFixed(2);
      const h = p.ph.toFixed(2);
      const content = enc.encode('q ' + w + ' 0 0 ' + h + ' 0 0 cm /Im0 Do Q');
      obj(n, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + w + ' ' + h + '] /Resources << /XObject << /Im0 ' + (n + 1) + ' 0 R >> >> /Contents ' + (n + 2) + ' 0 R >>');
      obj(n + 1, '<< /Type /XObject /Subtype /Image /Width ' + p.w + ' /Height ' + p.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + p.jpg.length + ' >>', p.jpg);
      obj(n + 2, '<< /Length ' + content.length + ' >>', content);
    });
    const count = 3 + pages.length * 3;
    const xref = pos;
    let x = 'xref\n0 ' + count + '\n0000000000 65535 f \n';
    for (let i = 1; i < count; i++) x += String(offsets[i]).padStart(10, '0') + ' 00000 n \n';
    push(x + 'trailer\n<< /Size ' + count + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n');
    return new Blob(parts, { type: 'application/pdf' });
  }
  // dots per inch and JPEG quality, from the clearest to the smallest
  const STEPS = [[150, 0.72], [132, 0.66], [116, 0.6], [100, 0.56], [88, 0.5], [76, 0.46], [66, 0.42], [56, 0.38]];
  async function shrinkPdf(file, target, tell) {
    const lib = await loadPdfJs();
    const doc = await lib.getDocument({ data: new Uint8Array(await file.arrayBuffer()), isEvalSupported: false }).promise;
    const n = doc.numPages;
    const per = target / Math.max(1, n);
    let si = per > 260 * 1024 ? 0 : per > 170 * 1024 ? 1 : per > 120 * 1024 ? 2 : per > 85 * 1024 ? 3 : per > 60 * 1024 ? 4 : 5;
    let best = null;
    try {
      while (si < STEPS.length) {
        const [dpi, q] = STEPS[si];
        const pages = [];
        for (let i = 1; i <= n; i++) {
          tell('page ' + i + ' of ' + n);
          const page = await doc.getPage(i);
          const base = page.getViewport({ scale: 1 });
          let scale = dpi / 72;
          const longest = Math.max(base.width, base.height) * scale;
          if (longest > 2600) scale *= 2600 / longest;
          const vp = page.getViewport({ scale });
          const c = document.createElement('canvas');
          c.width = Math.max(1, Math.floor(vp.width));
          c.height = Math.max(1, Math.floor(vp.height));
          const x = c.getContext('2d');
          x.fillStyle = '#fff';
          x.fillRect(0, 0, c.width, c.height);
          await page.render({ canvasContext: x, viewport: vp }).promise;
          const b = await toBlob(c, 'image/jpeg', q);
          pages.push({ jpg: new Uint8Array(await b.arrayBuffer()), w: c.width, h: c.height, pw: base.width, ph: base.height });
          page.cleanup();
          c.width = 0;
          c.height = 0;
        }
        const out = buildPdf(pages);
        if (!best || out.size < best.size) best = out;
        if (out.size <= target) break;
        const r = out.size / target;
        si += r > 2.4 ? 3 : r > 1.5 ? 2 : 1;
      }
    } finally {
      await doc.destroy();
    }
    return new File([best], file.name, { type: 'application/pdf', lastModified: Date.now() });
  }

  // ------------------------------------------------------------------ the inputs
  /** where a file input says what is happening: just after it (after its drop box, if it has one) */
  const noteFor = (input) => {
    if (input._shrinkNote) return input._shrinkNote;
    const host = input.closest('[data-dropzone], .att-drop, label') || input;
    const n = document.createElement('div');
    n.className = 'shrink-note';
    n.setAttribute('role', 'status');
    n.setAttribute('aria-live', 'polite');
    n.hidden = true;
    host.insertAdjacentElement('afterend', n);
    input._shrinkNote = n;
    return n;
  };
  const say = (input, html, tone) => {
    const n = noteFor(input);
    n.className = 'shrink-note' + (tone ? ' is-' + tone : '');
    n.innerHTML = html;
    n.hidden = !html;
  };
  const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const busy = new Map();   // form => files still being made smaller

  /** makes the chosen files smaller; returns the files to keep in the input, or null when one cannot be sent */
  async function work(input, files) {
    const out = [];
    const done = [];
    const bad = [];
    for (const f of files) {
      const t = targetFor(f.size);
      const k = kindOf(f);
      if (!t) { out.push(f); continue; }
      if (!k) {
        const max = serverMax();
        if (max && f.size > max) bad.push(f.name + ' is ' + mb(f.size) + ': the server takes up to ' + mb(max) + '. Save it as a PDF or a picture, or make it smaller, then choose it again.');
        out.push(f);
        continue;
      }
      try {
        say(input, '<span class="spinner-border spinner-border-sm"></span> Making ' + esc(f.name) + ' (' + mb(f.size) + ') smaller before it is sent...', 'busy');
        const r = k === 'image' ? await shrinkImage(f, t)
          : await shrinkPdf(f, t, (what) => say(input, '<span class="spinner-border spinner-border-sm"></span> Making ' + esc(f.name) + ' (' + mb(f.size) + ') smaller: ' + esc(what) + '...', 'busy'));
        if (r.size < f.size) { out.push(r); done.push(esc(f.name) + ': ' + mb(f.size) + ' to ' + mb(r.size)); } else { out.push(f); }
        const max = serverMax();
        if (max && Math.min(r.size, f.size) > max) bad.push(f.name + ' is still ' + mb(Math.min(r.size, f.size)) + ' after making it smaller: the server takes up to ' + mb(max) + '. Split it into parts, then choose them.');
      } catch (err) {
        out.push(f);
        const max = serverMax();
        if (max && f.size > max) bad.push(f.name + ' could not be made smaller (' + err.message + ') and is more than the ' + mb(max) + ' the server takes.');
      }
    }
    say(input, (done.length ? '<i class="bi bi-check2-circle"></i> Made smaller before sending: ' + done.join('; ') + '.' : '')
      + (bad.length ? (done.length ? '<br>' : '') + '<i class="bi bi-exclamation-triangle"></i> ' + bad.map(esc).join('<br>') : ''), bad.length ? 'bad' : (done.length ? 'ok' : ''));
    input.setCustomValidity(bad.length ? bad[0] : '');
    return out;
  }

  /** the files go back into the input, and its own scripts (drop boxes, attachment lists) hear of them */
  const put = (input, files) => {
    try {
      const dt = new DataTransfer();
      files.forEach((f) => dt.items.add(f));
      input.files = dt.files;
    } catch (e) { /* an older browser: the files stay as chosen */ }
    input._shrinkPass = true;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input._shrinkPass = false;
  };
  const needsWork = (files) => Array.from(files || []).some((f) => targetFor(f.size) > 0);

  async function handle(input, files) {
    const form = input.form;
    if (form) busy.set(form, (busy.get(form) || 0) + 1);
    try {
      put(input, await work(input, files));
    } finally {
      if (form) {
        const left = (busy.get(form) || 1) - 1;
        if (left > 0) busy.set(form, left); else busy.delete(form);
        if (!left && form._shrinkSubmit) {
          const s = form._shrinkSubmit;
          form._shrinkSubmit = null;
          if (form.reportValidity()) { if (s.submitter) form.requestSubmit(s.submitter); else form.requestSubmit(); }
        }
      }
    }
  }

  // a file chosen in a file box: held back from the page's other scripts until it is ready
  document.addEventListener('change', (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || input._shrinkPass || skip(input)) return;
    input.setCustomValidity('');
    if (!input.files || !input.files.length) { say(input, '', ''); return; }
    if (!needsWork(input.files)) { say(input, '', ''); return; }
    e.stopImmediatePropagation();
    handle(input, Array.from(input.files));
  }, true);

  // a file dropped on a drop box: made smaller, then put into its file box
  document.addEventListener('drop', (e) => {
    const zone = e.target && e.target.closest ? e.target.closest('[data-dropzone], [data-att], .att-drop') : null;
    const files = e.dataTransfer && e.dataTransfer.files;
    if (!zone || !files || !files.length || !needsWork(files)) return;
    const input = (zone.matches('[data-att]') ? zone : zone.closest('[data-att]') || zone).querySelector('input[type=file]');
    if (!input || skip(input)) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    zone.classList.remove('is-over');
    handle(input, Array.from(files));
  }, true);

  // a form is sent only once its files are ready
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!busy.get(form)) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    form._shrinkSubmit = { submitter: e.submitter || null };
  }, true);
})();
