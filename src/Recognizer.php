<?php
declare(strict_types=1);

/** Распознавание одной страницы через OpenAI (или mock). */
final class Recognizer
{
    public const PROMPT = <<<TXT
Ты — система распознавания первичных бухгалтерских документов РФ на поступление товаров:
УПД, счёт-фактура, ТОРГ-12, товарная накладная, акт, счёт. На входе — скан ОДНОЙ страницы.
Верни данные строго по JSON-схеме.

Правила:
1. Переписывай значения ровно так, как они напечатаны. НИЧЕГО не придумывай и не досчитывай.
   Если значение не видно или не читается — null, и добавь имя поля в uncertain_fields этой строки.
2. Числа — JSON-числа с точкой и без пробелов: «1 549,92» → 1549.92; количество «3,000» → 3.
3. Одна строка таблицы может занимать несколько строк текста — склей наименование в одну строку,
   сохраняя артикулы, размеры и знаки (1", 1/2", 1-1/2"F x 1"M).
4. code — значение колонки «Код товара/работ, услуг» или «Артикул». Если такой колонки нет — null
   (не выдирай артикул из наименования).
5. unit_code — код по ОКЕИ (например 796), unit — условное обозначение (шт, кг, м).
6. vat_rate — как напечатано: «22%», «20%», «10%», «0%», «без НДС».
7. page_kind: document_start — на странице есть шапка документа (номер, дата, продавец);
   continuation — продолжение таблицы без шапки; other — не товарный документ, пустая страница, оборот.
8. totals заполняй, только если на странице есть итоговая строка («Всего к оплате», «Итого»). Иначе все поля totals = null.
9. Рукописные пометки (номера, «принято», «1/7 ...», расшифровки подписей) переписывай в handwritten_notes как есть,
   спорные символы помечай «?».
10. Даты — YYYY-MM-DD. ИНН, КПП, код ОКЕИ — только цифры.
11. uncertain_fields — поля строки, в которых не уверен (смазано, перекрыто, спорные цифры 3/8, 1/7, 0/6/9, 5/6).
    Поля: code, name, unit_code, unit, qty, price, sum_no_vat, vat_rate, vat_sum, sum_with_vat, country, gtd.
12. В warnings — кратко по-русски о проблемах страницы: обрезан край, не видно часть таблицы, печать перекрывает цифры и т.п.
TXT;

