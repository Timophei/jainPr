<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

set_time_limit(300);
$a = $_GET['a'] ?? '';

function out(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    static $b = null;
    if ($b === null) {
        $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
    }
    return $b;
}

function param(string $k, $default = null)
{
    return $_POST[$k] ?? body()[$k] ?? $_GET[$k] ?? $default;
}

function download(string $path, string $name, string $mime): never
{
    header('Content-Type: ' . $mime);
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}

function num($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_string($v)) {
        $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

function str($v): ?string
{
    if ($v === null) {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : mb_substr($v, 0, 1000);
}

/** Чистим документ, пришедший из браузера. */
function clean_doc(array $in, array $old): array
{
    $party = static fn($p) => [
        'name' => str($p['name'] ?? null),
        'inn' => str(preg_replace('/\D/', '', (string)($p['inn'] ?? ''))),
        'kpp' => str(preg_replace('/\D/', '', (string)($p['kpp'] ?? ''))),
        'address' => str($p['address'] ?? null),
    ];
    $lines = [];
    foreach (($in['lines'] ?? []) as $l) {
        $m = $l['match'] ?? null;
        $lines[] = [
            'n' => isset($l['n']) && $l['n'] !== '' ? (int)$l['n'] : null,
            'code' => str($l['code'] ?? null),
            'name' => (string)str($l['name'] ?? ''),
            'unit_code' => str($l['unit_code'] ?? null),
            'unit' => str($l['unit'] ?? null),
            'qty' => num($l['qty'] ?? null),
            'price' => num($l['price'] ?? null),
            'sum_no_vat' => num($l['sum_no_vat'] ?? null),
            'vat_rate' => str($l['vat_rate'] ?? null),
            'vat_sum' => num($l['vat_sum'] ?? null),
            'sum_with_vat' => num($l['sum_with_vat'] ?? null),
            'country' => str($l['country'] ?? null),
            'gtd' => str($l['gtd'] ?? null),
            'uncertain' => array_values(array_filter((array)($l['uncertain'] ?? []), 'is_string')),
            'page' => str($l['page'] ?? null),
            'match' => is_array($m) && (($m['name'] ?? '') !== '') ? [
                'code' => (string)($m['code'] ?? ''),
                'name' => (string)$m['name'],
                'article' => (string)($m['article'] ?? ''),
                'how' => in_array($m['how'] ?? '', ['mapping', 'article', 'fuzzy', 'manual', 'confirmed', 'text'], true) ? $m['how'] : 'manual',
                'score' => (float)($m['score'] ?? 1),
            ] : null,
        ];
    }
    $t = $in['totals'] ?? [];
    return array_merge($old, [
        'type' => str($in['type'] ?? null),
        'number' => str($in['number'] ?? null),
        'date' => str($in['date'] ?? null),
        'upd_status' => str($in['upd_status'] ?? null),
        'seller' => $party($in['seller'] ?? []),
        'buyer' => $party($in['buyer'] ?? []),
        'contract_id' => str($in['contract_id'] ?? null),
        'currency' => str($in['currency'] ?? null),
        'lines' => $lines,
        'totals' => ['sum_no_vat' => num($t['sum_no_vat'] ?? null), 'vat_sum' => num($t['vat_sum'] ?? null), 'sum_with_vat' => num($t['sum_with_vat'] ?? null)],
        'notes' => array_values(array_filter((array)($in['notes'] ?? []), 'is_string')),
        'checked' => !empty($in['checked']),
    ]);
}

function find_doc(array $batch, string $docId): array
{
    foreach ($batch['documents'] as $d) {
        if ($d['id'] === $docId) {
            return $d;
        }
    }
    fail('Документ не найден', 404);
}

try {
    // ---------- вход ----------
    if ($a === 'login') {
        start_session();
        if (hash_equals((string)cfg('password', ''), (string)param('password', ''))) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            out([]);
        }
        sleep(1);
        fail('Неверный пароль', 403);
    }
    if (!is_authed()) {
        fail('Нужно войти', 401);
    }
    if ($a === 'logout') {
        $_SESSION = [];
        session_destroy();
        out([]);
    }

    switch ($a) {
        case 'state':
            out([
                'cfg' => [
                    'own_inns' => array_map('strval', (array)cfg('own_inns', [])),
                    'parallel' => max(1, (int)cfg('parallel', 2)),
                    'mock' => (bool)cfg('mock', false),
                    'model' => cfg('mock') ? 'mock' : (string)cfg('model'),
                    'auth' => (string)cfg('password', '') !== '',
                ],
                'batches' => Storage::listBatches(),
                'catalog' => Catalog::info(),
            ]);

        case 'batch_new':
            out(['batch' => Storage::newBatch((string)param('name', ''))]);

        case 'batch':
            out(['batch' => Storage::load((string)param('id'))]);

        case 'batch_rename':
            $b = Storage::update((string)param('id'), static function (array $b) {
                $b['name'] = mb_substr(trim((string)param('name', '')), 0, 120) ?: $b['name'];
                return $b;
            });
            out(['batch' => $b]);

        case 'batch_delete':
            Storage::delete((string)param('id'));
            out([]);

        case 'upload':
            $bid = (string)param('batch');
            Storage::batchDir($bid);
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $err = $_FILES['file']['error'] ?? -1;
                fail($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                    ? 'Файл больше лимита upload_max_filesize'
                    : 'Файл не получен (код ' . $err . ')');
            }
            $pages = Ingest::addFile($bid, $_FILES['file']['tmp_name'], (string)$_FILES['file']['name']);
            Storage::update($bid, static function (array $b) use ($pages) {
                $b['pages'] = array_merge($b['pages'], $pages);
                return $b;
            });
            out(['pages' => $pages]);

        case 'recognize':
            session_write_close(); // не блокируем параллельные запросы
            $page = Recognizer::recognizePage((string)param('batch'), (string)param('page'));
            out(['page' => $page]);

        case 'build':
            $b = Storage::update((string)param('batch'), static fn(array $b) => DocBuilder::buildNew($b));
            out(['batch' => $b]);

        case 'rebuild_doc':
            $docId = (string)param('doc');
            $b = Storage::update((string)param('batch'), static fn(array $b) => DocBuilder::rebuildDoc($b, $docId));
            out(['batch' => $b]);

        case 'merge_prev':
            $docId = (string)param('doc');
            $b = Storage::update((string)param('batch'), static fn(array $b) => DocBuilder::mergeWithPrev($b, $docId));
            out(['batch' => $b]);

        case 'delete_doc':
            $docId = (string)param('doc');
            $b = Storage::update((string)param('batch'), static function (array $b) use ($docId) {
                $removedPages = [];
                $b['documents'] = array_values(array_filter($b['documents'], static function ($d) use ($docId, &$removedPages) {
                    if ($d['id'] === $docId) {
                        $removedPages = $d['pages'];
                        return false;
                    }
                    return true;
                }));
                $b['pages'] = array_values(array_filter($b['pages'], static fn($p) => !in_array($p['id'], $removedPages, true)));
                return $b;
            });
            out(['batch' => $b]);

        case 'save_doc':
            $bid = (string)param('batch');
            $in = (array)param('doc', []);
            $saved = null;
            Storage::update($bid, static function (array $b) use ($in, &$saved) {
                foreach ($b['documents'] as &$d) {
                    if ($d['id'] === ($in['id'] ?? '')) {
                        $d = clean_doc($in, $d);
                        $d['duplicate'] = Exporter::wasExported($d);
                        // запоминаем ручные соответствия номенклатуры
                        foreach ($d['lines'] as $l) {
                            if (in_array($l['match']['how'] ?? '', ['manual', 'confirmed'], true)) {
                                Catalog::remember($l, $d['seller']['inn'] ?? null, $l['match']);
                            }
                        }
                        $saved = $d;
                    }
                }
                return $b;
            });
            if (!$saved) {
                fail('Документ не найден', 404);
            }
            out(['doc' => $saved]);

        case 'rotate':
            $bid = (string)param('batch');
            $pid = (string)param('page');
            $deg = (int)param('deg', 90);
            $b = Storage::load($bid);
            foreach ($b['pages'] as $p) {
                if ($p['id'] === $pid) {
                    $f = Storage::batchDir($bid) . '/' . $p['file'];
                    [$w, $h] = Ingest::normalize($f, $f, $deg);
                    $b = Storage::update($bid, static function (array $b) use ($pid, $w, $h) {
                        foreach ($b['pages'] as &$pp) {
                            if ($pp['id'] === $pid) {
                                $pp['w'] = $w;
                                $pp['h'] = $h;
                                $pp['rot'] = ($pp['rot'] ?? 0) + 1;
                            }
                        }
                        return $b;
                    });
                    out(['batch' => $b]);
                }
            }
            fail('Страница не найдена', 404);

        case 'image':
            $bid = (string)param('batch');
            $pid = (string)param('page');
            if (!preg_match('/^p[0-9a-f]{8}$/', $pid)) {
                fail('bad page');
            }
            $f = Storage::batchDir($bid) . '/pages/' . $pid . '.jpg';
            if (!is_file($f)) {
                fail('Нет файла', 404);
            }
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            readfile($f);
            exit;

        case 'export_doc':
            $bid = (string)param('batch');
            $d = find_doc(Storage::load($bid), (string)param('doc'));
            $tmp = tempnam(sys_get_temp_dir(), 'rx');
            Exporter::docXlsx($d, $tmp);
            Exporter::register($d, $bid);
            Storage::update($bid, static function (array $b) use ($d) {
                foreach ($b['documents'] as &$x) {
                    if ($x['id'] === $d['id']) {
                        $x['exported_at'] = date('c');
                    }
                }
                return $b;
            });
            download($tmp, Exporter::fileBase($d) . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        case 'export_batch':
            $bid = (string)param('batch');
            $b = Storage::load($bid);
            $onlyChecked = (string)param('only_checked', '1') === '1';
            $docs = array_values(array_filter($b['documents'], static fn($d) => !$onlyChecked || !empty($d['checked'])));
            if (!$docs) {
                fail('Нет документов для выгрузки' . ($onlyChecked ? ' (отметьте проверенные)' : ''));
            }
            foreach ($docs as $d) {
                Exporter::register($d, $bid);
            }
            $ids = array_column($docs, 'id');
            Storage::update($bid, static function (array $b) use ($ids) {
                foreach ($b['documents'] as &$x) {
                    if (in_array($x['id'], $ids, true)) {
                        $x['exported_at'] = date('c');
                    }
                }
                return $b;
            });
            if (param('format') === 'xml') {
                $tmp = tempnam(sys_get_temp_dir(), 'rx');
                file_put_contents($tmp, Exporter::batchXml($docs));
                download($tmp, 'Поступления ' . date('Y-m-d H-i') . '.xml', 'application/xml');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'rx');
            Exporter::batchZip($b, $docs, $tmp);
            download($tmp, 'Поступления ' . date('Y-m-d H-i') . '.zip', 'application/zip');

        case 'catalog_upload':
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                fail('Файл не получен');
            }
            $n = Catalog::import($_FILES['file']['tmp_name'], (string)$_FILES['file']['name']);
            out(['count' => $n, 'catalog' => Catalog::info()]);

        case 'catalog_search':
            out(['items' => Catalog::search((string)param('q', ''))]);

        case 'rematch':
            // пересопоставить номенклатуру во всех документах пачки (после загрузки справочника)
            $b = Storage::update((string)param('batch'), static function (array $b) {
                foreach ($b['documents'] as &$d) {
                    DocBuilder::finalize($d);
                }
                return $b;
            });
            out(['batch' => $b]);
    }
    fail('Неизвестное действие', 404);
} catch (Throwable $e) {
    fail($e->getMessage(), 500);
}
