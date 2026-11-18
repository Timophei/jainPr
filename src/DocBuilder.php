<?php
declare(strict_types=1);

/** Склейка распознанных страниц в документы + сопоставление номенклатуры. */
final class DocBuilder
{
    /** Собирает документы из страниц, которые ещё ни в один документ не попали. */
    public static function buildNew(array $batch): array
    {
        $assigned = [];
        foreach ($batch['documents'] as $d) {
            foreach ($d['pages'] as $pid) {
                $assigned[$pid] = true;
            }
        }
        $cur = null;
        $new = [];
        foreach ($batch['pages'] as $p) {
            if (isset($assigned[$p['id']]) || $p['status'] !== 'done' || !$p['result']) {
                continue;
            }
            $r = $p['result'];
            $kind = $r['page_kind'] ?? 'document_start';
            $hasLines = !empty($r['lines']);

            if ($kind === 'other' && !$hasLines) {
                if ($cur !== null) {
                    $new[$cur]['pages'][] = $p['id'];
                    $new[$cur]['notes'] = array_merge($new[$cur]['notes'], $r['handwritten_notes'] ?? []);
                } else {
                    $new[] = self::newDoc($r, $p['id']);
                    $cur = array_key_last($new);
                }
                continue;
            }
            $startNew = $cur === null
                || ($kind === 'document_start' && !self::sameDoc($new[$cur], $r));
            if ($startNew) {
                $new[] = self::newDoc($r, $p['id']);
                $cur = array_key_last($new);
            } else {
                self::appendPage($new[$cur], $r, $p['id']);
            }
        }
        foreach ($new as &$d) {
            self::finalize($d);
        }
        unset($d);
        $batch['documents'] = array_merge($batch['documents'], $new);
        return $batch;
    }

    /** Пересобрать один документ из его страниц (после перераспознавания). */
    public static function rebuildDoc(array $batch, string $docId): array
    {
        foreach ($batch['documents'] as $i => $d) {
            if ($d['id'] !== $docId) {
                continue;
            }
            $pagesById = array_column($batch['pages'], null, 'id');
            $doc = null;
            foreach ($d['pages'] as $pid) {
                $p = $pagesById[$pid] ?? null;
                if (!$p || !$p['result']) {
                    continue;
                }
                if ($doc === null) {
                    $doc = self::newDoc($p['result'], $pid);
                    $doc['id'] = $docId;
                } else {
                    self::appendPage($doc, $p['result'], $pid);
                }
            }
            if ($doc) {
                $doc['pages'] = $d['pages'];
                self::finalize($doc);
                $batch['documents'][$i] = $doc;
            }
        }
        return $batch;
    }

    public static function mergeWithPrev(array $batch, string $docId): array
    {
        $docs = $batch['documents'];
        foreach ($docs as $i => $d) {
            if ($d['id'] === $docId && $i > 0) {
                $prev = &$docs[$i - 1];
                $prev['pages'] = array_merge($prev['pages'], $d['pages']);
                $prev['lines'] = array_merge($prev['lines'], $d['lines']);
                $prev['notes'] = array_values(array_unique(array_merge($prev['notes'], $d['notes'])));
                $prev['warnings'] = array_merge($prev['warnings'], $d['warnings']);
                if (array_filter($d['totals'], static fn($v) => $v !== null)) {
                    $prev['totals'] = $d['totals'];
                }
                $prev['checked'] = false;
                unset($prev);
                array_splice($docs, $i, 1);
                break;
            }
        }
        $batch['documents'] = $docs;
        return $batch;
    }

    private static function sameDoc(array $doc, array $r): bool
    {
        $n = trim((string)($r['doc_number'] ?? ''));
        return $n !== '' && $n === trim((string)$doc['number'])
            && (($r['doc_date'] ?? null) === null || $r['doc_date'] === $doc['date']);
    }

