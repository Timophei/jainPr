<?php
declare(strict_types=1);

/**
 * Справочник номенклатуры 1С (загружается из CSV/XLSX, выгруженного из 1С)
 * и сопоставление строк поставщика с ним:
 *   1) запомненное соответствие (ИНН поставщика + код/наименование) — то, что пользователь уже выбирал;
 *   2) по артикулу;
 *   3) нечётко по наименованию (подсказка, требует подтверждения).
 */
final class Catalog
{
    private static ?array $items = null;
    private static ?array $artIdx = null;
    private static ?array $tokIdx = null;
    private static ?array $tokCache = null;
    private static ?array $mappings = null;

    public static function path(): string
    {
        return data_dir() . '/catalog.json';
    }

    public static function mapPath(): string
    {
        return data_dir() . '/mappings.json';
    }

    public static function items(): array
    {
        if (self::$items === null) {
            self::$items = Storage::readJson(self::path(), ['items' => []])['items'] ?? [];
        }
        return self::$items;
    }

    public static function info(): array
    {
        $d = Storage::readJson(self::path(), null);
        return [
            'count' => $d ? count($d['items']) : 0,
            'updated' => $d['updated'] ?? null,
            'source' => $d['source'] ?? null,
            'mappings' => count(self::mappings()),
        ];
    }

    /** Импорт: ищем колонки «Артикул», «Наименование/Номенклатура», «Код», «Ед.». */
    public static function import(string $file, string $origName): int
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $rows = $ext === 'xlsx' ? Xlsx::readFirstSheet($file) : self::readCsv($file);
        if (!$rows) {
            throw new RuntimeException('Файл пустой');
        }
        $hdrRow = -1;
        $cols = ['name' => null, 'article' => null, 'code' => null, 'unit' => null];
        foreach (array_slice($rows, 0, 15, true) as $ri => $r) {
            $found = ['name' => null, 'article' => null, 'code' => null, 'unit' => null];
            foreach ($r as $ci => $v) {
                $v = mb_strtolower(trim((string)$v));
                if ($v === '') {
                    continue;
                }
                if ($found['article'] === null && str_contains($v, 'артикул')) {
                    $found['article'] = $ci;
                } elseif ($found['name'] === null && (str_contains($v, 'наименование') || $v === 'номенклатура' || $v === 'товар')) {
                    $found['name'] = $ci;
                } elseif ($found['code'] === null && ($v === 'код' || str_starts_with($v, 'код '))) {
                    $found['code'] = $ci;
                } elseif ($found['unit'] === null && (str_starts_with($v, 'ед') || str_contains($v, 'единица'))) {
                    $found['unit'] = $ci;
                }
            }
            if ($found['name'] !== null) {
                $hdrRow = $ri;
                $cols = $found;
                break;
            }
        }
        if ($hdrRow < 0) {
            $cols['name'] = 0; // без заголовка: первая колонка — наименование
        }
        $items = [];
        $seen = [];
        foreach ($rows as $ri => $r) {
            if ($ri <= $hdrRow) {
                continue;
            }
            $name = trim((string)($r[$cols['name']] ?? ''));
            if ($name === '') {
                continue;
            }
            $it = [
                'name' => $name,
                'article' => $cols['article'] !== null ? trim((string)($r[$cols['article']] ?? '')) : '',
                'code' => $cols['code'] !== null ? trim((string)($r[$cols['code']] ?? '')) : '',
                'unit' => $cols['unit'] !== null ? trim((string)($r[$cols['unit']] ?? '')) : '',
            ];
            $k = $it['code'] . '|' . $it['name'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = 1;
            $items[] = $it;
        }
        if (!$items) {
            throw new RuntimeException('Не нашёл ни одной позиции. Нужна колонка «Наименование».');
        }
        Storage::writeJson(self::path(), ['updated' => date('c'), 'source' => $origName, 'items' => $items]);
        self::$items = $items;
        self::$artIdx = self::$tokIdx = self::$tokCache = null;
        return count($items);
    }

