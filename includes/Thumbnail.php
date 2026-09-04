<?php
/**
 * PonyImage 缩略图生成器（GD）
 *
 * 从 ImageManager 拆分出的单一职责类：等比缩放、不放大、保留透明通道。
 * 生成失败不影响原图保存（调用方收到 null 自行降级为原图）。
 */

declare(strict_types=1);

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Thumbnail
{
    /** GD 是否可用（缺扩展时上传流程自动跳过缩略图） */
    public static function available(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /**
     * 为指定原图生成缩略图
     *
     * @param string $absPath 原图绝对路径
     * @param string $mime    原图 MIME（决定解码与编码方式）
     * @return string|null 成功返回相对站点根的路径，失败返回 null
     */
    public static function create(string $absPath, string $mime): ?string
    {
        try {
            $data = file_get_contents($absPath);
            if ($data === false) {
                return null;
            }
            $src = @imagecreatefromstring($data);
            if (!$src) {
                return null;
            }

            [$tw, $th] = self::scaledSize(imagesx($src), imagesy($src));

            $dst = imagecreatetruecolor($tw, $th);

            // PNG / GIF / WebP 保留透明通道
            if ($mime !== 'image/jpeg') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefilledrectangle($dst, 0, 0, $tw, $th, $transparent);
            }

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, imagesx($src), imagesy($src));

            $thumbRel = self::targetPath($absPath);
            $thumbAbs = ROOT_PATH . '/' . $thumbRel;

            $thumbDir = dirname($thumbAbs);
            if (!is_dir($thumbDir) && !@mkdir($thumbDir, 0755, true)) {
                imagedestroy($src);
                imagedestroy($dst);
                return null;
            }

            $ok = self::encode($dst, $thumbAbs, $mime);

            imagedestroy($src);
            imagedestroy($dst);

            if (!$ok) {
                return null;
            }
            @chmod($thumbAbs, 0644);
            return $thumbRel;
        } catch (Throwable $e) {
            log_write('thumb', '缩略图生成失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 计算等比缩放尺寸（不放大）
     *
     * @return array{0:int,1:int} [宽, 高]
     */
    private static function scaledSize(int $sw, int $sh): array
    {
        $maxW  = (int)config('thumb_width', 320);
        $maxH  = (int)config('thumb_height', 320);
        $ratio = min($maxW / $sw, $maxH / $sh, 1.0);
        return [
            max(1, (int)round($sw * $ratio)),
            max(1, (int)round($sh * $ratio)),
        ];
    }

    /**
     * 由原图绝对路径推导缩略图相对路径（保持 Y/m 子目录结构）
     */
    private static function targetPath(string $absPath): string
    {
        $rel        = str_replace('\\', '/', $absPath);
        $relSrc     = ltrim(substr($rel, strlen(ROOT_PATH) + 1), '/');
        $thumbDir   = rtrim((string)config('thumb_path', 'thumbs/'), '/');
        $storageDir = rtrim((string)config('storage_path', 'uploads/'), '/');

        $subPath = str_starts_with($relSrc, $storageDir . '/')
            ? substr($relSrc, strlen($storageDir) + 1)
            : basename($relSrc);

        return $thumbDir . '/' . $subPath;
    }

    /** 按原格式编码写入磁盘 */
    private static function encode(GdImage $dst, string $thumbAbs, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($dst, $thumbAbs, 82),
            'image/png'  => imagepng($dst, $thumbAbs, 6),
            'image/gif'  => imagegif($dst, $thumbAbs),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $thumbAbs, 82) : false,
            default      => false,
        };
    }
}
