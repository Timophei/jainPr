<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$authed = is_authed();
$v = substr(md5((string)@filemtime(__DIR__ . '/assets/app.js') . @filemtime(__DIR__ . '/assets/app.css')), 0, 8);
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Распознавалка первички</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<?php if (!$authed): ?>
<form class="login" id="login">
  <h1>Распознавалка первички</h1>
  <input type="password" name="password" placeholder="Пароль" autofocus required>
  <button>Войти</button>
  <div class="login-err" id="loginErr"></div>
</form>
<script>
document.getElementById('login').onsubmit = async (e) => {
  e.preventDefault();
  const r = await fetch('api.php?a=login', {method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({password: e.target.password.value})});
  const j = await r.json();
  if (j.ok) location.reload(); else document.getElementById('loginErr').textContent = j.error;
};
</script>
<?php else: ?>
<header class="top">
  <div class="brand">Распознавалка <span class="muted">первичка → 1С</span></div>
  <select id="batchSel" title="Пачка документов"></select>
  <button class="btn ghost" id="btnNewBatch" title="Новая пачка">＋ Пачка</button>
  <label class="btn primary" title="JPG, PNG, PDF, TIFF — можно сразу много">
    ⬆ Загрузить сканы<input type="file" id="fileInput" multiple accept="image/*,.pdf,.tif,.tiff,.heic" hidden>
  </label>
  <div class="spacer"></div>
  <span id="progress" class="progress"></span>
  <button class="btn ghost" id="btnCatalog" title="Справочник номенклатуры 1С">📚 Номенклатура 1С</button>
  <div class="dropdown">
    <button class="btn" id="btnExportAll">⬇ Выгрузить пачку ▾</button>
    <div class="menu" id="exportMenu">
      <a data-exp="zip1">ZIP: проверенные документы (XLSX + XML)</a>
      <a data-exp="zip0">ZIP: все документы</a>
      <a data-exp="xml1">XML: проверенные документы</a>
      <hr>
      <a data-exp="rename">Переименовать пачку</a>
      <a data-exp="delete" class="danger">Удалить пачку</a>
      <a data-exp="logout" id="logoutLink">Выйти</a>
    </div>
  </div>
</header>

<nav id="docTabs" class="tabs"></nav>

<main id="split">
  <section id="left">
    <div class="viewer-tools">
      <button class="btn sm" data-zoom="-1" title="Уменьшить">−</button>
      <span id="zoomVal">100%</span>
      <button class="btn sm" data-zoom="1" title="Увеличить">＋</button>
      <button class="btn sm" data-zoom="0" title="По ширине">⇔ по ширине</button>
      <span class="muted small">Ctrl+колесо — масштаб, перетаскивание — сдвиг</span>
    </div>
    <div id="viewer"></div>
  </section>
  <div id="gutter" title="Потяните, чтобы изменить ширину"></div>
  <section id="right">
    <div id="docView"></div>
  </section>
</main>

<div id="empty" class="empty hidden">
  <div class="empty-box">
    <div class="big">⬆</div>
    <h2>Перетащите сюда сканы документов</h2>
    <p>УПД, ТОРГ-12, счета-фактуры — JPG, PNG, PDF, TIFF. Можно сразу пачкой.<br>
      Многостраничные документы склеятся сами, порядок — как загрузили.</p>
    <label class="btn primary">Выбрать файлы<input type="file" id="fileInput2" multiple accept="image/*,.pdf,.tif,.tiff,.heic" hidden></label>
  </div>
</div>

<div id="dropOverlay" class="drop-overlay hidden"><div>Отпустите файлы — начну распознавать</div></div>

<dialog id="catalogDlg">
  <form method="dialog" class="dlg">
    <h3>Справочник номенклатуры 1С</h3>
    <p id="catalogInfo" class="muted"></p>
    <p class="small">Выгрузите список номенклатуры из 1С (Номенклатура → Ещё → Вывести список → сохранить как XLSX или CSV).
      Нужны колонки <b>Наименование</b>, желательно <b>Артикул</b> и <b>Код</b>.
      Распознанные строки будут сопоставляться с ним: по артикулу, по ранее выбранному вручную и нечётко по названию.</p>
    <label class="btn primary">Загрузить XLSX / CSV<input type="file" id="catalogFile" accept=".xlsx,.csv,.txt" hidden></label>
    <div class="dlg-foot"><button class="btn">Закрыть</button></div>
  </form>
</dialog>

<div id="acBox" class="ac hidden"></div>
<div id="toast" class="toast hidden"></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
<?php endif; ?>
</body>
</html>
