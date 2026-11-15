<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Foto siswa: disimpan di storage/photos (di luar public), diperkecil dengan GD agar ringan
 * untuk kartu dan roster perangkat (PRD: ≤ 10 KB per foto kecil).
 */
final class Photo
{
    public const MAX_MB = 4;
    private const MAX_SIDE = 480;   // untuk kartu/cetak
    private const THUMB_SIDE = 96;  // untuk roster scanner

    public static function dir(): string
    {
        $dir = STORAGE_PATH . '/photos';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function path(int $studentId, bool $thumb = false): string
    {
        return self::dir() . '/' . $studentId . ($thumb ? '.thumb' : '') . '.jpg';
    }

    public static function exists(int $studentId): bool
    {
        return is_file(self::path($studentId));
    }

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /**
     * Simpan unggahan dari $_FILES. Mengembalikan null bila sukses, atau pesan kesalahan.
     * @param array{tmp_name:string,error:int,size:int} $file
     */
    public static function store(int $studentId, array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return t('Unggahan foto gagal (kode :c).', ['c' => $file['error'] ?? -1]);
        }
        if ($file['size'] > self::MAX_MB * 1024 * 1024) {
            return t('Foto maksimal :mb MB.', ['mb' => self::MAX_MB]);
        }
        if (!self::available()) {
            return t('Ekstensi GD tidak tersedia di server; foto tidak bisa diproses.');
        }
        $data = (string) file_get_contents($file['tmp_name']);
        $info = @getimagesizefromstring($data);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return t('Format foto harus JPG, PNG, atau WebP.');
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return t('Foto tidak dapat dibaca.');
        }
        // Hormati orientasi EXIF pada foto dari HP
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file['tmp_name']);
            $o = (int) ($exif['Orientation'] ?? 1);
            if ($o === 3) {
                $src = imagerotate($src, 180, 0);
            } elseif ($o === 6) {
                $src = imagerotate($src, -90, 0);
            } elseif ($o === 8) {
                $src = imagerotate($src, 90, 0);
            }
        }
        self::writeSquare($src, self::path($studentId), self::MAX_SIDE, 82);
        self::writeSquare($src, self::path($studentId, true), self::THUMB_SIDE, 70);
        imagedestroy($src);
        return null;
    }

    /** Potong ke rasio 3:4 (pas foto) dari tengah, lalu perkecil. */
    private static function writeSquare(\GdImage $src, string $dest, int $maxH, int $quality): void
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $ratio = 3 / 4;
        if ($w / $h > $ratio) {
            $cw = (int) round($h * $ratio);
            $ch = $h;
        } else {
            $cw = $w;
            $ch = (int) round($w / $ratio);
        }
        $cx = (int) (($w - $cw) / 2);
        $cy = (int) (($h - $ch) / 2);
        $th = min($maxH, $ch);
        $tw = (int) round($th * $ratio);
        $dst = imagecreatetruecolor($tw, $th);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
        imagejpeg($dst, $dest, $quality);
        imagedestroy($dst);
    }

    public static function delete(int $studentId): void
    {
        @unlink(self::path($studentId));
        @unlink(self::path($studentId, true));
    }
}