    public static function schema(): array
    {
        $s = ['type' => ['string', 'null']];
        $n = ['type' => ['number', 'null']];
        $party = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'inn', 'kpp', 'address'],
            'properties' => ['name' => $s, 'inn' => $s, 'kpp' => $s, 'address' => $s],
        ];
        $line = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['n', 'code', 'name', 'unit_code', 'unit', 'qty', 'price', 'sum_no_vat', 'excise',
                'vat_rate', 'vat_sum', 'sum_with_vat', 'country', 'gtd', 'uncertain_fields'],
            'properties' => [
                'n' => ['type' => ['integer', 'null']],
                'code' => $s,
                'name' => ['type' => 'string'],
                'unit_code' => $s,
                'unit' => $s,
                'qty' => $n,
                'price' => $n,
                'sum_no_vat' => $n,
                'excise' => $s,
                'vat_rate' => $s,
                'vat_sum' => $n,
                'sum_with_vat' => $n,
                'country' => $s,
                'gtd' => $s,
                'uncertain_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['page_kind', 'doc_type', 'doc_number', 'doc_date', 'upd_status', 'seller', 'buyer',
                'contract_id', 'currency', 'lines', 'totals', 'handwritten_notes', 'warnings'],
            'properties' => [
                'page_kind' => ['type' => 'string', 'enum' => ['document_start', 'continuation', 'other']],
                'doc_type' => ['type' => ['string', 'null'], 'enum' => ['УПД', 'Счет-фактура', 'ТОРГ-12', 'Товарная накладная', 'Акт', 'Счет', 'Другое', null]],
                'doc_number' => $s,
                'doc_date' => $s,
                'upd_status' => $s,
                'seller' => $party,
                'buyer' => $party,
                'contract_id' => $s,
                'currency' => $s,
                'lines' => ['type' => 'array', 'items' => $line],
                'totals' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['sum_no_vat', 'vat_sum', 'sum_with_vat'],
                    'properties' => ['sum_no_vat' => $n, 'vat_sum' => $n, 'sum_with_vat' => $n],
                ],
                'handwritten_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /** Распознаёт страницу и сохраняет результат в пачку. */
    public static function recognizePage(string $batchId, string $pageId): array
    {
        $batch = Storage::load($batchId);
        $page = null;
        $idx = 0;
        foreach ($batch['pages'] as $i => $p) {
            if ($p['id'] === $pageId) {
                $page = $p;
                $idx = $i;
            }
        }
        if (!$page) {
            throw new RuntimeException('Страница не найдена');
        }
        $img = Storage::batchDir($batchId) . '/' . $page['file'];
        $t0 = microtime(true);
        try {
            if (cfg('mock')) {
                usleep(700000);
                $res = ['data' => self::mock($idx), 'usage' => null, 'model' => 'mock'];
            } else {
                $dataUrl = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($img));
                $res = OpenAiClient::fromConfig()->extract(
                    self::PROMPT,
                    'Распознай страницу. Файл: ' . $page['src'] . ', страница ' . $page['src_page'] . '.',
                    [$dataUrl],
                    'primary_doc_page',
                    self::schema()
                );
            }
            $patch = ['status' => 'done', 'error' => null, 'result' => $res['data'],
                'meta' => ['model' => $res['model'], 'usage' => $res['usage'], 'sec' => round(microtime(true) - $t0, 1)]];
        } catch (Throwable $e) {
            $patch = ['status' => 'error', 'error' => $e->getMessage()];
        }
        $batch = Storage::update($batchId, static function (array $b) use ($pageId, $patch) {
            foreach ($b['pages'] as &$p) {
                if ($p['id'] === $pageId) {
                    $p = array_merge($p, $patch);
                }
            }
            return $b;
        });
        foreach ($batch['pages'] as $p) {
            if ($p['id'] === $pageId) {
                return $p;
            }
        }
        return [];
    }

    /** Пример ответа для тестового режима (по образцу УПД ЦБ-2714). */
    private static function mock(int $pageIndex): array
    {
        $L = static fn($n, $code, $name, $qty, $price, $sum, $vat, $total, $unc = []) => [
            'n' => $n, 'code' => $code, 'name' => $name, 'unit_code' => '796', 'unit' => 'шт', 'qty' => $qty,
            'price' => $price, 'sum_no_vat' => $sum, 'excise' => 'без акциза', 'vat_rate' => '22%',
            'vat_sum' => $vat, 'sum_with_vat' => $total, 'country' => null, 'gtd' => null, 'uncertain_fields' => $unc,
        ];
        $party = static fn($n, $i, $k, $a) => ['name' => $n, 'inn' => $i, 'kpp' => $k, 'address' => $a];
        $emptyTotals = ['sum_no_vat' => null, 'vat_sum' => null, 'sum_with_vat' => null];
        if ($pageIndex % 2 === 0) {
            return [
                'page_kind' => 'document_start', 'doc_type' => 'УПД', 'doc_number' => 'ЦБ-2714', 'doc_date' => '2026-11-11',
                'upd_status' => '1',
                'seller' => $party('ООО "СМС-ДОН"', '6162068035', '616201001', '344033, Ростовская обл, г Ростов-на-Дону, ул Портовая, зд. 543, офис 217'),
                'buyer' => $party('ООО "ТМГ"', '7704450802', '773401001', '123308, Москва г, вн.тер. г. Муниципальный Округ Хорошево-Мневники, ул 3-Я Хорошёвская, д. 2, стр. 1'),
                'contract_id' => '007702038150222S0049', 'currency' => 'Российский рубль, 643',
                'lines' => [
                    $L(1, 'PT-IA-05-04', 'Переходник (адаптор) ударный 1-1/2"F x 1"M PT-IA-05-04', 3, 2348.36, 7045.08, 1549.92, 8595.00),
                    $L(2, 'PT-IH-04-27', 'Шестигранник ударный (27 мм; 1") PT-IH-04-27', 3, 2127.05, 6381.15, 1403.85, 7785.00),
                    $L(3, 'PT-IH-04-19', 'Шестигранник ударный (19 мм; 1") PT-IH-04-19', 3, 2127.05, 6381.15, 1403.85, 7785.00),
                    $L(4, 'PT-IH-04-17', 'Шестигранник ударный (17 мм; 1") PT-IH-04-17', 3, 2127.05, 6381.15, 1403.85, 7785.00),
                    $L(5, 'PT-IH-04-14', 'Шестигранник ударный (14 мм; 1") PT-IH-04-14', 3, 2127.05, 6381.15, 1403.85, 7785.00),
                    $L(6, 'PT-IS-04-27', 'Головка ударная стандартная (27 мм; 1"DR) PT-IS-04-27', 3, 897.54, 2692.62, 592.38, 3285.00),
                    $L(7, 'PT-IS-04-36', 'Головка ударная стандартная (36 мм; 1"DR) PT-IS-04-36', 3, 897.54, 2692.62, 592.38, 3285.00),
                    $L(8, 'PT-IS-04-30', 'Головка ударная стандартная (30 мм; 1"DR) ПНЕВМО-ТРЕЙД PT-IS-04-30', 3, 897.54, 2692.62, 592.38, 3285.00),
                    $L(9, 'PT-IS-04-19', 'Головка ударная стандартная 1" 19 мм PT-IS-04-19', 3, 897.54, 2692.62, 592.38, 3285.00),
                    $L(10, 'PT-IS-04-32', 'Головка ударная стандартная 1" 32 мм PT-IS-04-32', 3, 897.54, 2692.62, 592.38, 3285.00, ['code']),
                ],
                'totals' => $emptyTotals,
                'handwritten_notes' => ['1/7 сайга?'],
                'warnings' => [],
            ];
        }
        return [
            'page_kind' => 'continuation', 'doc_type' => null, 'doc_number' => null, 'doc_date' => null, 'upd_status' => null,
            'seller' => $party(null, null, null, null), 'buyer' => $party(null, null, null, null),
            'contract_id' => null, 'currency' => null,
            'lines' => [
                $L(11, 'PT-IS-12-24', 'Головка ударная удлиненная 6-гранная (24 мм; 1/2"DR) PT-IS-12-24', 6, 389.35, 2336.07, 513.93, 2850.00),
            ],
            'totals' => ['sum_no_vat' => 48368.85, 'vat_sum' => 10641.15, 'sum_with_vat' => 59010.00],
            'handwritten_notes' => [],
            'warnings' => [],
        ];
    }
}
