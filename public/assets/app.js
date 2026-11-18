'use strict';
/* Распознавалка первички → 1С. Vanilla JS, без сборки. */

const S = {
  cfg: {}, batches: [], catalog: {count: 0},
  batch: null, sel: null,          // sel: {type:'doc'|'page', id}
  zoom: 'fit', busy: 0, queueRunning: false,
  saveTimer: null, acState: null,
};
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

// ---------------- API ----------------
async function api(action, params = {}, body = null) {
  const qs = Object.entries(params).map(([k, v]) => '&' + k + '=' + encodeURIComponent(v)).join('');
  const opt = {};
  if (body) {
    opt.method = 'POST';
    if (body instanceof FormData) opt.body = body;
    else { opt.body = JSON.stringify(body); opt.headers = {'Content-Type': 'application/json'}; }
  }
  const r = await fetch('api.php?a=' + action + qs, opt);
  if (r.status === 401) { location.reload(); throw new Error('Нужно войти'); }
  let j;
  try { j = await r.json(); } catch { throw new Error('Сервер вернул не JSON (HTTP ' + r.status + '). Проверьте таймауты nginx/php.'); }
  if (!j.ok) throw new Error(j.error || 'Ошибка');
  return j;
}

function toast(msg, kind = '') {
  const t = $('#toast');
  t.textContent = msg; t.className = 'toast ' + kind;
  clearTimeout(t._t); t._t = setTimeout(() => t.classList.add('hidden'), kind === 'err' ? 7000 : 3000);
}

// ---------------- числа ----------------
const MONEY = ['price', 'sum_no_vat', 'vat_sum', 'sum_with_vat'];
function parseNum(s) {
  if (s === null || s === undefined) return null;
  if (typeof s === 'number') return s;
  s = String(s).replace(/[\s ]/g, '').replace(',', '.');
  if (s === '') return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}
function fmt(n, key) {
  if (n === null || n === undefined || n === '') return '';
  const qty = key === 'qty';
  return Number(n).toLocaleString('ru-RU', {minimumFractionDigits: qty ? 0 : 2, maximumFractionDigits: qty ? 3 : 2});
}
const r2 = (x) => Math.round((x + Number.EPSILON) * 100) / 100;
function rateOf(s) {
  if (s === null || s === undefined) return null;
  s = String(s).toLowerCase();
  if (s.includes('без')) return 0;
  const m = s.match(/(\d+(?:[.,]\d+)?)/);
  return m ? parseFloat(m[1].replace(',', '.')) : null;
}
function innValid(inn) {
  inn = String(inn || '');
  const c = (w, d) => (w.reduce((s, k, i) => s + k * +d[i], 0) % 11) % 10;
  if (/^\d{10}$/.test(inn)) return c([2, 4, 10, 3, 5, 9, 4, 6, 8], inn) === +inn[9];
  if (/^\d{12}$/.test(inn)) return c([7, 2, 4, 10, 3, 5, 9, 4, 6, 8], inn) === +inn[10] && c([3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8], inn) === +inn[11];
  return false;
}
const fmtDate = (d) => d ? d.split('-').reverse().join('.') : '';

// ---------------- проверки ----------------
const FIELD_RU = {code: 'код', name: 'наименование', unit_code: 'код ед.', unit: 'ед.', qty: 'количество', price: 'цена',
  sum_no_vat: 'сумма без НДС', vat_rate: 'ставка', vat_sum: 'НДС', sum_with_vat: 'сумма с НДС', country: 'страна', gtd: 'ГТД'};

