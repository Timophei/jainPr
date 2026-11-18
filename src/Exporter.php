<?php
declare(strict_types=1);

/**
 * Выгрузка для 1С:
 *  - XLSX на документ: лист «Товары» (для «Загрузить из файла» в табличной части
 *    «Приобретения товаров и услуг») + лист «Документ» с реквизитами шапки;
 *  - XML со всеми документами пачки — для обработки загрузки в 1С;
 *  - ZIP со всем этим.
 */
final class Exporter
{
    public const GOODS_HEADER = ['№', 'Артикул', 'Номенклатура', 'Код 1С', 'Количество', 'Ед. изм.', 'Цена', 'Сумма',
        'Ставка НДС', 'Сумма НДС', 'Всего', 'Наименование поставщика', 'Код поставщика', 'Страна', 'ГТД/РНПТ'];

    public static function registryPath(): string
    {
        return data_dir() . '/exported.json';
    }

    public static function key(array $d): ?string
    {
        $inn = $d['seller']['inn'] ?? '';
        $num = mb_strtoupper(preg_replace('/\s+/u', '', (string)($d['number'] ?? '')));
        if ($inn === '' || $num === '') {
            return null;
        }
        return $inn . '|' . $num . '|' . ($d['date'] ?? '');
    }

    /** @return array|null {at, batch, doc} если такой документ уже выгружали в другой раз */
    public static function wasExported(array $d): ?array
    {
        $k = self::key($d);
        if (!$k) {
            return null;
        }
        $reg = Storage::readJson(self::registryPath(), []);
        $hit = $reg[$k] ?? null;
        if ($hit && ($hit['doc'] ?? '') === $d['id']) {
            return null; // это он сам
        }
        return $hit;
    }

    public static function register(array $d, string $batchId): void
    {
        $k = self::key($d);
        if (!$k) {
            return;
        }
        $reg = Storage::readJson(self::registryPath(), []);
        if (!isset($reg[$k]) || ($reg[$k]['doc'] ?? '') === $d['id']) {
            $reg[$k] = ['at' => date('c'), 'batch' => $batchId, 'doc' => $d['id']];
            Storage::writeJson(self::registryPath(), $reg);
        }
    }

