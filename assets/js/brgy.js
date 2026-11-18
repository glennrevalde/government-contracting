/* G-SMaRT Integrated Accounting and Government Contracting System - Barangay Contracts (no build step): file boxes, modal fill-ins, the price schedule grid,
   quantity grids, the collection calculator, the form / template editor, the Process tab of the SOP, the live preview of a template's format
   and the placing of values on pre-printed paper. */
(function () {
  'use strict';

  const F = window.AppFmt || { num: (v) => parseFloat(String(v).replace(/,/g, '')) || 0, r2: (v) => Math.round(v * 100) / 100, fmt: (v) => Number(v).toFixed(2) };
  const num = (v) => F.num(v);
  const r2 = (v) => F.r2(v);
  const fmt = (v) => F.fmt(v);
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  // ------------------------------------------------------------------ file boxes: drop a file or tap to choose
  $$('[data-dropzone]').forEach((zone) => {
    const input = $('input[type=file]', zone);
    const name = $('.dz-name', zone);
    const show = () => {
      const f = input.files && input.files[0];
      zone.classList.toggle('has-file', !!f);
      if (name) name.textContent = f ? f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)' : '';
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, () => zone.classList.remove('is-over')));
    zone.addEventListener('drop', (e) => {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        show();
      }
    });
  });

  // ------------------------------------------------------------------ the phase track shows the current phase (it scrolls on a phone)
  $$('.phase-track').forEach((track) => {
    const now = $('[aria-current="step"]', track);
    if (now && track.scrollWidth > track.clientWidth) track.scrollLeft = Math.max(0, now.offsetLeft - track.clientWidth / 2 + now.offsetWidth / 2);
  });
  // the tab row scrolls to the open tab
  $$('.proj-tabs').forEach((tabs) => {
    const act = $('.nav-link.active', tabs);
    if (act && tabs.scrollWidth > tabs.clientWidth) tabs.scrollLeft = Math.max(0, act.offsetLeft - 24);
  });

  // ------------------------------------------------------------------ modals filled from the button that opened them
  // data-req="5" on the button fills <input name="req_id"> and [data-fill-req]; data-req-name fills [data-fill-req-name] ...
  document.addEventListener('show.bs.modal', (e) => {
    const btn = e.relatedTarget;
    const modal = e.target;
    if (!btn || !btn.dataset) return;
    Object.keys(btn.dataset).forEach((k) => {
      if (k.startsWith('bs')) return;
      const kebab = k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());
      const val = btn.dataset[k];
      $$('[data-fill-' + kebab + ']', modal).forEach((el) => { if ('value' in el && el.tagName !== 'SPAN' && el.tagName !== 'DIV') el.value = val; else el.textContent = val; });
      const hidden = $('input[name="' + kebab.replace(/-/g, '_') + '_id"]', modal);
      if (hidden) hidden.value = val;
    });
    const comp = $('[data-company-box]', modal);
    if (comp) comp.hidden = btn.dataset.company !== '1';
    const valid = $('[data-validity-box]', modal);
    if (valid) valid.classList.toggle('is-wanted', btn.dataset.validity === '1');
  });

  // ------------------------------------------------------------------ price schedule (Projects > Price schedule)
  const grid = $('#linesGrid');
  if (grid) {
    const tbody = $('tbody', grid);
    const tpl = $('#tplLine');
    const items = JSON.parse(grid.dataset.items || '{}');
    const costs = grid.dataset.costs === '1';
    const meter = $('.abc-meter');
    const abc = meter ? num(meter.dataset.abc) : 0;
    let n = 0;
    const field = (tr, k) => $('[data-k="' + k + '"]', tr);

    const priceOf = (tr) => {
      const p = field(tr, 'unit_price');
      if (p && p.value.trim() !== '') return num(p.value);
      if (!costs) return 0;
      const c = num(field(tr, 'unit_cost').value);
      const m = num(field(tr, 'markup_pct').value);
      return c > 0 ? Math.round(c * (1 + m / 100) * 10000) / 10000 : 0;
    };
    const recalc = () => {
      let total = 0;
      $$('tr', tbody).forEach((tr) => {
        const q = num(field(tr, 'qty').value);
        const p = priceOf(tr);
        const a = r2(q * p);
        total += a;
        field(tr, 'amount').textContent = fmt(a);
        const pr = field(tr, 'unit_price');
        if (pr) pr.placeholder = costs && pr.value.trim() === '' && p > 0 ? fmt(p) : '';
      });
      total = r2(total);
      $('#lgTotal').textContent = fmt(total);
      if (meter) {
        const fill = $('#abcFill');
        const text = $('#abcText');
        if (abc > 0) {
          const pct = total / abc * 100;
          fill.style.width = Math.min(100, pct) + '%';
          meter.classList.toggle('is-over', total > abc + 0.004);
          text.textContent = fmt(total) + ' of the ABC ' + fmt(abc) + ' (' + pct.toFixed(1) + '%)' + (total > abc + 0.004 ? ': above the ABC' : '');
        } else {
          text.textContent = 'No ABC entered';
        }
      }
    };
    const addRow = (data) => {
      const tr = tpl.content.firstElementChild.cloneNode(true);
      const i = n++;
      $$('[data-k]', tr).forEach((el) => {
        const k = el.dataset.k;
        if (el.tagName === 'TD') return;
        el.name = 'lines[' + i + '][' + k + ']';
        if (data && data[k] !== undefined && data[k] !== null) el.value = data[k];
      });
      tbody.appendChild(tr);
      if (window.AppCombo) window.AppCombo.init(tr);
      const item = field(tr, 'item_id');
      item.addEventListener('change', () => {
        const it = items[item.value];
        if (!it) return;
        const d = field(tr, 'description');
        if (!d.value) d.value = it.d;
        const u = field(tr, 'unit');
        if (!u.value) u.value = it.u || '';
        if (costs && it.c && !field(tr, 'unit_cost').value) field(tr, 'unit_cost').value = it.c;
        if (it.t) field(tr, 'tax_code_id').value = it.t;
        field(tr, 'line_kind').value = it.k === 'service' ? 'service' : 'goods';
        recalc();
      });
      $('[data-del]', tr).addEventListener('click', () => { tr.remove(); recalc(); });
      if (!data) tr.classList.add('row-new');
      return tr;
    };
    tbody.addEventListener('input', recalc);
    (window.BRGY_LINES || []).forEach((l) => addRow(l));
    if (!tbody.children.length) for (let i = 0; i < 3; i++) addRow(null);
    $$('[data-add]').forEach((b) => b.addEventListener('click', () => {
      let last = null;
      for (let i = 0; i < parseInt(b.dataset.add, 10); i++) last = addRow(null);
      if (last) field(last, 'description').focus();
    }));
    recalc();
  }

  // ------------------------------------------------------------------ quantity grids: "everything left", limits, amounts
  $$('[data-fill-all]').forEach((btn) => btn.addEventListener('click', () => {
    $$('input[data-max]', $(btn.dataset.fillAll)).forEach((inp) => {
      if (!inp.disabled && num(inp.dataset.max) > 0) inp.value = String(num(inp.dataset.max));
      inp.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }));
  $$('input[data-max]').forEach((inp) => {
    const check = () => {
      const over = inp.value.trim() !== '' && num(inp.value) > num(inp.dataset.max) + 0.00001;
      inp.classList.toggle('is-invalid', over);
      inp.title = over ? 'More than the ' + inp.dataset.max + ' available' : '';
    };
    inp.addEventListener('input', check);
    check();
  });
  $$('table[data-sum]').forEach((t) => {
    const out = $(t.dataset.sum);
    const calc = () => {
      let tot = 0;
      $$('tbody tr', t).forEach((tr) => {
        const inp = $('input[data-price]', tr);
        const cell = $('[data-amount]', tr);
        if (!inp || !cell) return;
        const a = r2(num(inp.value) * num(inp.dataset.price));
        cell.textContent = a ? fmt(a) : '';
        tot += a;
      });
      if (out) out.textContent = fmt(r2(tot));
    };
    t.addEventListener('input', calc);
    calc();
  });
  $$('[data-accept-all]').forEach((btn) => btn.addEventListener('click', () => {
    $$('tbody tr, tr', $(btn.dataset.acceptAll)).forEach((tr) => {
      const d = $('[data-delivered]', tr);
      const acc = $('input[data-acc]', tr);
      if (!d || !acc) return;
      acc.value = d.dataset.delivered;
      const rej = acc.closest('tr').querySelector('input[name$="[rejected]"]');
      if (rej) rej.value = '0';
    });
  }));

  // ------------------------------------------------------------------ collection: what is settled, and "as expected"
  const col = $('#colForm');
  if (col) {
    const fields = $$('[data-col]', col);
    const settled = $('#colSettled');
    const left = $('#colLeft');
    const fill = $('#fillExpected');
    const open = fill ? num(fill.dataset.open) : 0;
    const calc = () => {
      const tot = r2(fields.reduce((s, el) => s + num(el.value), 0));
      settled.textContent = fmt(tot);
      if (left) {
        const diff = r2(open - tot);
        left.textContent = Math.abs(diff) < 0.005 ? 'Settles the billing in full.' : (diff > 0 ? fmt(diff) + ' stays open.' : 'More than is open by ' + fmt(-diff) + '.');
        left.classList.toggle('text-danger', diff < -0.004);
      }
    };
    col.addEventListener('input', calc);
    if (fill) fill.addEventListener('click', () => {
      const set = (n, v) => { const el = $('[name="' + n + '"]', col); if (el) el.value = v > 0 ? v.toFixed(2) : ''; };
      const ewt = num(fill.dataset.ewt), vat = num(fill.dataset.vat), ret = num(fill.dataset.ret), ld = num(fill.dataset.ld);
      set('ewt_amount', ewt); set('vatwh_amount', vat); set('retention_amount', ret); set('ld_amount', ld);
      set('cash_received', r2(open - ewt - vat - ret - ld));
      calc();
    });
    calc();
  }

  // ------------------------------------------------------------------ editor of forms and templates
  const editor = $('#formEditor');
  if (editor) {
    const store = $('#formBody');
    const form = editor.closest('form') || store.form;
    let lastRange = null;
    const keepRange = () => {
      const sel = window.getSelection();
      if (sel.rangeCount && editor.contains(sel.anchorNode)) lastRange = sel.getRangeAt(0).cloneRange();
    };
    document.addEventListener('selectionchange', keepRange);
    const restore = () => {
      editor.focus();
      if (lastRange) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(lastRange);
      }
    };
    try { document.execCommand('styleWithCSS', false, true); } catch (e) { /* older browsers */ }
    $$('[data-cmd]').forEach((b) => b.addEventListener('click', () => { restore(); document.execCommand(b.dataset.cmd, false, null); keepRange(); }));
    const block = $('[data-cmd-block]');
    if (block) block.addEventListener('change', () => { restore(); document.execCommand('formatBlock', false, block.value); block.value = 'p'; });
    const insertHtml = (html) => { restore(); document.execCommand('insertHTML', false, html); keepRange(); };
    const tb = $('[data-insert-table]');
    if (tb) tb.addEventListener('click', () => {
      const rows = Math.max(1, Math.min(40, parseInt(window.prompt('How many rows (besides the heading)?', '3') || '0', 10)));
      const cols = Math.max(1, Math.min(10, parseInt(window.prompt('How many columns?', '4') || '0', 10)));
      if (!rows || !cols) return;
      let h = '<table class="ft"><thead><tr>' + '<th>&nbsp;</th>'.repeat(cols) + '</tr></thead><tbody>';
      for (let r = 0; r < rows; r++) h += '<tr>' + '<td>&nbsp;</td>'.repeat(cols) + '</tr>';
      insertHtml(h + '</tbody></table><p></p>');
    });
    const br = $('[data-insert-break]');
    if (br) br.addEventListener('click', () => insertHtml('<div class="page-break" style="page-break-after:always;break-after:page"></div><p></p>'));
    $$('[data-insert-field]').forEach((b) => b.addEventListener('click', () => {
      insertHtml('{{' + b.dataset.insertField + '}}');
      const oc = b.closest('.offcanvas');
      if (oc && window.bootstrap) window.bootstrap.Offcanvas.getOrCreateInstance(oc).hide();
    }));
    const ff = $('[data-field-filter]');
    if (ff) ff.addEventListener('input', () => {
      const q = ff.value.trim().toLowerCase();
      $$('.fp-item').forEach((it) => { it.hidden = q !== '' && !it.textContent.toLowerCase().includes(q); });
      $$('.fp-group').forEach((g) => { g.hidden = !$$('.fp-item', g).some((it) => !it.hidden); });
    });

    // blanks: list them with a box each; typing there fills every blank of that field in the page
    const gapList = $('#gapList');
    const gapNone = $('#gapNone');
    const listGaps = () => {
      if (!gapList) return;
      const seen = {};
      $$('.ph-missing', editor).forEach((s) => {
        const k = s.dataset.ph || s.textContent;
        if (!seen[k]) seen[k] = s.textContent.replace(/^\[|\]$/g, '');
      });
      const keys = Object.keys(seen);
      gapNone.hidden = keys.length > 0;
      const have = $$('[data-gap]', gapList).map((el) => el.dataset.gap);
      if (have.join('|') === keys.join('|')) return;
      gapList.innerHTML = '';
      keys.forEach((k) => {
        const wrap = document.createElement('div');
        wrap.className = 'mb-2';
        wrap.dataset.gap = k;
        const lab = document.createElement('label');
        lab.className = 'form-label';
        lab.textContent = seen[k];
        const inp = document.createElement('input');
        inp.className = 'form-control form-control-sm';
        inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); inp.dispatchEvent(new Event('change')); } });
        inp.addEventListener('change', () => {
          if (inp.value.trim() === '') return;
          $$('.ph-missing', editor).filter((s) => (s.dataset.ph || s.textContent) === k).forEach((s) => s.replaceWith(document.createTextNode(inp.value.trim())));
          listGaps();
        });
        wrap.appendChild(lab);
        wrap.appendChild(inp);
        gapList.appendChild(wrap);
      });
    };
    // a click on a blank in the page selects it as plain text, so typing replaces it
    editor.addEventListener('click', (e) => {
      const s = e.target.closest('.ph-missing');
      if (!s || !editor.contains(s)) return;
      const t = document.createTextNode(s.textContent);
      s.replaceWith(t);
      const r = document.createRange();
      r.selectNodeContents(t);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(r);
      listGaps();
    });
    editor.addEventListener('input', listGaps);
    listGaps();

    // paste as clean text paragraphs (no foreign styles); the server cleans the HTML again on save
    editor.addEventListener('paste', (e) => {
      const text = (e.clipboardData || window.clipboardData).getData('text/plain');
      if (!text) return;
      e.preventDefault();
      const esc = (t) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
      const paras = text.split(/\r?\n\r?\n/).map((p) => esc(p).replace(/\r?\n/g, '<br>'));
      document.execCommand('insertHTML', false, paras.length > 1 ? '<p>' + paras.join('</p><p>') + '</p>' : paras[0]);
    });

    let dirty = false;
    editor.addEventListener('input', () => { dirty = true; });
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', () => { store.value = editor.innerHTML; dirty = false; });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // paper and orientation change the page shape at once
    const paper = $('select[name="paper"]', form);
    const orient = $('select[name="orientation"]', form);
    const sizes = { A4: [210, 297], Letter: [215.9, 279.4], Folio: [215.9, 330.2], Legal: [215.9, 355.6], A5: [148, 210], HalfLetter: [139.7, 215.9] };
    const shape = () => {
      const sz = sizes[paper ? paper.value : 'A4'] || sizes.A4;
      editor.style.setProperty('--paper-w', (orient && orient.value === 'landscape' ? sz[1] : sz[0]) + 'mm');
    };
    if (paper) paper.addEventListener('change', shape);
    if (orient) orient.addEventListener('change', shape);
  }

  // ------------------------------------------------------------------ Process tab (the SOP): open the step of the link, fill the dialogs
  const openStep = (code) => {
    const el = document.getElementById('step-' + code);
    if (!el) return;
    el.open = true;
    setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 30);
  };
  if (location.hash.startsWith('#step-')) openStep(location.hash.slice(6));
  $$('[data-open-step]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); history.replaceState(null, '', '#step-' + a.dataset.openStep); openStep(a.dataset.openStep); }));

  /** Puts the values of an object into the fields of a form (by name; arrays as tick boxes) */
  const fillForm = (form, obj) => {
    form.reset();
    $$('input[type=hidden]', form).forEach((h) => { if (!['_csrf', 'do', 'tab', 'process', 'print_mode'].includes(h.name)) h.value = ''; });
    Object.keys(obj).forEach((k) => {
      let v = obj[k];
      if (v === null || v === undefined) v = '';
      const many = $$('[name="' + k + '[]"]', form);
      if (many.length) {
        const list = Array.isArray(v) ? v.map(String) : String(v).split(/[,|]/).map((x) => x.trim());
        many.forEach((c) => { c.checked = list.includes(c.value); });
        return;
      }
      $$('[name="' + k + '"]', form).forEach((el) => {
        if (el.type === 'checkbox') el.checked = String(v) === '1' || String(v) === el.value && el.value !== '1' ? true : String(v) === '1';
        else if (el.type === 'radio') el.checked = String(el.value) === String(v);
        else if (el.type !== 'file') el.value = v;
      });
    });
  };
  document.addEventListener('show.bs.modal', (e) => {
    const btn = e.relatedTarget;
    const modal = e.target;
    const form = modal.querySelector('form');
    if (!btn || !btn.dataset || !form) return;
    if (btn.dataset.paperId !== undefined) {
      const pid = $('input[name="paper_id"]', form);
      if (pid) pid.value = btn.dataset.paperId;
      const lbl = $('[data-paper-label]', modal);
      if (lbl) lbl.textContent = btn.dataset.paperName || '';
    }
    if (btn.dataset.step !== undefined) {
      let obj = null;
      try { obj = JSON.parse(btn.dataset.step); } catch (err) { obj = null; }
      if (obj && typeof obj === 'object') {
        // SOP Steps & Papers: a step to edit (or {} for a new one)
        fillForm(form, Object.assign({ is_active: 1 }, obj, { step_id: obj.id || '', checks: String(obj.checks || '').split('|').filter(Boolean).join('\n') }));
      } else {
        const st = $('input[name="step"]', form);
        if (st) st.value = btn.dataset.step;
      }
    }
    if (btn.dataset.paper !== undefined) {
      let obj = {};
      try { obj = JSON.parse(btn.dataset.paper || '{}'); } catch (err) { obj = {}; }
      fillForm(form, Object.assign({ is_active: 1, copies: 1, source: 'prepare', needs_signature: 1 }, obj));
      const code = $('input[name="code"]', form);
      if (code) code.readOnly = !!obj.code;
    }
    if (btn.dataset.canvass !== undefined) {
      let obj = {};
      try { obj = JSON.parse(btn.dataset.canvass || '{}'); } catch (err) { obj = {}; }
      fillForm(form, Object.assign({ remarks: 'passed' }, obj, { canvass_id: obj.id || '' }));
      const co = String(obj.is_company || '0') === '1';
      const isCo = $('input[name="is_company"]', form);
      if (isCo) isCo.value = co ? '1' : '0';
      const tot = $('input[name="total_amount"]', form);
      if (tot) tot.readOnly = co;
      const note = $('[data-company-note]', form);
      if (note) note.hidden = !co;
      const rd = $('input[name="received_date"]', form);
      if (rd && !rd.value) rd.value = new Date().toISOString().slice(0, 10);
    }
  });

  // ------------------------------------------------------------------ Format of a template: live preview
  const fmtForm = $('#fmtForm');
  if (fmtForm) {
    const doc = $('[data-fmt-doc]', fmtForm);
    const css = $('[data-fmt-css]', fmtForm);
    const state = $('#fmtState');
    const paperSel = $('#fPaper');
    const custom = () => $$('[data-custom-size]', fmtForm).forEach((c) => { c.hidden = !paperSel || paperSel.value !== 'Custom'; });
    custom();
    // the parts printed: an unticked part goes in hidden[]
    const syncHidden = () => {
      $$('[data-hidden-sec]', fmtForm).forEach((x) => x.remove());
      $$('[data-section-toggle]', fmtForm).forEach((c) => {
        if (!c.checked) {
          const h = document.createElement('input');
          h.type = 'hidden'; h.name = 'hidden[]'; h.value = c.value; h.dataset.hiddenSec = c.value;
          fmtForm.appendChild(h);
        }
      });
    };
    let timer = null;
    let seq = 0;
    const refresh = () => {
      syncHidden();
      custom();
      const n = ++seq;
      if (state) state.textContent = 'Updating...';
      fetch(fmtForm.dataset.previewUrl, { method: 'POST', body: new FormData(fmtForm), credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => {
          if (n !== seq) return;
          doc.setAttribute('style', d.style);
          doc.className = (doc.dataset.baseClass || doc.className) + (d.cls ? ' ' + d.cls : '');
          doc.innerHTML = d.html;
          if (css) css.textContent = d.css;
          const pl = $('#fmtPaper');
          if (pl) pl.textContent = d.paper;
          if (state) state.textContent = 'Live preview';
        })
        .catch(() => { if (state) state.textContent = 'The preview could not be refreshed'; });
    };
    fmtForm.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refresh, 350); });
    fmtForm.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(refresh, 120); });
    fmtForm.addEventListener('submit', syncHidden);
    const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 120); };

    // the colour of the table headings: none, or the colour chosen
    const thOn = $('[data-th-on]', fmtForm);
    const thColor = $('[data-th-color]', fmtForm);
    const thValue = $('[data-th-value]', fmtForm);
    const syncTh = () => { if (thValue) thValue.value = thOn && thOn.checked && thColor ? thColor.value : ''; };
    if (thOn) thOn.addEventListener('change', syncTh);
    if (thColor) thColor.addEventListener('input', () => { if (thOn) thOn.checked = true; syncTh(); });

    // the letterhead: choosing the company, barangay or the template's own shows its settings
    const lhStyle = $('#fLhStyle');
    const setsEl = $('#lhSets');
    const sets = setsEl ? JSON.parse(setsEl.textContent) : {};
    const lhFields = $('[data-lh-fields]', fmtForm);
    const lhShared = $('[data-lh-shared]', fmtForm);
    const fillLh = (set) => {
      Object.keys(set).forEach((k) => {
        const el = fmtForm.elements.namedItem(k);
        if (!el || el instanceof RadioNodeList) return;
        if (el.type === 'checkbox') el.checked = !!Number(set[k]);
        else el.value = set[k] === null || set[k] === undefined ? '' : String(set[k]);
      });
      ['a', 'b'].forEach((slot) => {
        const box = $('[data-lhpic-img="' + slot + '"]', fmtForm);
        const url = set['pic_' + slot + '_url'];
        if (box) box.innerHTML = url ? '<img src="' + url.replace(/"/g, '&quot;') + '" alt="">' : '<i class="bi bi-image"></i>';
      });
    };
    if (lhStyle) {
      lhStyle.addEventListener('change', () => {
        const v = lhStyle.value;
        if (lhFields) lhFields.hidden = v === 'none';
        if (lhShared) lhShared.hidden = !(v === 'company' || v === 'barangay');
        if (sets[v]) fillLh(sets[v]);
        $$('[data-lh-style-copy]').forEach((x) => { x.value = v; });
      });
    }
    // a picture chosen is uploaded at once (the page comes back with it)
    $$('[data-lhpic-file]', fmtForm).forEach((inp) => {
      inp.addEventListener('change', () => {
        if (!inp.files || !inp.files.length || !inp.form) return;
        if (state) state.textContent = 'Uploading the picture...';
        inp.form.submit();
      });
    });

    // the signatories: add from a list, move, remove; numbered in order
    const list = $('[data-sig-list]', fmtForm);
    const tpl = $('#sigCardTpl');
    const presetsEl = $('#sigPresets');
    const presets = presetsEl ? JSON.parse(presetsEl.textContent) : {};
    let sigSeq = 1000;
    const renumber = () => {
      if (!list) return;
      const cards = $$('[data-sig-card]', list);
      cards.forEach((c, i) => { const no = $('[data-sig-no]', c); if (no) no.textContent = String(i + 1); });
      const empty = $('[data-sig-empty]', fmtForm);
      if (empty) empty.hidden = cards.length > 0;
    };
    const fillCard = (card, b) => {
      $$('input, textarea, select', card).forEach((el) => {
        const m = /\[([a-z]+)\]$/.exec(el.name || '');
        if (!m || !(m[1] in b)) return;
        if (el.type === 'checkbox') el.checked = !!Number(b[m[1]]);
        else el.value = b[m[1]];
      });
      const title = $('[data-sig-title]', card);
      if (title) title.textContent = b.name || b.lead || 'Signatory';
    };
    if (list) {
      renumber();
      list.addEventListener('click', (e) => {
        const card = e.target.closest('[data-sig-card]');
        if (!card) return;
        const mv = e.target.closest('[data-sig-move]');
        if (mv) {
          const dir = Number(mv.dataset.sigMove);
          const sib = dir < 0 ? card.previousElementSibling : card.nextElementSibling;
          if (sib) { if (dir < 0) list.insertBefore(card, sib); else list.insertBefore(sib, card); }
          renumber(); later();
        } else if (e.target.closest('[data-sig-remove]')) {
          card.remove();
          renumber(); later();
        }
      });
      list.addEventListener('input', (e) => {
        if (e.target.matches('[data-sig-name]')) {
          const card = e.target.closest('[data-sig-card]');
          const title = card && $('[data-sig-title]', card);
          if (title) title.textContent = e.target.value || 'Signatory';
        }
      });
    }
    const addBtn = $('[data-sig-add]', fmtForm);
    if (addBtn && list && tpl) {
      addBtn.addEventListener('click', () => {
        const sel = $('[data-sig-preset]', fmtForm);
        const b = presets[sel ? sel.value : ''] || {};
        const wrap = document.createElement('div');
        wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, String(sigSeq++));
        const card = wrap.firstElementChild;
        list.appendChild(card);
        fillCard(card, b);
        renumber(); later();
        const nm = $('[data-sig-name]', card);
        if (nm) nm.focus();
      });
    }
  }

  // ------------------------------------------------------------------ Pre-printed paper: placing the values in millimetres
  const posData = $('#posData');
  const canvas = $('#posCanvas');
  if (posData && canvas) {
    const D = JSON.parse(posData.textContent);
    const P = D.P;
    const form = $('#posForm');
    const side = { props: $('#posProps'), list: $('#posList') };
    let scale = 0;
    let sel = null; // {kind: 'f', i} or {kind: 't', i, c}
    const snap = (v) => Math.round(v * 2) / 2;
    const size = () => {
      const pp = D.papers[P.paper] || D.papers.A4;
      let w = P.paper === 'Custom' ? (parseFloat(P.width_mm) || 210) : pp[0];
      let h = P.paper === 'Custom' ? (parseFloat(P.height_mm) || 297) : pp[1];
      if (P.orientation === 'landscape') [w, h] = [h, w];
      return [w, h];
    };
    const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const showVals = () => $('#posShowVals') && $('#posShowVals').checked;
    const fieldText = (f) => {
      if (f.key.startsWith('text:')) return f.text || 'Text';
      const v = D.vals.f[f.key];
      return showVals() && v ? v : (D.fields[f.key] || f.key).replace(/ \(.*\)$/, '');
    };
    const ptpx = (pt) => pt * 0.3528 * scale;
    const render = () => {
      const [w, h] = size();
      if (!scale) scale = Math.max(1.4, Math.min(3.2, ($('.pos-scroll').clientWidth - 24) / w));
      canvas.style.width = (w * scale) + 'px';
      canvas.style.height = (h * scale) + 'px';
      canvas.style.fontFamily = D.fonts[P.font] || 'Arial';
      canvas.style.backgroundImage = D.bg && $('#posShowBg').checked ? 'url("' + D.bg + '")' : 'none';
      canvas.style.setProperty('--grid', (10 * scale) + 'px');
      let html = '';
      P.fields.forEach((f, i) => {
        const on = sel && sel.kind === 'f' && sel.i === i;
        html += '<div class="pos-chip' + (on ? ' is-sel' : '') + (f.key.startsWith('text:') ? ' is-text' : '') + '" data-k="f" data-i="' + i + '" tabindex="0" style="left:' + (f.x * scale) + 'px;top:' + (f.y * scale) + 'px;width:' + (f.w * scale) + 'px;font-size:'
          + ptpx(parseFloat(f.size) || parseFloat(P.font_size) || 10) + 'px;text-align:' + f.align + ';font-weight:' + (f.bold ? 700 : 400) + '">' + esc(f.upper ? fieldText(f).toUpperCase() : fieldText(f)) + '</div>';
      });
      P.tables.forEach((t, i) => {
        const rows = (D.vals.t[t.key] || []).slice(0, t.max_rows);
        const xs = t.cols.map((c) => c.x);
        const xe = t.cols.map((c) => c.x + c.w);
        if (t.cols.length) {
          html += '<div class="pos-tbl" style="left:' + (Math.min(...xs) * scale) + 'px;top:' + (t.y * scale) + 'px;width:' + ((Math.max(...xe) - Math.min(...xs)) * scale) + 'px;height:' + (t.row_h * t.max_rows * scale) + 'px"></div>';
        }
        t.cols.forEach((c, ci) => {
          const on = sel && sel.kind === 't' && sel.i === i && sel.c === ci;
          const label = (D.tables[t.key] && D.tables[t.key].cols[c.col]) || c.col;
          const val = showVals() && rows[0] ? (rows[0][c.col] || '') : label;
          html += '<div class="pos-chip is-col' + (on ? ' is-sel' : '') + '" data-k="t" data-i="' + i + '" data-c="' + ci + '" tabindex="0" style="left:' + (c.x * scale) + 'px;top:' + (t.y * scale) + 'px;width:' + (c.w * scale) + 'px;height:' + (t.row_h * scale)
            + 'px;font-size:' + ptpx(parseFloat(t.size) || parseFloat(P.font_size) || 10) + 'px;text-align:' + c.align + '">' + esc(val) + '</div>';
        });
      });
      canvas.innerHTML = html;
      list();
    };
    const list = () => {
      let h = '<div class="pl-head">On the page</div>';
      P.fields.forEach((f, i) => { h += '<button type="button" class="pl-item' + (sel && sel.kind === 'f' && sel.i === i ? ' is-sel' : '') + '" data-pick="f" data-i="' + i + '"><span>' + esc(f.key.startsWith('text:') ? '"' + (f.text || '') + '"' : (D.fields[f.key] || f.key)) + '</span><code>' + f.x + ', ' + f.y + '</code></button>'; });
      P.tables.forEach((t, i) => { h += '<button type="button" class="pl-item' + (sel && sel.kind === 't' && sel.i === i ? ' is-sel' : '') + '" data-pick="t" data-i="' + i + '"><span><i class="bi bi-table"></i> ' + esc((D.tables[t.key] || {}).label || t.key) + '</span><code>y ' + t.y + '</code></button>'; });
      if (!P.fields.length && !P.tables.length) h += '<div class="small text-muted">Nothing placed yet.</div>';
      side.list.innerHTML = h;
    };
    const num2 = (v, d) => { const n = parseFloat(String(v).replace(',', '.')); return Number.isFinite(n) ? n : d; };
    const props = () => {
      if (!sel) { side.props.innerHTML = '<div class="small text-muted">Choose a field on the page to set its place, width, size and alignment.</div>'; return; }
      const inp = (k, lbl, v, extra) => '<div class="col-4"><label class="form-label">' + lbl + '</label><input class="form-control form-control-sm" data-p="' + k + '" value="' + esc(v) + '" ' + (extra || 'inputmode="decimal"') + '></div>';
      const align = (k, v) => '<div class="col-6"><label class="form-label">Alignment</label><select class="form-select form-select-sm" data-p="' + k + '">' + ['left', 'center', 'right'].map((a) => '<option' + (a === v ? ' selected' : '') + '>' + a + '</option>').join('') + '</select></div>';
      let h = '';
      if (sel.kind === 'f') {
        const f = P.fields[sel.i];
        h = '<div class="pp-title">' + esc(f.key.startsWith('text:') ? 'Fixed text' : (D.fields[f.key] || f.key)) + '</div><div class="row g-2">'
          + (f.key.startsWith('text:') ? '<div class="col-12"><label class="form-label">Text</label><input class="form-control form-control-sm" data-p="text" value="' + esc(f.text || '') + '" maxlength="120"></div>' : '')
          + inp('x', 'Right (mm)', f.x) + inp('y', 'Down (mm)', f.y) + inp('w', 'Width (mm)', f.w) + inp('size', 'Size (pt)', f.size || '') + align('align', f.align)
          + '<div class="col-6 d-flex flex-column justify-content-end"><label class="form-check m-0"><input class="form-check-input" type="checkbox" data-p="bold" ' + (f.bold ? 'checked' : '') + '> <span class="form-check-label small">Bold</span></label>'
          + '<label class="form-check m-0"><input class="form-check-input" type="checkbox" data-p="upper" ' + (f.upper ? 'checked' : '') + '> <span class="form-check-label small">Capitals</span></label></div>'
          + '<div class="col-12"><button type="button" class="btn btn-sm btn-outline-danger" data-remove>Remove from the page</button></div></div>';
      } else {
        const t = P.tables[sel.i];
        h = '<div class="pp-title"><i class="bi bi-table"></i> ' + esc((D.tables[t.key] || {}).label || t.key) + '</div><div class="row g-2">'
          + inp('ty', 'First row (mm)', t.y) + inp('row_h', 'Row height', t.row_h) + inp('max_rows', 'Rows', t.max_rows, 'inputmode="numeric"') + inp('tsize', 'Size (pt)', t.size || '') + '</div>'
          + '<div class="pp-cols"><div class="small fw-semibold mt-2">Columns: right (mm), width, alignment</div>';
        Object.keys((D.tables[t.key] || { cols: {} }).cols).forEach((ck) => {
          const ci = t.cols.findIndex((c) => c.col === ck);
          const c = ci >= 0 ? t.cols[ci] : null;
          h += '<div class="pp-col' + (sel.c === ci ? ' is-sel' : '') + '"><label class="form-check m-0"><input class="form-check-input" type="checkbox" data-col-on="' + ck + '" ' + (c ? 'checked' : '') + '> <span class="form-check-label small">' + esc(D.tables[t.key].cols[ck]) + '</span></label>'
            + (c ? '<input class="form-control form-control-sm" data-col="' + ci + '" data-cp="x" value="' + c.x + '" inputmode="decimal" aria-label="Right"><input class="form-control form-control-sm" data-col="' + ci + '" data-cp="w" value="' + c.w + '" inputmode="decimal" aria-label="Width">'
              + '<select class="form-select form-select-sm" data-col="' + ci + '" data-cp="align" aria-label="Alignment">' + ['left', 'center', 'right'].map((a) => '<option' + (a === c.align ? ' selected' : '') + '>' + a + '</option>').join('') + '</select>' : '') + '</div>';
        });
        h += '</div><button type="button" class="btn btn-sm btn-outline-danger mt-2" data-remove>Remove the table</button>';
      }
      side.props.innerHTML = h;
    };
    const select = (s) => { sel = s; render(); props(); };
    side.props.addEventListener('input', (e) => {
      const el = e.target;
      if (!sel) return;
      if (sel.kind === 'f') {
        const f = P.fields[sel.i];
        const k = el.dataset.p;
        if (!k) return;
        if (k === 'bold' || k === 'upper') f[k] = el.checked ? 1 : 0;
        else if (k === 'align') f.align = el.value;
        else if (k === 'text') f.text = el.value;
        else f[k] = Math.max(0, num2(el.value, f[k] || 0));
      } else {
        const t = P.tables[sel.i];
        if (el.dataset.p === 'ty') t.y = Math.max(0, num2(el.value, t.y));
        else if (el.dataset.p === 'row_h') t.row_h = Math.max(2, num2(el.value, t.row_h));
        else if (el.dataset.p === 'max_rows') t.max_rows = Math.max(1, Math.round(num2(el.value, t.max_rows)));
        else if (el.dataset.p === 'tsize') t.size = Math.max(0, num2(el.value, 0));
        else if (el.dataset.col !== undefined) { const c = t.cols[+el.dataset.col]; if (el.dataset.cp === 'align') c.align = el.value; else c[el.dataset.cp] = Math.max(0, num2(el.value, c[el.dataset.cp])); }
      }
      render();
    });
    side.props.addEventListener('change', (e) => {
      const el = e.target;
      if (el.dataset.colOn && sel && sel.kind === 't') {
        const t = P.tables[sel.i];
        const ck = el.dataset.colOn;
        if (el.checked) {
          const last = t.cols.reduce((m, c) => Math.max(m, c.x + c.w), 10);
          t.cols.push({ col: ck, x: snap(Math.min(last, size()[0] - 20)), w: 20, align: 'left' });
        } else {
          t.cols = t.cols.filter((c) => c.col !== ck);
        }
        sel.c = -1;
        render();
        props();
      } else if (el.dataset.p === 'bold' || el.dataset.p === 'upper' || el.dataset.p === 'align') {
        side.props.dispatchEvent(new Event('input'));
      }
    });
    side.props.addEventListener('click', (e) => {
      if (!e.target.closest('[data-remove]') || !sel) return;
      if (sel.kind === 'f') P.fields.splice(sel.i, 1); else P.tables.splice(sel.i, 1);
      select(null);
      fillAdd();
    });
    side.list.addEventListener('click', (e) => {
      const b = e.target.closest('[data-pick]');
      if (b) select({ kind: b.dataset.pick, i: +b.dataset.i, c: -1 });
    });
    // dragging
    let drag = null;
    canvas.addEventListener('pointerdown', (e) => {
      const chip = e.target.closest('.pos-chip');
      if (!chip) { select(null); return; }
      const s = { kind: chip.dataset.k, i: +chip.dataset.i, c: chip.dataset.c !== undefined ? +chip.dataset.c : -1 };
      const o = s.kind === 'f' ? { x: P.fields[s.i].x, y: P.fields[s.i].y } : { x: P.tables[s.i].cols[s.c].x, y: P.tables[s.i].y };
      drag = { s, sx: e.clientX, sy: e.clientY, o, moved: false };
      chip.setPointerCapture(e.pointerId);
      if (!sel || sel.kind !== s.kind || sel.i !== s.i || sel.c !== s.c) select(s);
      e.preventDefault();
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!drag) return;
      const dx = (e.clientX - drag.sx) / scale;
      const dy = (e.clientY - drag.sy) / scale;
      if (Math.abs(dx) + Math.abs(dy) < 0.3 && !drag.moved) return;
      drag.moved = true;
      const [w, h] = size();
      const nx = Math.max(0, Math.min(w - 2, snap(drag.o.x + dx)));
      const ny = Math.max(0, Math.min(h - 2, snap(drag.o.y + dy)));
      if (drag.s.kind === 'f') { P.fields[drag.s.i].x = nx; P.fields[drag.s.i].y = ny; } else { P.tables[drag.s.i].cols[drag.s.c].x = nx; P.tables[drag.s.i].y = ny; }
      const info = $('#posInfo');
      if (info) info.textContent = nx + ' mm right, ' + ny + ' mm down';
      render();
    });
    const end = () => { if (drag && drag.moved) props(); drag = null; };
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);
    document.addEventListener('keydown', (e) => {
      if (!sel || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key) || ['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
      e.preventDefault();
      const d = e.shiftKey ? 5 : 0.5;
      const dx = e.key === 'ArrowLeft' ? -d : e.key === 'ArrowRight' ? d : 0;
      const dy = e.key === 'ArrowUp' ? -d : e.key === 'ArrowDown' ? d : 0;
      if (sel.kind === 'f') { const f = P.fields[sel.i]; f.x = Math.max(0, snap(f.x + dx)); f.y = Math.max(0, snap(f.y + dy)); } else if (sel.c >= 0) { const t = P.tables[sel.i]; t.cols[sel.c].x = Math.max(0, snap(t.cols[sel.c].x + dx)); t.y = Math.max(0, snap(t.y + dy)); }
      render();
      props();
    });
    // adding fields, fixed text and tables
    const addSel = $('#posAddField');
    const tblSel = $('#posAddTable');
    const fillAdd = () => {
      const used = P.fields.map((f) => f.key);
      addSel.innerHTML = Object.keys(D.fields).filter((k) => !used.includes(k)).map((k) => '<option value="' + esc(k) + '">' + esc(D.fields[k]) + '</option>').join('');
      const usedT = P.tables.map((t) => t.key);
      tblSel.innerHTML = '<option value="">Add an item table</option>' + Object.keys(D.tables).filter((k) => !usedT.includes(k)).map((k) => '<option value="' + esc(k) + '">' + esc(D.tables[k].label) + '</option>').join('');
    };
    fillAdd();
    $('#posAddBtn').addEventListener('click', () => {
      if (!addSel.value) return;
      P.fields.push({ key: addSel.value, x: 15, y: snap(15 + (P.fields.length % 30) * 6), w: 60, size: 0, bold: 0, align: 'left', upper: 0 });
      fillAdd();
      select({ kind: 'f', i: P.fields.length - 1, c: -1 });
    });
    $('#posAddText').addEventListener('click', () => {
      P.fields.push({ key: 'text:' + Math.random().toString(36).slice(2, 8), text: 'Text', x: 15, y: 15, w: 40, size: 0, bold: 0, align: 'left', upper: 0 });
      select({ kind: 'f', i: P.fields.length - 1, c: -1 });
    });
    tblSel.addEventListener('change', () => {
      const k = tblSel.value;
      if (!k) return;
      const cols = Object.keys(D.tables[k].cols);
      const w = (size()[0] - 20) / cols.length;
      P.tables.push({ key: k, y: 90, row_h: 5, max_rows: 15, size: 0, cols: cols.map((c, i) => ({ col: c, x: snap(10 + i * w), w: snap(w - 1), align: c === 'description' ? 'left' : 'center' })) });
      fillAdd();
      select({ kind: 't', i: P.tables.length - 1, c: 0 });
    });
    // paper and view
    $$('[data-pos-set]', form).forEach((el) => el.addEventListener('change', () => {
      P[el.dataset.posSet] = el.value;
      $$('[data-pos-custom]', form).forEach((c) => { c.hidden = P.paper !== 'Custom'; });
      scale = 0;
      render();
    }));
    $$('[data-pos-custom]', form).forEach((c) => { c.hidden = P.paper !== 'Custom'; });
    $$('[data-zoom]').forEach((b) => b.addEventListener('click', () => { scale = Math.max(0.8, Math.min(6, scale * (b.dataset.zoom === '1' ? 1.2 : 1 / 1.2))); render(); }));
    ['#posShowBg', '#posShowVals'].forEach((id) => { const el = $(id); if (el) el.addEventListener('change', render); });
    form.addEventListener('submit', () => { $('#posJson').value = JSON.stringify({ fields: P.fields, tables: P.tables }); });
    window.addEventListener('resize', () => { if (!drag) { scale = 0; render(); } });
    render();
  }

  // ------------------------------------------------------------------ Document Vault: the preview of Word, Excel, PowerPoint and Publisher files
  // Quick view: HTML read by the program, loaded once into the page. Page view: the PDF LibreOffice makes, in a frame.
  // The view chosen last is remembered in this browser.
  $$('[data-op-preview]').forEach((card) => {
    const stage = $('[data-op-stage]', card);
    const buttons = $$('[data-op-mode]', card);
    if (!stage) return;
    let quickHtml = null;
    let loading = false;
    const pageOk = card.dataset.pageOk === '1';
    const msg = (icon, text) => '<div class="op-msg"><i class="bi bi-' + icon + '"></i>' + text + '</div>';
    const wire = () => {
      // the sheet tabs of a workbook
      $$('[data-op-sheets]', stage).forEach((wb) => {
        const tabs = $$('[data-op-sheet]', wb);
        tabs.forEach((tab) => tab.addEventListener('click', () => {
          tabs.forEach((t) => { const on = t === tab; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
          $$('[data-op-panel]', wb).forEach((p) => { p.hidden = p.dataset.opPanel !== tab.dataset.opSheet; });
        }));
      });
    };
    const show = (mode) => {
      if (mode === 'page' && !pageOk) mode = 'quick';
      buttons.forEach((b) => { const on = b.dataset.opMode === mode; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      $$('[data-op-hint]', card).forEach((h) => { h.hidden = h.dataset.opHint !== mode; });
      try { localStorage.setItem('opPreviewMode', mode); } catch (e) { /* private mode */ }
      if (mode === 'page') {
        // phones and tablets do not show a PDF inside a page: the pages open in their own viewer
        const touch = window.matchMedia && window.matchMedia('(max-width: 767.98px), (pointer: coarse)').matches;
        stage.classList.toggle('op-pdf', !touch);
        stage.innerHTML = touch
          ? msg('file-earmark-richtext', 'The pages open in the PDF viewer of this device.<div class="mt-3"><a class="btn btn-primary btn-sm" href="' + card.dataset.page + '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Open the pages</a></div>')
          : '<object data="' + card.dataset.page + '" type="application/pdf" aria-label="Page view">' + msg('file-earmark-richtext', 'This browser does not show the pages here. <a href="' + card.dataset.page + '" target="_blank" rel="noopener">Open them in a new tab</a>.') + '</object>';
        return;
      }
      stage.classList.remove('op-pdf');
      if (quickHtml !== null) { stage.innerHTML = quickHtml; wire(); return; }
      stage.innerHTML = '<div class="op-loading"><span class="spinner-border spinner-border-sm" role="status"></span> Reading the file</div>';
      if (loading) return;
      loading = true;
      fetch(card.dataset.quick, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then((r) => r.text().then((t) => ({ ok: r.ok || /^<div class="op-msg"/.test(t), t: t })))
        .then((res) => {
          quickHtml = res.ok ? res.t : msg('file-earmark-x', 'The file could not be read.');
          if (!stage.classList.contains('op-pdf')) { stage.innerHTML = quickHtml; wire(); }
        })
        .catch(() => { stage.innerHTML = msg('wifi-off', 'The file could not be loaded. Check the connection and open the page again.'); })
        .finally(() => { loading = false; });
    };
    buttons.forEach((b) => b.addEventListener('click', () => { if (!b.disabled) show(b.dataset.opMode); }));
    let start = card.dataset.mode || 'quick';
    try { const saved = localStorage.getItem('opPreviewMode'); if (saved === 'page' || saved === 'quick') start = saved; } catch (e) { /* private mode */ }
    // a file the quick view does not read (OpenDocument) opens in the page view when there is one
    if (card.dataset.mode === 'page') start = 'page';
    show(start);
  });
})();