    private static function newDoc(array $r, string $pageId): array
    {
        $party = static fn($x) => [
            'name' => $x['name'] ?? null,
            'inn' => isset($x['inn']) ? preg_replace('/\D/', '', $x['inn']) : null,
            'kpp' => isset($x['kpp']) ? preg_replace('/\D/', '', $x['kpp']) : null,
            'address' => $x['address'] ?? null,
        ];
        $d = [
            'id' => 'd' . bin2hex(random_bytes(4)),
            'pages' => [$pageId],
            'type' => $r['doc_type'] ?? null,
            'number' => $r['doc_number'] ?? null,
            'date' => $r['doc_date'] ?? null,
            'upd_status' => $r['upd_status'] ?? null,
            'seller' => $party($r['seller'] ?? []),
            'buyer' => $party($r['buyer'] ?? []),
            'contract_id' => $r['contract_id'] ?? null,
            'currency' => $r['currency'] ?? null,
            'lines' => [],
            'totals' => ['sum_no_vat' => null, 'vat_sum' => null, 'sum_with_vat' => null],
            'notes' => $r['handwritten_notes'] ?? [],
            'warnings' => $r['warnings'] ?? [],
            'checked' => false,
            'exported_at' => null,
        ];
        self::appendLines($d, $r, $pageId);
        self::takeTotals($d, $r);
        return $d;
    }

    private static function appendPage(array &$d, array $r, string $pageId): void
    {
        $d['pages'][] = $pageId;
        foreach (['type' => 'doc_type', 'number' => 'doc_number', 'date' => 'doc_date'] as $k => $rk) {
            if (empty($d[$k]) && !empty($r[$rk])) {
                $d[$k] = $r[$rk];
            }
        }
        foreach (['seller', 'buyer'] as $side) {
            foreach (['name', 'inn', 'kpp', 'address'] as $f) {
                if (empty($d[$side][$f]) && !empty($r[$side][$f])) {
                    $d[$side][$f] = $f === 'inn' || $f === 'kpp' ? preg_replace('/\D/', '', $r[$side][$f]) : $r[$side][$f];
                }
            }
        }
        $d['notes'] = array_values(array_unique(array_merge($d['notes'], $r['handwritten_notes'] ?? [])));
        $d['warnings'] = array_merge($d['warnings'], $r['warnings'] ?? []);
        self::appendLines($d, $r, $pageId);
        self::takeTotals($d, $r);
    }

    private static function appendLines(array &$d, array $r, string $pageId): void
    {
        foreach ($r['lines'] ?? [] as $l) {
            $d['lines'][] = [
                'n' => $l['n'] ?? null,
                'code' => $l['code'] ?? null,
                'name' => $l['name'] ?? '',
                'unit_code' => $l['unit_code'] ?? null,
                'unit' => $l['unit'] ?? null,
                'qty' => $l['qty'] ?? null,
                'price' => $l['price'] ?? null,
                'sum_no_vat' => $l['sum_no_vat'] ?? null,
                'vat_rate' => $l['vat_rate'] ?? null,
                'vat_sum' => $l['vat_sum'] ?? null,
                'sum_with_vat' => $l['sum_with_vat'] ?? null,
                'country' => $l['country'] ?? null,
                'gtd' => $l['gtd'] ?? null,
                'uncertain' => array_values($l['uncertain_fields'] ?? []),
                'page' => $pageId,
                'match' => null,
            ];
        }
    }

    private static function takeTotals(array &$d, array $r): void
    {
        $t = $r['totals'] ?? [];
        if (array_filter($t, static fn($v) => $v !== null)) {
            $d['totals'] = [
                'sum_no_vat' => $t['sum_no_vat'] ?? null,
                'vat_sum' => $t['vat_sum'] ?? null,
                'sum_with_vat' => $t['sum_with_vat'] ?? null,
            ];
        }
    }

    /** Сопоставление с номенклатурой 1С + пометка дублей. */
    public static function finalize(array &$d): void
    {
        foreach ($d['lines'] as &$l) {
            $how = $l['match']['how'] ?? null;
            if (in_array($how, ['manual', 'confirmed'], true)) {
                continue;
            }
            $l['match'] = Catalog::match($l, $d['seller']['inn'] ?? null);
        }
        unset($l);
        $d['duplicate'] = Exporter::wasExported($d);
    }
}