    public static function fileBase(array $d): string
    {
        $seller = preg_replace('/^(ООО|АО|ПАО|ЗАО|ИП)\s*/u', '', str_replace(['"', '«', '»'], '', (string)($d['seller']['name'] ?? '')));
        $date = $d['date'] ? date('d.m.Y', strtotime($d['date'])) : '';
        $s = trim(($d['type'] ?? 'Документ') . ' ' . ($d['number'] ?? '') . ' от ' . $date . ' ' . $seller);
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $s), ' ._') ?: 'document';
    }

    public static function goodsRows(array $d): array
    {
        $rows = [self::GOODS_HEADER];
        $i = 0;
        foreach ($d['lines'] as $l) {
            $m = $l['match'] ?? null;
            $rows[] = [
                ++$i,
                ($m['article'] ?? '') ?: ($l['code'] ?? ''),
                ($m['name'] ?? '') ?: $l['name'],
                $m['code'] ?? '',
                $l['qty'],
                $l['unit'] ?? '',
                $l['price'],
                $l['sum_no_vat'],
                $l['vat_rate'] ?? '',
                $l['vat_sum'],
                $l['sum_with_vat'],
                $l['name'],
                $l['code'] ?? '',
                $l['country'] ?? '',
                $l['gtd'] ?? '',
            ];
        }
        return $rows;
    }

    public static function headerRows(array $d): array
    {
        return [
            ['Реквизит', 'Значение'],
            ['Вид документа', $d['type'] ?? ''],
            ['Номер', $d['number'] ?? ''],
            ['Дата', $d['date'] ? date('d.m.Y', strtotime($d['date'])) : ''],
            ['Статус УПД', $d['upd_status'] ?? ''],
            ['Поставщик', $d['seller']['name'] ?? ''],
            ['ИНН поставщика', $d['seller']['inn'] ?? ''],
            ['КПП поставщика', $d['seller']['kpp'] ?? ''],
            ['Покупатель', $d['buyer']['name'] ?? ''],
            ['ИНН покупателя', $d['buyer']['inn'] ?? ''],
            ['КПП покупателя', $d['buyer']['kpp'] ?? ''],
            ['Идентификатор госконтракта', $d['contract_id'] ?? ''],
            ['Валюта', $d['currency'] ?? ''],
            ['Сумма без НДС', $d['totals']['sum_no_vat']],
            ['Сумма НДС', $d['totals']['vat_sum']],
            ['Сумма с НДС', $d['totals']['sum_with_vat']],
            ['Пометки на скане', implode('; ', $d['notes'] ?? [])],
        ];
    }

    public static function docXlsx(array $d, string $path): void
    {
        Xlsx::write($path, [
            'Товары' => self::goodsRows($d),
            'Документ' => self::headerRows($d),
        ], [
            'Товары' => [5, 16, 50, 12, 11, 8, 12, 13, 10, 12, 13, 50, 16, 10, 22],
            'Документ' => [28, 70],
        ]);
    }

    public static function batchXml(array $docs): string
    {
        $x = new XMLWriter();
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('ПоступленияТоваров');
        $x->writeAttribute('Сформировано', date('c'));
        foreach ($docs as $d) {
            $x->startElement('Документ');
            foreach (['Вид' => $d['type'], 'Номер' => $d['number'], 'Дата' => $d['date'], 'СтатусУПД' => $d['upd_status'],
                         'ИдГосКонтракта' => $d['contract_id']] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $x->writeAttribute($k, (string)$v);
                }
            }
            foreach (['Поставщик' => $d['seller'], 'Покупатель' => $d['buyer']] as $tag => $p) {
                $x->startElement($tag);
                $x->writeAttribute('Наименование', (string)($p['name'] ?? ''));
                $x->writeAttribute('ИНН', (string)($p['inn'] ?? ''));
                $x->writeAttribute('КПП', (string)($p['kpp'] ?? ''));
                $x->endElement();
            }
            $x->startElement('Товары');
            foreach ($d['lines'] as $i => $l) {
                $m = $l['match'] ?? [];
                $x->startElement('Строка');
                $attrs = [
                    'НомерСтроки' => $i + 1,
                    'НоменклатураКод' => $m['code'] ?? '',
                    'НоменклатураНаименование' => ($m['name'] ?? '') ?: $l['name'],
                    'Артикул' => ($m['article'] ?? '') ?: ($l['code'] ?? ''),
                    'НаименованиеПоставщика' => $l['name'],
                    'КодПоставщика' => $l['code'] ?? '',
                    'ЕдиницаКод' => $l['unit_code'] ?? '',
                    'Единица' => $l['unit'] ?? '',
                    'Количество' => $l['qty'],
                    'Цена' => $l['price'],
                    'Сумма' => $l['sum_no_vat'],
                    'СтавкаНДС' => $l['vat_rate'] ?? '',
                    'СуммаНДС' => $l['vat_sum'],
                    'Всего' => $l['sum_with_vat'],
                    'Страна' => $l['country'] ?? '',
                    'ГТД' => $l['gtd'] ?? '',
                ];
                foreach ($attrs as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $x->writeAttribute($k, (string)$v);
                    }
                }
                $x->endElement();
            }
            $x->endElement();
            $x->startElement('Итого');
            foreach (['СуммаБезНДС' => 'sum_no_vat', 'СуммаНДС' => 'vat_sum', 'Всего' => 'sum_with_vat'] as $k => $f) {
                if ($d['totals'][$f] !== null) {
                    $x->writeAttribute($k, (string)$d['totals'][$f]);
                }
            }
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
        $x->endDocument();
        return $x->outputMemory();
    }

    /** ZIP: XLSX на каждый документ + documents.xml. */
    public static function batchZip(array $batch, array $docs, string $path): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $tmpFiles = [];
        $used = [];
        foreach ($docs as $d) {
            $base = self::fileBase($d);
            $name = $base;
            $k = 1;
            while (isset($used[$name])) {
                $name = $base . ' (' . (++$k) . ')';
            }
            $used[$name] = true;
            $tmp = tempnam(sys_get_temp_dir(), 'rx');
            self::docXlsx($d, $tmp);
            $zip->addFile($tmp, $name . '.xlsx');
            $tmpFiles[] = $tmp;
        }
        $zip->addFromString('documents.xml', self::batchXml($docs));
        $zip->close();
        foreach ($tmpFiles as $f) {
            @unlink($f);
        }
    }
}