function validate(d) {
  const issues = [];
  const cells = {};   // "i:key" -> err|warn|unc
  const fields = {};  // "seller.inn" -> err|warn
  const rank = {err: 3, warn: 2, unc: 1};
  const mark = (map, k, lvl) => { if (!map[k] || rank[lvl] > rank[map[k]]) map[k] = lvl; };
  const add = (level, msg, refs = []) => {
    issues.push({level, msg, ref: refs[0] || null});
    for (const r of refs) {
      if (r.startsWith('L')) { const [i, k] = r.slice(1).split(':'); mark(cells, i + ':' + k, level); } else mark(fields, r, level);
    }
  };

  // шапка
  if (!d.number) add('err', 'Не распознан номер документа', ['number']);
  if (!d.date) add('err', 'Не распознана дата документа', ['date']);
  else {
    const dt = new Date(d.date), now = new Date();
    if (dt > new Date(now.getTime() + 2 * 864e5)) add('warn', 'Дата документа в будущем', ['date']);
    if (now - dt > 365 * 864e5) add('warn', 'Документу больше года — проверьте дату', ['date']);
  }
  if (!d.seller?.inn) add('err', 'Нет ИНН поставщика', ['seller.inn']);
  else if (!innValid(d.seller.inn)) add('err', 'ИНН поставщика не сходится по контрольной сумме — скорее всего, ошибка в цифре', ['seller.inn']);
  if (d.seller?.inn?.length === 10 && d.seller?.kpp && !/^\d{9}$/.test(d.seller.kpp)) add('warn', 'КПП поставщика должен быть из 9 цифр', ['seller.kpp']);
  if (d.buyer?.inn && !innValid(d.buyer.inn)) add('err', 'ИНН покупателя не сходится по контрольной сумме', ['buyer.inn']);
  if (S.cfg.own_inns?.length && d.buyer?.inn && !S.cfg.own_inns.includes(d.buyer.inn))
    add('warn', 'Покупатель — не наша организация (ИНН ' + d.buyer.inn + ')', ['buyer.inn']);
  if (d.duplicate) add('err', 'Этот документ уже выгружали ' + new Date(d.duplicate.at).toLocaleString('ru-RU') + ' — возможен дубль', ['number']);
  for (const w of d.warnings || []) add('warn', 'Распознавание: ' + w);

  // строки
  const L = d.lines || [];
  if (!L.length) add('err', 'В документе нет ни одной строки товаров');
  const ns = L.map((l) => l.n).filter((n) => Number.isInteger(n));
  if (ns.length) {
    const max = Math.max(...ns), have = new Set(ns), miss = [];
    for (let k = 1; k <= max; k++) if (!have.has(k)) miss.push(k);
    if (miss.length) add('err', 'Пропущены строки № ' + miss.slice(0, 10).join(', ') + (miss.length > 10 ? '…' : '') + ' — возможно, не загружена страница');
    const dup = ns.filter((n, i) => ns.indexOf(n) !== i);
    if (dup.length) add('warn', 'Повторяются номера строк: ' + [...new Set(dup)].join(', '));
  }
  const unc = [], noMatch = [], fuzzy = [];
  const catalogOn = S.catalog.count > 0;
  L.forEach((l, i) => {
    const N = 'Стр. ' + (l.n ?? i + 1) + ': ';
    const R = (...ks) => ks.map((k) => 'L' + i + ':' + k);
    if (!l.name) add('err', N + 'нет наименования', R('name'));
    if (l.qty === null || l.qty <= 0) add('err', N + 'нет количества', R('qty'));
    if (l.sum_no_vat === null) add('err', N + 'нет суммы без НДС', R('sum_no_vat'));
    if (l.price === null) add('warn', N + 'нет цены', R('price'));
    if (!l.unit) add('warn', N + 'нет единицы измерения', R('unit'));
    if (l.qty !== null && l.price !== null && l.sum_no_vat !== null) {
      const calc = l.qty * l.price, tol = Math.max(0.011, Math.abs(l.qty) * 0.005 + 0.01);
      if (Math.abs(calc - l.sum_no_vat) > tol)
        add('err', N + 'кол-во × цена = ' + fmt(r2(calc)) + ', а в сумме ' + fmt(l.sum_no_vat), R('qty', 'price', 'sum_no_vat'));
    }
    if (l.sum_no_vat !== null && l.vat_sum !== null && l.sum_with_vat !== null && Math.abs(l.sum_no_vat + l.vat_sum - l.sum_with_vat) > 0.011)
      add('err', N + 'сумма + НДС = ' + fmt(r2(l.sum_no_vat + l.vat_sum)) + ', а «всего» ' + fmt(l.sum_with_vat), R('sum_no_vat', 'vat_sum', 'sum_with_vat'));
    const rate = rateOf(l.vat_rate);
    if (l.vat_rate && rate !== null && ![0, 5, 7, 10, 20, 22].includes(rate)) add('warn', N + 'необычная ставка НДС ' + l.vat_rate, R('vat_rate'));
    if (!l.vat_rate) add('warn', N + 'не указана ставка НДС', R('vat_rate'));
    if (rate && l.vat_sum !== null) {
      const byBase = l.sum_no_vat !== null ? Math.abs(r2(l.sum_no_vat * rate / 100) - l.vat_sum) : 99;
      const byTotal = l.sum_with_vat !== null ? Math.abs(r2(l.sum_with_vat * rate / (100 + rate)) - l.vat_sum) : 99;
      if (Math.min(byBase, byTotal) > 0.021) add('err', N + 'НДС ' + fmt(l.vat_sum) + ' не соответствует ставке ' + l.vat_rate, R('vat_sum', 'vat_rate'));
    }
    for (const k of l.uncertain || []) mark(cells, i + ':' + k, 'unc');
    if ((l.uncertain || []).length) unc.push((l.n ?? i + 1) + ' (' + l.uncertain.map((k) => FIELD_RU[k] || k).join(', ') + ')');
    if (catalogOn) {
      if (!l.match) { noMatch.push(l.n ?? i + 1); mark(cells, i + ':match', 'warn'); }
      else if (l.match.how === 'fuzzy') { fuzzy.push(l.n ?? i + 1); mark(cells, i + ':match', 'warn'); }
    }
  });
  if (unc.length) add('warn', 'Плохо читается, сверьте со сканом — стр. ' + unc.join('; '), unc.length ? [] : []);
  if (fuzzy.length) add('warn', 'Номенклатура подобрана по похожему названию — подтвердите (стр. ' + fuzzy.join(', ') + ')');
  if (noMatch.length) add('warn', 'Не найдено в справочнике 1С — будет новая номенклатура или выберите вручную (стр. ' + noMatch.join(', ') + ')');

  // итоги
  const t = d.totals || {};
  const sum = (k) => r2(L.reduce((s, l) => s + (l[k] || 0), 0));
  if (t.sum_no_vat === null && t.vat_sum === null && t.sum_with_vat === null) {
    if (d.type !== 'Другое') add('err', 'Нет итоговой строки «Всего к оплате» — проверьте, все ли страницы загружены', ['totals.sum_with_vat']);
  } else {
    for (const [k, ru] of [['sum_no_vat', 'без НДС'], ['vat_sum', 'НДС'], ['sum_with_vat', 'с НДС']]) {
      if (t[k] !== null && t[k] !== undefined && Math.abs(sum(k) - t[k]) > 0.011)
        add('err', 'Итог ' + ru + ' по строкам ' + fmt(sum(k)) + ' ≠ по документу ' + fmt(t[k]) + ' (разница ' + fmt(r2(sum(k) - t[k])) + ')', ['totals.' + k]);
    }
  }
  for (const n of d.notes || []) add('info', 'Рукописная пометка на скане: «' + n + '»');

  const ord = {err: 0, warn: 1, info: 2};
  issues.sort((a, b) => ord[a.level] - ord[b.level]);
  return {issues, cells, fields, errors: issues.filter((x) => x.level === 'err').length, warns: issues.filter((x) => x.level === 'warn').length};
}

// ---------------- состояние / пачки ----------------
const docs = () => S.batch?.documents || [];
const getDoc = () => S.sel?.type === 'doc' ? docs().find((d) => d.id === S.sel.id) : null;
const pageById = (id) => S.batch?.pages.find((p) => p.id === id);
const assignedPages = () => new Set(docs().flatMap((d) => d.pages));

async function loadState() {
  const j = await api('state');
  S.cfg = j.cfg; S.batches = j.batches; S.catalog = j.catalog;
  renderBatchSel();
}

function renderBatchSel() {
  const sel = $('#batchSel');
  sel.innerHTML = S.batches.length ? '' : '<option value="">— нет пачек —</option>';
  for (const b of S.batches) {
    const o = document.createElement('option');
    o.value = b.id;
    o.textContent = b.name + ' · ' + b.documents + ' док.' + (b.documents ? ' (✓' + b.checked + ')' : '');
    sel.appendChild(o);
  }
  if (S.batch) sel.value = S.batch.id;
}