    private static function readCsv(string $file): array
    {
        $raw = (string)file_get_contents($file);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $first = strtok($raw, "\n") ?: '';
        $delims = [';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), ',' => substr_count($first, ',')];
        arsort($delims);
        $d = (string)array_key_first($delims);
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $d, '"', '')) !== false) {
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }

    // ---------- нормализация ----------

    public static function normArt(?string $s): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper((string)$s)) ?? '';
    }

    public static function normName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('/(\d),(\d)/u', '$1.$2', $s);
        $s = preg_replace('/(\p{N})(\p{L})/u', '$1 $2', $s);
        $s = preg_replace('/(\p{L})(\p{N})/u', '$1 $2', $s);
        $s = preg_replace('/[^\p{L}\p{N}\/\.]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function tokens(string $s): array
    {
        $t = [];
        foreach (explode(' ', self::normName($s)) as $w) {
            $w = trim($w, '.');
            if ($w === '' || (mb_strlen($w) < 2 && !ctype_digit($w))) {
                continue;
            }
            $t[$w] = true;
        }
        return array_map('strval', array_keys($t));
    }

    private static function buildIndex(): void
    {
        if (self::$artIdx !== null) {
            return;
        }
        self::$artIdx = [];
        self::$tokIdx = [];
        self::$tokCache = [];
        foreach (self::items() as $i => $it) {
            $a = self::normArt($it['article'] ?? '');
            if (mb_strlen($a) >= 3) {
                self::$artIdx[$a][] = $i;
            }
            $toks = self::tokens($it['name']);
            self::$tokCache[$i] = $toks;
            foreach ($toks as $tk) {
                self::$tokIdx[$tk][] = $i;
            }
        }
    }

    // ---------- запомненные соответствия ----------

    public static function mappings(): array
    {
        if (self::$mappings === null) {
            self::$mappings = Storage::readJson(self::mapPath(), []);
        }
        return self::$mappings;
    }

    public static function mapKeys(array $line, ?string $inn): array
    {
        $inn = preg_replace('/\D/', '', (string)$inn) ?: '-';
        $keys = [];
        if (!empty($line['code'])) {
            $keys[] = $inn . '|c:' . self::normArt($line['code']);
        }
        if (!empty($line['name'])) {
            $keys[] = $inn . '|n:' . self::normName($line['name']);
        }
        return $keys;
    }

    public static function remember(array $line, ?string $inn, array $match): void
    {
        $m = self::mappings();
        foreach (self::mapKeys($line, $inn) as $k) {
            $m[$k] = ['code' => $match['code'] ?? '', 'name' => $match['name'] ?? '', 'article' => $match['article'] ?? ''];
        }
        self::$mappings = $m;
        Storage::writeJson(self::mapPath(), $m);
    }

    // ---------- сопоставление ----------

    /** @return array|null {code,name,article,how: mapping|article|fuzzy, score} */
    public static function match(array $line, ?string $inn): ?array
    {
        $maps = self::mappings();
        foreach (self::mapKeys($line, $inn) as $k) {
            if (isset($maps[$k])) {
                return $maps[$k] + ['how' => 'mapping', 'score' => 1];
            }
        }
        $items = self::items();
        if (!$items) {
            return null;
        }
        self::buildIndex();

        // по артикулу: колонка кода или артикулоподобные куски из наименования
        $cands = [];
        if (!empty($line['code'])) {
            $cands[] = self::normArt($line['code']);
        }
        if (preg_match_all('/[\p{L}\p{N}]+(?:[\-\.\/][\p{L}\p{N}]+)+|(?=[\p{L}\-]*\d)(?=[\p{N}\-]*\p{L})[\p{L}\p{N}\-]{5,}/u', (string)$line['name'], $mm)) {
            foreach ($mm[0] as $c) {
                $cands[] = self::normArt($c);
            }
        }
        foreach (array_unique($cands) as $a) {
            if (mb_strlen($a) >= 4 && isset(self::$artIdx[$a])) {
                $it = $items[self::$artIdx[$a][0]];
                return ['code' => $it['code'], 'name' => $it['name'], 'article' => $it['article'], 'how' => 'article', 'score' => 1];
            }
        }

        // нечётко по наименованию
        $toks = self::tokens((string)$line['name']);
        if (!$toks) {
            return null;
        }
        $hits = [];
        foreach ($toks as $tk) {
            $post = self::$tokIdx[$tk] ?? [];
            if (count($post) > 3000) {
                continue;
            }
            foreach ($post as $i) {
                $hits[$i] = ($hits[$i] ?? 0) + 1;
            }
        }
        if (!$hits) {
            return null;
        }
        arsort($hits);
        $best = null;
        $bestScore = 0.0;
        $numsA = array_filter($toks, static fn($t) => (bool)preg_match('/\d/', $t));
        foreach (array_slice($hits, 0, 40, true) as $i => $common) {
            $tb = self::$tokCache[$i];
            $score = $common / (count($toks) + count($tb) - $common);
            $numsB = array_filter($tb, static fn($t) => (bool)preg_match('/\d/', $t));
            if ($numsA && array_diff($numsB, $numsA)) {
                $score *= 0.5; // размеры/числа не совпали — сильно штрафуем
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }
        if ($best === null || $bestScore < 0.34) {
            return null;
        }
        $it = $items[$best];
        return ['code' => $it['code'], 'name' => $it['name'], 'article' => $it['article'], 'how' => 'fuzzy', 'score' => round($bestScore, 2)];
    }

    /** Поиск для автодополнения. */
    public static function search(string $q, int $limit = 30): array
    {
        $q = trim($q);
        $items = self::items();
        if ($q === '' || !$items) {
            return [];
        }
        $words = array_filter(explode(' ', self::normName($q)));
        $qa = self::normArt($q);
        $out = [];
        foreach ($items as $it) {
            $hay = self::normName($it['name'] . ' ' . $it['article'] . ' ' . $it['code']);
            $ok = true;
            foreach ($words as $w) {
                if (!str_contains($hay, $w)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok && $qa !== '' && mb_strlen($qa) >= 3 && str_contains(self::normArt($it['article']), $qa)) {
                $ok = true;
            }
            if ($ok) {
                $out[] = $it;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }
}
