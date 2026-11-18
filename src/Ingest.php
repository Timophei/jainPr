<?php
declare(strict_types=1);

/** Приём файлов: PDF → страницы, картинки → нормализованный JPEG. */
final class Ingest
{
    /** @return array<int, array> новые страницы */
    public static function addFile(string $batchId, string $tmpPath, string $origName): array
    {
        $dir = Storage::batchDir($batchId);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $srcName = bin2hex(random_bytes(4)) . '-' . preg_replace('/[^\w.\-]+/u', '_', $origName);
        $srcPath = $dir . '/src/' . $srcName;
        if (!@move_uploaded_file($tmpPath, $srcPath)) {
            copy($tmpPath, $srcPath);
        }

        $images = []; // [path, srcPageNo]
        $cleanup = [];
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            $prefix = sys_get_temp_dir() . '/rspz-' . bin2hex(random_bytes(4));
            $cmd = 'pdftoppm -r 200 -jpeg -jpegopt quality=90 ' . escapeshellarg($srcPath) . ' ' . escapeshellarg($prefix) . ' 2>&1';
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new RuntimeException('Не удалось разобрать PDF (нужен пакет poppler-utils): ' . implode(' ', $out));
            }
            $files = glob($prefix . '-*.jpg') ?: [];
            natsort($files);
            $n = 0;
            foreach ($files as $f) {
                $images[] = [$f, ++$n];
                $cleanup[] = $f;
            }
        } elseif (in_array($mime, ['image/tiff', 'image/heic', 'image/heif'], true) || in_array($ext, ['tif', 'tiff', 'heic', 'heif'], true)) {
            $images = self::convertViaImageMagick($srcPath, $cleanup);
        } elseif (str_starts_with($mime, 'image/')) {
            $images[] = [$srcPath, 1];
        } else {
            throw new RuntimeException("Неподдерживаемый формат: $origName ($mime)");
        }

        $pages = [];
        foreach ($images as [$path, $pageNo]) {
            $pid = 'p' . bin2hex(random_bytes(4));
            $rel = 'pages/' . $pid . '.jpg';
            [$w, $h] = self::normalize($path, $dir . '/' . $rel);
            $pages[] = [
                'id' => $pid,
                'file' => $rel,
                'src' => $origName,
                'src_page' => $pageNo,
                'w' => $w,
                'h' => $h,
                'status' => 'new',
                'error' => null,
                'result' => null,
            ];
        }
        foreach ($cleanup as $f) {
            @unlink($f);
        }
        if (!$pages) {
            throw new RuntimeException("В файле $origName не найдено страниц");
        }
        return $pages;
    }

    private static function convertViaImageMagick(string $src, array &$cleanup): array
    {
        $prefix = sys_get_temp_dir() . '/rspz-' . bin2hex(random_bytes(4));
        if (class_exists('Imagick')) {
            $im = new Imagick($src);
            $res = [];
            $n = 0;
            foreach ($im as $frame) {
                $frame->setImageFormat('jpeg');
                $f = $prefix . '-' . (++$n) . '.jpg';
                $frame->writeImage($f);
                $res[] = [$f, $n];
                $cleanup[] = $f;
            }
            return $res;
        }
        $bin = trim((string)shell_exec('command -v magick || command -v convert'));
        if ($bin === '') {
            throw new RuntimeException('Для TIFF/HEIC нужен ImageMagick (apt install imagemagick)');
        }
        exec($bin . ' ' . escapeshellarg($src) . ' ' . escapeshellarg($prefix . '-%d.jpg') . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('Ошибка конвертации: ' . implode(' ', $out));
        }
        $files = glob($prefix . '-*.jpg') ?: [];
        natsort($files);
        $res = [];
        $n = 0;
        foreach ($files as $f) {
            $res[] = [$f, ++$n];
            $cleanup[] = $f;
        }
        return $res;
    }

    /** Поворот по EXIF, уменьшение до max_image_side, сохранение в JPEG. */
    public static function normalize(string $src, string $dst, int $rotateDeg = 0): array
    {
        $data = file_get_contents($src);
        $img = @imagecreatefromstring($data);
        if (!$img) {
            throw new RuntimeException('Не удалось прочитать изображение');
        }
        if (function_exists('exif_read_data') && str_starts_with($data, "\xFF\xD8")) {
            $exif = @exif_read_data($src);
            $o = (int)($exif['Orientation'] ?? 1);
            $map = [3 => 180, 6 => -90, 8 => 90];
            if (isset($map[$o])) {
                $img = imagerotate($img, $map[$o], 0xFFFFFF);
            }
        }
        if ($rotateDeg) {
            $img = imagerotate($img, -$rotateDeg, 0xFFFFFF); // по часовой
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $max = (int)cfg('max_image_side', 2400);
        if (max($w, $h) > $max) {
            $k = $max / max($w, $h);
            $nw = (int)round($w * $k);
            $nh = (int)round($h * $k);
            $dstImg = imagecreatetruecolor($nw, $nh);
            imagefill($dstImg, 0, 0, 0xFFFFFF);
            imagecopyresampled($dstImg, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $img = $dstImg;
            [$w, $h] = [$nw, $nh];
        } else {
            // убираем альфа-канал PNG на белый фон
            $flat = imagecreatetruecolor($w, $h);
            imagefill($flat, 0, 0, 0xFFFFFF);
            imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
            $img = $flat;
        }
        imagejpeg($img, $dst, 88);
        return [$w, $h];
    }
}