async function openBatch(id) {
  if (!id) { S.batch = null; render(); return; }
  const j = await api('batch', {id});
  S.batch = j.batch;
  localStorage.setItem('rspz.batch', id);
  S.sel = null;
  selectDefault();
  render();
  if (S.batch.pages.some((p) => p.status === 'new')) runQueue();
}

function selectDefault() {
  if (S.sel && (S.sel.type === 'doc' ? getDoc() : pageById(S.sel.id))) return;
  const d = docs()[0];
  if (d) S.sel = {type: 'doc', id: d.id};
  else {
    const p = S.batch?.pages.find((p) => !assignedPages().has(p.id));
    S.sel = p ? {type: 'page', id: p.id} : null;
  }
}

async function ensureBatch() {
  if (S.batch) return S.batch;
  const j = await api('batch_new', {}, {});
  S.batch = j.batch;
  S.batches.unshift({id: j.batch.id, name: j.batch.name, documents: 0, checked: 0, pages: 0, created: j.batch.created});
  renderBatchSel();
  localStorage.setItem('rspz.batch', j.batch.id);
  return S.batch;
}

// ---------------- загрузка и распознавание ----------------
async function uploadFiles(files) {
  files = [...files];
  if (!files.length) return;
  await ensureBatch();
  let n = 0;
  for (const f of files) {
    setProgress('Загрузка ' + (++n) + '/' + files.length + ': ' + f.name);
    const fd = new FormData();
    fd.append('file', await shrinkImage(f));
    try {
      const j = await api('upload', {batch: S.batch.id}, fd);
      S.batch.pages.push(...j.pages);
      if (!S.sel) S.sel = {type: 'page', id: j.pages[0].id};
      render();
    } catch (e) { toast(f.name + ': ' + e.message, 'err'); }
  }
  setProgress('');
  runQueue();
}

