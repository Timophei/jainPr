<?php
declare(strict_types=1);

/** Хранение пачек в data/batches/<id>/batch.json + картинки страниц. */
final class Storage
{
    public static function batchDir(string $id): string
    {
        if (!preg_match('/^[a-z0-9\-]{6,40}$/', $id)) {
            throw new RuntimeException('Некорректный id пачки');
        }
        return data_dir() . '/batches/' . $id;
    }

    public static function newBatch(string $name = ''): array
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $dir = self::batchDir($id);
        mkdir($dir . '/pages', 0775, true);
        mkdir($dir . '/src', 0775, true);
        $b = [
            'id' => $id,
            'name' => $name !== '' ? $name : 'Пачка от ' . date('d.m.Y H:i'),
            'created' => date('c'),
            'pages' => [],
            'documents' => [],
        ];
        self::writeJson($dir . '/batch.json', $b);
        return $b;
    }

    public static function load(string $id): array
    {
        $f = self::batchDir($id) . '/batch.json';
        if (!is_file($f)) {
            throw new RuntimeException('Пачка не найдена');
        }
        return self::readJson($f, []);
    }

    /** Атомарное изменение пачки под файловой блокировкой. */
    public static function update(string $id, callable $fn): array
    {
        $dir = self::batchDir($id);
        $lock = fopen($dir . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $b = self::readJson($dir . '/batch.json', []);
            $res = $fn($b);
            if (is_array($res)) {
                $b = $res;
            }
            self::writeJson($dir . '/batch.json', $b);
            return $b;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function delete(string $id): void
    {
        $dir = self::batchDir($id);
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public static function listBatches(int $limit = 60): array
    {
        $out = [];
        foreach (glob(data_dir() . '/batches/*/batch.json') ?: [] as $f) {
            $b = self::readJson($f, []);
            if (!$b) {
                continue;
            }
            $out[] = [
                'id' => $b['id'],
                'name' => $b['name'],
                'created' => $b['created'],
                'pages' => count($b['pages'] ?? []),
                'documents' => count($b['documents'] ?? []),
                'checked' => count(array_filter($b['documents'] ?? [], static fn($d) => !empty($d['checked']))),
            ];
        }
        usort($out, static fn($a, $b) => strcmp($b['created'], $a['created']));
        return array_slice($out, 0, $limit);
    }

    public static function readJson(string $path, $default)
    {
        if (!is_file($path)) {
            return $default;
        }
        $data = json_decode((string)file_get_contents($path), true);
        return $data ?? $default;
    }

    public static function writeJson(string $path, $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmp, $path);
    }
}