/** Большие фото/сканы ужимаем в браузере до 2400px JPEG — быстрее загрузка, меньше лимиты. */
async function shrinkImage(f) {
  if (!/^image\/(jpeg|png|webp|bmp)$/.test(f.type) || f.size < 1.5e6) return f;
  try {
    const bmp = await createImageBitmap(f, {imageOrientation: 'from-image'});
    const k = Math.min(1, 2400 / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.drawImage(bmp, 0, 0, c.width, c.height);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
    return blob ? new File([blob], f.name.replace(/\.\w+$/, '') + '.jpg', {type: 'image/jpeg'}) : f;
  } catch { return f; }
}

function setProgress(t) { $('#progress').textContent = t; }

async function runQueue() {
  if (S.queueRunning) return;
  S.queueRunning = true;
  const batchId = S.batch.id;
  try {
    const todo = () => S.batch && S.batch.id === batchId ? S.batch.pages.filter((p) => p.status === 'new') : [];
    const total = todo().length;
    let done = 0;
    const worker = async () => {
      for (;;) {
        const p = todo()[0];
        if (!p) return;
        p.status = 'processing';
        renderTabs();
        if (S.sel?.id === p.id) renderRight();
        setProgress('Распознаю ' + (done + 1) + '/' + total + '…');
        try {
          const j = await api('recognize', {batch: batchId, page: p.id}, {});
          Object.assign(p, j.page);
          if (j.page.status === 'error') toast(p.src + ': ' + j.page.error, 'err');
        } catch (e) { p.status = 'error'; p.error = e.message; toast(e.message, 'err'); }
        done++;
        renderTabs();
        if (S.sel?.id === p.id) renderRight();
      }
    };
    await Promise.all(Array.from({length: Math.max(1, S.cfg.parallel || 2)}, worker));
    if (S.batch?.id !== batchId) return;
    setProgress('Собираю документы…');
    const before = new Set(docs().map((d) => d.id));
    const j = await api('build', {batch: batchId}, {});
    S.batch = j.batch;
    const firstNew = docs().find((d) => !before.has(d.id));
    if (firstNew && (S.sel?.type !== 'doc')) S.sel = {type: 'doc', id: firstNew.id};
    selectDefault();
    render();
    refreshBatchList();
  } finally {
    S.queueRunning = false;
    setProgress('');
  }
}

async function refreshBatchList() {
  try { const j = await api('state'); S.batches = j.batches; S.catalog = j.catalog; renderBatchSel(); } catch {}
}

// ---------------- отрисовка ----------------
function render() {
  const has = !!S.batch && S.batch.pages.length > 0;
  $('#empty').classList.toggle('hidden', has);
  $('#split').classList.toggle('hidden', !has);
  $('#docTabs').classList.toggle('hidden', !has);
  renderBatchSel();
  if (!has) return;
  renderTabs();
  renderViewer();
  renderRight();
}

function renderTabs() {
  const el = $('#docTabs');
  if (!S.batch) { el.innerHTML = ''; return; }
  let h = '';
  docs().forEach((d, i) => {
    const v = validate(d);
    const seller = (d.seller?.name || '').replace(/^(ООО|АО|ПАО|ЗАО|ИП)\s*/, '').replace(/["«»]/g, '');
    const badge = d.checked ? '<b class="bdg ok">✓</b>'
      : v.errors ? '<b class="bdg err">' + v.errors + '</b>' : v.warns ? '<b class="bdg warn">' + v.warns + '</b>' : '<b class="bdg ok0">•</b>';
    h += `<button class="tab ${S.sel?.type === 'doc' && S.sel.id === d.id ? 'active' : ''}" data-doc="${d.id}" title="${esc((d.type || '') + ' ' + (d.number || '') + ' ' + (d.seller?.name || ''))}">
      ${badge}<span>${esc(d.type || 'Док.')} ${esc(d.number || '?')}</span><small>${esc(seller.slice(0, 22))}${d.exported_at ? ' · ⬇' : ''}</small></button>`;
  });
  const assigned = assignedPages();
  for (const p of S.batch.pages.filter((p) => !assigned.has(p.id))) {
    const st = {new: '⏳ в очереди', processing: '<span class="spin"></span> распознаю', error: '⚠ ошибка', done: '… сборка'}[p.status] || p.status;
    h += `<button class="tab page ${S.sel?.type === 'page' && S.sel.id === p.id ? 'active' : ''} ${p.status}" data-page="${p.id}">
      <span>${st}</span><small>${esc(p.src.slice(0, 26))}${p.src_page > 1 ? ' · стр.' + p.src_page : ''}</small></button>`;
  }
  el.innerHTML = h;
}

function currentPages() {
  if (!S.batch) return [];
  if (S.sel?.type === 'doc') return (getDoc()?.pages || []).map(pageById).filter(Boolean);
  if (S.sel?.type === 'page') return [pageById(S.sel.id)].filter(Boolean);
  return [];
}

function renderViewer() {
  const v = $('#viewer');
  const pages = currentPages();
  v.innerHTML = pages.map((p, i) => `
    <div class="pg" data-pg="${p.id}">
      <div class="pg-head"><span>Стр. ${i + 1} · ${esc(p.src)}${p.src_page > 1 ? ' (стр. ' + p.src_page + ')' : ''}</span>
        <span><button class="btn xs" data-rot="${p.id}" title="Повернуть на 90° по часовой">⟳ повернуть</button>
        <button class="btn xs" data-rerec="${p.id}" title="Распознать эту страницу заново">↻ распознать заново</button></span></div>
      <img src="api.php?a=image&batch=${S.batch.id}&page=${p.id}&r=${p.rot || 0}" data-w="${p.w}" draggable="false" alt="">
    </div>`).join('');
  applyZoom();
}

function applyZoom() {
  const v = $('#viewer');
  for (const img of $$('img', v)) {
    img.style.width = S.zoom === 'fit' ? '100%' : Math.round(+img.dataset.w * S.zoom) + 'px';
    img.style.maxWidth = S.zoom === 'fit' ? '100%' : 'none';
  }
  const first = $('img', v);
  const pct = S.zoom === 'fit' ? (first && first.dataset.w ? Math.round(v.clientWidth / first.dataset.w * 100) : 100) : Math.round(S.zoom * 100);
  $('#zoomVal').textContent = pct + '%';
}

function zoomBy(dir) {
  const v = $('#viewer'), first = $('img', v);
  let z = S.zoom === 'fit' ? (first ? (v.clientWidth - 24) / first.dataset.w : 1) : S.zoom;
  z = Math.min(3, Math.max(0.2, z * (dir > 0 ? 1.2 : 1 / 1.2)));
  S.zoom = z;
  applyZoom();
}

function renderRight() {
  const box = $('#docView');
  if (S.sel?.type === 'page') {
    const p = pageById(S.sel.id);
    if (!p) { box.innerHTML = ''; return; }
    box.innerHTML = p.status === 'error'
      ? `<div class="state err"><h3>⚠ Не удалось распознать</h3><p>${esc(p.error)}</p><button class="btn primary" data-retry="${p.id}">Попробовать ещё раз</button></div>`
      : `<div class="state"><span class="spin big"></span><h3>${p.status === 'new' ? 'В очереди' : 'Распознаю страницу…'}</h3>
         <p class="muted">Обычно 15–60 секунд на страницу. Можно пока проверять готовые документы.</p></div>`;
    return;
  }
  const d = getDoc();
  if (!d) { box.innerHTML = '<div class="state muted">Выберите документ сверху</div>'; return; }
  const showMatch = S.catalog.count > 0 || d.lines.some((l) => l.match);
  const F = (path, label, cls = '') => `<label class="fld ${cls}"><span>${label}</span><input data-f="${path}" value="${esc(getPath(d, path) ?? '')}"></label>`;

  box.innerHTML = `
  <div class="doc-head">
    <div class="doc-title">
      <select data-f="type">${['УПД', 'Счет-фактура', 'ТОРГ-12', 'Товарная накладная', 'Акт', 'Счет', 'Другое'].map((t) => `<option ${t === d.type ? 'selected' : ''}>${t}</option>`).join('')}</select>
      <span>№</span><input class="w-num" data-f="number" value="${esc(d.number || '')}">
      <span>от</span><input type="date" data-f="date" value="${esc(d.date || '')}">
      ${d.upd_status ? `<span class="chip">статус ${esc(d.upd_status)}</span>` : ''}
    </div>
    <div class="doc-actions">
      <button class="btn ${d.checked ? 'ok' : ''}" id="btnChecked">${d.checked ? '✓ Проверено' : 'Отметить проверенным'}</button>
      <button class="btn primary" id="btnExport" title="Таблица для «Загрузить из файла» в документе поступления">⬇ XLSX для 1С</button>
      <div class="dropdown"><button class="btn ghost">⋯</button><div class="menu">
        <a id="actRerec">Распознать документ заново</a>
        <a id="actMerge">Объединить с предыдущим документом</a>
        <a id="actRematch">Пересопоставить номенклатуру</a>
        <a id="actDelete" class="danger">Удалить документ</a></div></div>
    </div>
  </div>
  <div class="parties">
    <div class="party"><b>Поставщик</b>${F('seller.name', 'Наименование', 'grow')}${F('seller.inn', 'ИНН', 'w-inn')}${F('seller.kpp', 'КПП', 'w-inn')}</div>
    <div class="party"><b>Покупатель</b>${F('buyer.name', 'Наименование', 'grow')}${F('buyer.inn', 'ИНН', 'w-inn')}${F('buyer.kpp', 'КПП', 'w-inn')}</div>
    ${d.contract_id ? `<div class="party small"><b>Госконтракт</b><span>${esc(d.contract_id)}</span></div>` : ''}
  </div>
  <div id="issues" class="issues"></div>
  <div class="tbl-wrap"><table class="lines">
    <thead><tr><th>№</th><th>Код пост.</th><th class="w-name">Наименование поставщика</th>
      ${showMatch ? '<th class="w-name">Номенклатура 1С</th>' : ''}
      <th>Кол-во</th><th>Ед.</th><th>Цена</th><th>Сумма без НДС</th><th>Ставка</th><th>НДС</th><th>Всего</th><th></th></tr></thead>
    <tbody>${d.lines.map((l, i) => rowHtml(l, i, showMatch)).join('')}</tbody>
    <tfoot>
      <tr class="sum-calc"><td colspan="${showMatch ? 7 : 6}" class="r">Итого по строкам</td>
        <td class="r" id="sc_sum_no_vat"></td><td></td><td class="r" id="sc_vat_sum"></td><td class="r" id="sc_sum_with_vat"></td><td></td></tr>
      <tr class="sum-doc"><td colspan="${showMatch ? 7 : 6}" class="r">По документу (строка «Всего к оплате»)</td>
        <td><input class="num" data-t="sum_no_vat" value="${fmt(d.totals.sum_no_vat)}"></td><td></td>
        <td><input class="num" data-t="vat_sum" value="${fmt(d.totals.vat_sum)}"></td>
        <td><input class="num" data-t="sum_with_vat" value="${fmt(d.totals.sum_with_vat)}"></td><td></td></tr>
    </tfoot></table></div>
  <div class="tbl-actions"><button class="btn sm" id="btnAddRow">＋ строка</button>
    <span class="muted small">Enter — к следующей строке. Жёлтый пунктир — модель не уверена, сверьте со сканом. Клик по № — показать страницу.</span></div>`;
  refreshChecks();
}

function rowHtml(l, i, showMatch) {
  const inp = (k, cls = '') => {
    const val = MONEY.includes(k) || k === 'qty' ? fmt(l[k], k) : (l[k] ?? '');
    return `<td><input class="${cls}" data-i="${i}" data-k="${k}" value="${esc(val)}"></td>`;
  };
  const m = l.match;
  const how = m ? {mapping: ['ok', 'запомнено'], article: ['ok', 'по артикулу'], manual: ['ok', 'выбрано'], confirmed: ['ok', 'подтверждено'],
    text: ['warn', 'вручную, нет в справочнике'], fuzzy: ['warn', '≈ похоже — нажмите, чтобы подтвердить']}[m.how] || ['', ''] : ['new', 'нет в справочнике'];
  return `<tr data-row="${i}">
    <td class="n" data-goto="${l.page || ''}" title="Показать страницу">${l.n ?? i + 1}</td>
    ${inp('code', 'code')}
    <td><textarea rows="1" data-i="${i}" data-k="name">${esc(l.name)}</textarea></td>
    ${showMatch ? `<td class="match"><input data-i="${i}" data-k="match" value="${esc(m?.name || '')}" placeholder="как у поставщика">
      <span class="mb ${how[0]}" data-mb="${i}" title="${esc(how[1] + (m?.article ? ' · арт. ' + m.article : '') + (m?.code ? ' · код ' + m.code : ''))}">${{ok: '✓', warn: '?', new: 'нов.'}[how[0]] || ''}</span></td>` : ''}
    ${inp('qty', 'num')}${inp('unit', 'unit')}${inp('price', 'num')}${inp('sum_no_vat', 'num')}${inp('vat_rate', 'rate')}${inp('vat_sum', 'num')}${inp('sum_with_vat', 'num')}
    <td><button class="del" data-del="${i}" title="Удалить строку">✕</button></td></tr>`;
}

function getPath(o, p) { return p.split('.').reduce((x, k) => x?.[k], o); }
function setPath(o, p, v) { const ks = p.split('.'); const last = ks.pop(); const t = ks.reduce((x, k) => (x[k] ??= {}), o); t[last] = v; }

/** Пересчитать подсветку без перерисовки (чтобы не терять фокус). */
function refreshChecks() {
  const d = getDoc();
  if (!d) return;
  const v = validate(d);
  for (const el of $$('#docView [data-i]')) {
    el.parentElement.classList.remove('c-err', 'c-warn', 'c-unc');
    const lvl = v.cells[el.dataset.i + ':' + el.dataset.k];
    if (lvl) el.parentElement.classList.add('c-' + lvl);
  }
  for (const el of $$('#docView [data-f], #docView [data-t]')) {
    const key = el.dataset.f || 'totals.' + el.dataset.t;
    const target = el.closest('label') || el.parentElement;
    target.classList.remove('c-err', 'c-warn');
    if (v.fields[key]) target.classList.add('c-' + v.fields[key]);
  }
  const sum = (k) => r2(d.lines.reduce((s, l) => s + (l[k] || 0), 0));
  for (const k of ['sum_no_vat', 'vat_sum', 'sum_with_vat']) { const c = $('#sc_' + k); if (c) c.textContent = fmt(sum(k)); }
  const box = $('#issues');
  if (box) {
    box.innerHTML = v.issues.length
      ? v.issues.map((x, n) => `<div class="iss ${x.level}" data-iss="${n}">${{err: '⛔', warn: '⚠', info: 'ℹ'}[x.level]} ${esc(x.msg)}</div>`).join('')
      : '<div class="iss okk">✓ Расхождений не найдено — сверьте глазами наименования и количество</div>';
    box._issues = v.issues;
  }
  const tab = $(`#docTabs [data-doc="${d.id}"] .bdg`);
  if (tab) {
    tab.className = 'bdg ' + (d.checked ? 'ok' : v.errors ? 'err' : v.warns ? 'warn' : 'ok0');
    tab.textContent = d.checked ? '✓' : v.errors || v.warns || '•';
  }
}

function focusRef(ref) {
  if (!ref) return;
  let el;
  if (ref.startsWith('L')) { const [i, k] = ref.slice(1).split(':'); el = $(`#docView [data-i="${i}"][data-k="${k}"]`); }
  else if (ref.startsWith('totals.')) el = $(`#docView [data-t="${ref.slice(7)}"]`);
  else el = $(`#docView [data-f="${ref}"]`);
  if (el) { el.scrollIntoView({block: 'center', behavior: 'smooth'}); el.focus(); el.select?.(); }
}

// ---------------- сохранение ----------------
function scheduleSave() {
  clearTimeout(S.saveTimer);
  const d = getDoc();
  if (!d) return;
  setProgress('Сохраняю…');
  S.saveTimer = setTimeout(() => saveDoc(d), 700);
}
async function saveDoc(d) {
  try {
    const j = await api('save_doc', {}, {batch: S.batch.id, doc: d});
    d.duplicate = j.doc.duplicate;
    setProgress('Сохранено');
    setTimeout(() => $('#progress').textContent === 'Сохранено' && setProgress(''), 1500);
    if (getDoc() === d) refreshChecks();
  } catch (e) { setProgress(''); toast('Не сохранилось: ' + e.message, 'err'); }
}

// ---------------- автодополнение номенклатуры ----------------
let acTimer = null;
function acOpen(input) {
  clearTimeout(acTimer);
  acTimer = setTimeout(async () => {
    const q = input.value.trim();
    const box = $('#acBox');
    if (q.length < 2) { box.classList.add('hidden'); return; }
    try {
      const j = await api('catalog_search', {q});
      if (document.activeElement !== input) return;
      S.acState = {input, items: j.items, idx: 0};
      if (!j.items.length) { box.innerHTML = '<div class="ac-empty">Ничего не найдено — останется как введено</div>'; }
      else box.innerHTML = j.items.map((it, n) => `<div class="ac-it ${n === 0 ? 'on' : ''}" data-ac="${n}"><span>${esc(it.name)}</span><small>${esc([it.article && 'арт. ' + it.article, it.code && 'код ' + it.code].filter(Boolean).join(' · '))}</small></div>`).join('');
      const r = input.getBoundingClientRect();
      const w = Math.max(r.width, 420);
      box.style.left = Math.min(r.left, innerWidth - w - 8) + 'px';
      box.style.width = w + 'px';
      box.classList.remove('hidden');
      const below = innerHeight - r.bottom - 8;
      box.style.maxHeight = Math.max(below, r.top - 8, 120) > 320 ? '320px' : Math.max(below, r.top - 8, 120) + 'px';
      box.style.top = (below >= Math.min(box.offsetHeight, 200) ? r.bottom + 2 : r.top - box.offsetHeight - 2) + 'px';
    } catch {}
  }, 200);
}
function acPick(n) {
  const st = S.acState;
  if (!st) return;
  const it = st.items[n];
  const d = getDoc(), i = +st.input.dataset.i;
  if (it && d) {
    d.lines[i].match = {code: it.code, name: it.name, article: it.article, how: 'manual', score: 1};
    st.input.value = it.name;
    updateMatchBadge(i);
    scheduleSave(); refreshChecks();
  }
  acClose();
}
function acClose() { $('#acBox').classList.add('hidden'); S.acState = null; }
function updateMatchBadge(i) {
  const d = getDoc();
  const tr = $(`#docView tr[data-row="${i}"]`);
  if (!tr || !d) return;
  const tmp = document.createElement('tbody');
  tmp.innerHTML = rowHtml(d.lines[i], i, true);
  const nb = $('.mb', tmp), ob = $('.mb', tr);
  if (nb && ob) ob.replaceWith(nb);
}

// ---------------- события ----------------
function bind() {
  $('#fileInput').onchange = (e) => { uploadFiles(e.target.files); e.target.value = ''; };
  $('#fileInput2').onchange = (e) => { uploadFiles(e.target.files); e.target.value = ''; };
  $('#batchSel').onchange = (e) => openBatch(e.target.value);
  $('#btnNewBatch').onclick = async () => {
    const name = prompt('Название пачки', 'Пачка от ' + new Date().toLocaleString('ru-RU').slice(0, 17));
    if (name === null) return;
    const j = await api('batch_new', {}, {name});
    await refreshBatchList();
    S.batch = j.batch; S.sel = null; renderBatchSel(); render();
    localStorage.setItem('rspz.batch', j.batch.id);
  };

  // drag & drop файлов
  let dragDepth = 0;
  window.addEventListener('dragenter', (e) => { if (e.dataTransfer?.types.includes('Files')) { dragDepth++; $('#dropOverlay').classList.remove('hidden'); } });
  window.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; $('#dropOverlay').classList.add('hidden'); } });
  window.addEventListener('dragover', (e) => e.preventDefault());
  window.addEventListener('drop', (e) => {
    e.preventDefault(); dragDepth = 0; $('#dropOverlay').classList.add('hidden');
    if (e.dataTransfer?.files?.length) uploadFiles(e.dataTransfer.files);
  });

  // вкладки
  $('#docTabs').onclick = (e) => {
    const t = e.target.closest('.tab');
    if (!t) return;
    S.sel = t.dataset.doc ? {type: 'doc', id: t.dataset.doc} : {type: 'page', id: t.dataset.page};
    renderTabs(); renderViewer(); renderRight();
    $('#viewer').scrollTop = 0;
  };

  // меню
  document.addEventListener('click', (e) => {
    const dd = e.target.closest('.dropdown');
    $$('.dropdown.open').forEach((x) => x !== dd && x.classList.remove('open'));
    if (dd && e.target.closest('.dropdown > .btn')) dd.classList.toggle('open');
    else if (dd && e.target.closest('.menu a')) dd.classList.remove('open');
    if (!e.target.closest('#acBox') && !e.target.closest('[data-k="match"]')) acClose();
  });
  $('#exportMenu').onclick = async (e) => {
    const a = e.target.closest('[data-exp]');
    if (!a || !S.batch && a.dataset.exp !== 'logout') return;
    const x = a.dataset.exp;
    if (x === 'logout') { await api('logout', {}, {}); location.reload(); return; }
    if (x === 'rename') {
      const name = prompt('Название пачки', S.batch.name);
      if (name) { const j = await api('batch_rename', {id: S.batch.id}, {name}); S.batch.name = j.batch.name; refreshBatchList(); }
      return;
    }
    if (x === 'delete') {
      if (!confirm('Удалить пачку «' + S.batch.name + '» со всеми сканами?')) return;
      await api('batch_delete', {id: S.batch.id}, {});
      S.batch = null; await refreshBatchList();
      openBatch(S.batches[0]?.id || '');
      return;
    }
    const only = x.endsWith('1') ? '1' : '0';
    const fmtX = x.startsWith('xml') ? 'xml' : 'zip';
    const unchecked = docs().filter((d) => !d.checked).length;
    if (only === '1' && !docs().some((d) => d.checked)) { toast('Нет проверенных документов — отметьте «Проверено»', 'err'); return; }
    if (only === '0' && unchecked && !confirm('Непроверенных документов: ' + unchecked + '. Всё равно выгрузить все?')) return;
    downloadUrl(`api.php?a=export_batch&batch=${S.batch.id}&only_checked=${only}&format=${fmtX}`);
    setTimeout(async () => { const j = await api('batch', {id: S.batch.id}); S.batch = j.batch; renderTabs(); }, 2500);
  };

  // справочник
  $('#btnCatalog').onclick = () => {
    $('#catalogInfo').textContent = S.catalog.count
      ? `Загружено позиций: ${S.catalog.count} (${S.catalog.source || ''}, ${new Date(S.catalog.updated).toLocaleString('ru-RU')}). Запомнено соответствий: ${S.catalog.mappings}.`
      : 'Справочник ещё не загружен.' + (S.catalog.mappings ? ' Запомнено соответствий: ' + S.catalog.mappings : '');
    $('#catalogDlg').showModal();
  };
  $('#catalogFile').onchange = async (e) => {
    const f = e.target.files[0];
    e.target.value = '';
    if (!f) return;
    const fd = new FormData(); fd.append('file', f);
    try {
      const j = await api('catalog_upload', {}, fd);
      S.catalog = j.catalog;
      toast('Загружено позиций: ' + j.count, 'ok');
      $('#catalogDlg').close();
      if (S.batch?.documents.length) { const r = await api('rematch', {batch: S.batch.id}, {}); S.batch = r.batch; render(); }
    } catch (err) { toast(err.message, 'err'); }
  };

  // масштаб и панорамирование
  $$('[data-zoom]').forEach((b) => b.onclick = () => { const z = +b.dataset.zoom; if (z === 0) { S.zoom = 'fit'; applyZoom(); } else zoomBy(z); });
  const viewer = $('#viewer');
  viewer.addEventListener('wheel', (e) => { if (e.ctrlKey) { e.preventDefault(); zoomBy(e.deltaY < 0 ? 1 : -1); } }, {passive: false});
  let pan = null;
  viewer.addEventListener('mousedown', (e) => { if (e.target.tagName === 'IMG') { pan = {x: e.clientX, y: e.clientY, l: viewer.scrollLeft, t: viewer.scrollTop}; viewer.classList.add('panning'); e.preventDefault(); } });
  window.addEventListener('mousemove', (e) => { if (pan) { viewer.scrollLeft = pan.l - (e.clientX - pan.x); viewer.scrollTop = pan.t - (e.clientY - pan.y); } });
  window.addEventListener('mouseup', () => { pan = null; viewer.classList.remove('panning'); });
  window.addEventListener('resize', () => S.zoom === 'fit' && applyZoom());
  viewer.onclick = async (e) => {
    const rot = e.target.closest('[data-rot]'), rr = e.target.closest('[data-rerec]');
    if (rot) {
      const j = await api('rotate', {batch: S.batch.id, page: rot.dataset.rot, deg: 90}, {});
      S.batch.pages = j.batch.pages; renderViewer();
      toast('Повернул. Если страница была распознана криво — нажмите «распознать заново».');
    }
    if (rr) rerecognize([rr.dataset.rerec]);
  };

  // разделитель панелей
  const split = $('#split'), gutter = $('#gutter');
  const savedW = localStorage.getItem('rspz.leftW');
  if (savedW) split.style.setProperty('--left', savedW);
  gutter.onmousedown = (e) => {
    e.preventDefault();
    const move = (ev) => {
      const r = split.getBoundingClientRect();
      const pct = Math.min(75, Math.max(20, (ev.clientX - r.left) / r.width * 100)).toFixed(1) + '%';
      split.style.setProperty('--left', pct);
      localStorage.setItem('rspz.leftW', pct);
      if (S.zoom === 'fit') applyZoom();
    };
    const up = () => { window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); };
    window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
  };

  // правая панель: ввод
  const right = $('#docView');
  right.addEventListener('input', (e) => {
    const el = e.target, d = getDoc();
    if (!d) return;
    if (el.dataset.f) setPath(d, el.dataset.f, el.dataset.f.endsWith('inn') || el.dataset.f.endsWith('kpp') ? el.value.replace(/\D/g, '') : el.value.trim() || null);
    else if (el.dataset.t) d.totals[el.dataset.t] = parseNum(el.value);
    else if (el.dataset.k === 'match') { acOpen(el); return; }
    else if (el.dataset.k) {
      const l = d.lines[+el.dataset.i], k = el.dataset.k;
      l[k] = MONEY.includes(k) || k === 'qty' ? parseNum(el.value) : (el.value.trim() || (k === 'name' ? '' : null));
      l.uncertain = (l.uncertain || []).filter((x) => x !== k); // поправили руками — снимаем «не уверен»
      if (k === 'name') autoGrow(el);
    } else return;
    scheduleSave(); refreshChecks();
  });
  right.addEventListener('change', (e) => {
    const el = e.target, d = getDoc();
    if (!d) return;
    if (el.tagName === 'SELECT' && el.dataset.f) { setPath(d, el.dataset.f, el.value); scheduleSave(); refreshChecks(); renderTabs(); }
    if (el.dataset.f === 'number') renderTabs();
  });
  right.addEventListener('focusout', (e) => {
    const el = e.target;
    if (el.classList.contains('num')) {
      const d = getDoc();
      if (!d) return;
      const v = el.dataset.t ? d.totals[el.dataset.t] : d.lines[+el.dataset.i]?.[el.dataset.k];
      el.value = fmt(v, el.dataset.k);
    }
    if (el.dataset.k === 'match') {
      setTimeout(() => {
        if (S.acState?.input === el && !$('#acBox').classList.contains('hidden') && document.activeElement?.closest?.('#acBox')) return;
        const d = getDoc(), i = +el.dataset.i, l = d?.lines[i];
        if (!l) return;
        const txt = el.value.trim();
        if (txt === (l.match?.name || '')) return;
        l.match = txt ? {code: '', name: txt, article: '', how: 'text', score: 0} : null;
        updateMatchBadge(i); scheduleSave(); refreshChecks();
      }, 150);
    }
  });
  right.addEventListener('keydown', (e) => {
    const el = e.target;
    if (el.dataset.k === 'match' && S.acState && !$('#acBox').classList.contains('hidden')) {
      const its = $$('.ac-it');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        S.acState.idx = (S.acState.idx + (e.key === 'ArrowDown' ? 1 : -1) + its.length) % its.length;
        its.forEach((x, n) => x.classList.toggle('on', n === S.acState.idx));
        return;
      }
      if (e.key === 'Enter') { e.preventDefault(); acPick(S.acState.idx); return; }
      if (e.key === 'Escape') { acClose(); return; }
    }
    if (e.key === 'Enter' && el.dataset.k && !(el.tagName === 'TEXTAREA' && e.shiftKey)) {
      e.preventDefault();
      const next = $(`#docView [data-i="${+el.dataset.i + (e.shiftKey ? -1 : 1)}"][data-k="${el.dataset.k}"]`);
      if (next) { next.focus(); next.select?.(); }
    }
  });
  right.addEventListener('focusin', (e) => {
    const tr = e.target.closest('tr[data-row]');
    $$('#docView tr.cur').forEach((x) => x.classList.remove('cur'));
    if (tr) tr.classList.add('cur');
  });
  $('#acBox').addEventListener('mousedown', (e) => { const it = e.target.closest('[data-ac]'); if (it) { e.preventDefault(); acPick(+it.dataset.ac); } });

  right.addEventListener('click', async (e) => {
    const d = getDoc();
    if (!d) {
      const r = e.target.closest('[data-retry]');
      if (r) { const p = pageById(r.dataset.retry); p.status = 'new'; renderRight(); renderTabs(); runQueue(); }
      return;
    }
    const t = e.target;
    const iss = t.closest('[data-iss]');
    if (iss) { focusRef($('#issues')._issues[+iss.dataset.iss]?.ref); return; }
    const del = t.closest('[data-del]');
    if (del) { d.lines.splice(+del.dataset.del, 1); renderRight(); scheduleSave(); renderTabs(); return; }
    const go = t.closest('[data-goto]');
    if (go && go.dataset.goto) { $(`#viewer [data-pg="${go.dataset.goto}"]`)?.scrollIntoView({behavior: 'smooth'}); return; }
    const mb = t.closest('[data-mb]');
    if (mb) {
      const l = d.lines[+mb.dataset.mb];
      if (l.match?.how === 'fuzzy') { l.match.how = 'confirmed'; updateMatchBadge(+mb.dataset.mb); scheduleSave(); refreshChecks(); renderTabs(); }
      else $(`#docView [data-i="${mb.dataset.mb}"][data-k="match"]`)?.focus();
      return;
    }
    if (t.id === 'btnAddRow') {
      const last = d.lines[d.lines.length - 1];
      d.lines.push({n: (last?.n || d.lines.length) + 1, code: null, name: '', unit: last?.unit || 'шт', unit_code: last?.unit_code || null, qty: null, price: null,
        sum_no_vat: null, vat_rate: last?.vat_rate || null, vat_sum: null, sum_with_vat: null, uncertain: [], page: last?.page || d.pages[0], match: null});
      renderRight(); scheduleSave();
      $(`#docView [data-i="${d.lines.length - 1}"][data-k="name"]`)?.focus();
      return;
    }
    if (t.id === 'btnChecked') {
      if (!d.checked) {
        const v = validate(d);
        if (v.errors && !confirm('В документе ' + v.errors + ' ошибок. Всё равно отметить проверенным?')) return;
      }
      d.checked = !d.checked;
      await saveDoc(d);
      renderRight(); renderTabs();
      if (d.checked) { const nxt = docs().find((x) => !x.checked); if (nxt) toast('Следующий непроверенный: ' + (nxt.number || '?')); }
      return;
    }
    if (t.id === 'btnExport') {
      clearTimeout(S.saveTimer); await saveDoc(d);
      downloadUrl(`api.php?a=export_doc&batch=${S.batch.id}&doc=${d.id}`);
      d.exported_at = new Date().toISOString(); renderTabs();
      return;
    }
    if (t.id === 'actRerec') { if (confirm('Распознать заново? Ручные правки в этом документе пропадут.')) rerecognize(d.pages); return; }
    if (t.id === 'actMerge') {
      if (docs().indexOf(d) === 0) { toast('Это первый документ'); return; }
      if (!confirm('Присоединить этот документ (страницы и строки) к предыдущему?')) return;
      const j = await api('merge_prev', {batch: S.batch.id, doc: d.id}, {});
      const idx = docs().indexOf(d);
      S.batch = j.batch; S.sel = {type: 'doc', id: docs()[idx - 1].id}; render();
      return;
    }
    if (t.id === 'actRematch') {
      const j = await api('rematch', {batch: S.batch.id}, {}); S.batch = j.batch; render(); toast('Готово');
      return;
    }
    if (t.id === 'actDelete') {
      if (!confirm('Удалить документ и его страницы из пачки?')) return;
      const j = await api('delete_doc', {batch: S.batch.id, doc: d.id}, {});
      S.batch = j.batch; S.sel = null; selectDefault(); render();
    }
  });
}

async function rerecognize(pageIds) {
  const d = docs().find((x) => x.pages.includes(pageIds[0]));
  setProgress('Распознаю заново…');
  try {
    for (const pid of pageIds) {
      const p = pageById(pid);
      p.status = 'processing';
      const j = await api('recognize', {batch: S.batch.id, page: pid}, {});
      Object.assign(p, j.page);
      if (j.page.status === 'error') throw new Error(j.page.error);
    }
    if (d) {
      const j = await api('rebuild_doc', {batch: S.batch.id, doc: d.id}, {});
      S.batch = j.batch;
    } else {
      const j = await api('build', {batch: S.batch.id}, {});
      S.batch = j.batch;
    }
    render(); toast('Готово', 'ok');
  } catch (e) { toast(e.message, 'err'); render(); }
  setProgress('');
}

function downloadUrl(url) {
  const a = document.createElement('a');
  a.href = url; a.download = '';
  document.body.appendChild(a); a.click(); a.remove();
}

function autoGrow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
new MutationObserver(() => $$('#docView textarea').forEach(autoGrow)).observe(document.getElementById('docView'), {childList: true});

// ---------------- старт ----------------
(async function init() {
  bind();
  try {
    await loadState();
    if (S.cfg.mock) toast('Тестовый режим: распознавание не настоящее (mock в config.php)');
    if (!S.cfg.auth) $('#logoutLink')?.remove();
    const last = localStorage.getItem('rspz.batch');
    const id = S.batches.find((b) => b.id === last)?.id || S.batches[0]?.id;
    if (id) await openBatch(id); else render();
  } catch (e) { toast(e.message, 'err'); render(); }
})();
