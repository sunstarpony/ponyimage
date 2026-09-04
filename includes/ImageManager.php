<?php
/**
 * PonyImage 图片管理核心
 *
 * 职责：上传校验（多重防伪造）、按年/月分目录存储、安全删除、API 行转换。
 * 缩略图生成见 includes/Thumbnail.php（GD 处理独立成类）。
 */

declare(strict_types=1);

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ImageManager
{
    /** MIME → 安全扩展名映射（同时用于交叉校验，防止改后缀伪装） */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * 处理一次上传
     *
     * @param array      $file    $_FILES['file'] 单元
     * @param int|null   $userId  归属用户 ID，游客为 0
     * @return array{ok:bool,msg:string,data?:array}
     */
    public static function handle(array $file, int $userId = 0): array
    {
        // ---- 1. 基础检查 ----
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'msg' => '参数错误：未收到有效文件字段'];
        }

        $errorCode = (int)$file['error'];
        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'msg' => self::uploadErrorMessage($errorCode)];
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['ok' => false, 'msg' => '非法的上传来源'];
        }

        $maxSize = (int)config('max_file_size', 10485760);
        $size    = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'msg' => '文件为空'];
        }
        if ($size > $maxSize) {
            return ['ok' => false, 'msg' => '文件过大，最大允许 ' . format_bytes($maxSize)];
        }

        // ---- 2. 扩展名白名单 ----
        $originalName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = (array)config('allowed_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (!in_array($ext, $allowed, true)) {
            return ['ok' => false, 'msg' => '不支持的文件类型，仅允许：' . implode(' / ', $allowed)];
        }

        // ---- 3. 真实 MIME 校验（finfo 内容检测，不信任客户端声明的 type）----
        if (!class_exists('finfo')) {
            log_write('upload', '服务器缺少 fileinfo 扩展，无法校验文件真实类型');
            return ['ok' => false, 'msg' => '服务器未启用 fileinfo 扩展，请联系管理员'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($tmpName);
        if (!isset(self::MIME_EXT[$mime])) {
            log_write('upload', "拒绝可疑文件 mime={$mime} name={$originalName}");
            return ['ok' => false, 'msg' => '文件内容不是有效的图片'];
        }

        // 扩展名与真实 MIME 交叉校验（jpg/jpeg 视为同一类型）
        $extByMime = self::MIME_EXT[$mime];
        $extNorm   = ($ext === 'jpeg') ? 'jpg' : $ext;
        if ($extNorm !== $extByMime) {
            log_write('upload', "扩展名与内容不符 mime={$mime} ext={$ext} name={$originalName}");
            return ['ok' => false, 'msg' => '文件扩展名与实际内容不一致'];
        }

        // ---- 4. 图像结构校验 + 读取真实宽高 ----
        $info = @getimagesize($tmpName);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return ['ok' => false, 'msg' => '无法解析的图片数据'];
        }
        $width  = (int)$info[0];
        $height = (int)$info[1];

        // ---- 5. 生成随机保存名并创建年/月目录 ----
        $subDir     = date('Y') . '/' . date('m');
        $storageDir = rtrim((string)config('storage_path', 'uploads/'), '/');
        $fullDir    = ROOT_PATH . '/' . $storageDir . '/' . $subDir;
        if (!is_dir($fullDir) && !@mkdir($fullDir, 0755, true)) {
            log_write('upload', '目录创建失败: ' . $fullDir);
            return ['ok' => false, 'msg' => '服务器存储目录不可写'];
        }

        $savedName = date('YmdHis') . '_' . random_key(12) . '.' . $extByMime;
        $relPath   = $storageDir . '/' . $subDir . '/' . $savedName;
        $absPath   = $fullDir . '/' . $savedName;

        if (!@move_uploaded_file($tmpName, $absPath)) {
            return ['ok' => false, 'msg' => '文件保存失败'];
        }
        @chmod($absPath, 0644);

        // ---- 6. 生成缩略图（失败不影响原图保存，降级用原图）----
        $thumbRel = '';
        if (config('enable_thumb', true) && Thumbnail::available()) {
            $thumbRel = Thumbnail::create($absPath, $mime) ?? '';
        }

        // ---- 7. 写入数据库 ----
        $deleteKey = random_key(32);

        try {
            $stmt = db()->prepare(
                'INSERT INTO images
                    (filename, saved_name, file_path, thumb_path, file_size, mime_type,
                     file_width, file_height, delete_key, user_id, upload_time, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                mb_substr($originalName, 0, 255, 'UTF-8'),
                $savedName,
                $relPath,
                $thumbRel,
                $size,
                $mime,
                $width,
                $height,
                $deleteKey,
                $userId,
                date('Y-m-d H:i:s'),
                client_ip(),
                user_agent(),
            ]);
            $id = (int)db()->lastInsertId();
        } catch (Throwable $e) {
            // 数据库失败时回滚已落盘文件，避免产生孤儿文件
            @unlink($absPath);
            if ($thumbRel !== '') {
                @unlink(ROOT_PATH . '/' . $thumbRel);
            }
            log_write('upload', '数据库写入失败: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '保存记录失败'];
        }

        log_write('upload', "上传成功 id={$id} file={$relPath} size={$size} mime={$mime}");

        return [
            'ok'   => true,
            'msg'  => '上传成功',
            'data' => [
                'id'         => $id,
                'url'        => asset_url($relPath),
                'thumb_url'  => $thumbRel !== '' ? asset_url($thumbRel) : asset_url($relPath),
                'delete_key' => $deleteKey,
                'filename'   => $originalName,
                'size'       => $size,
                'width'      => $width,
                'height'     => $height,
                'upload_time'=> date('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * 删除图片（原图 + 缩略图 + 数据库记录）
     *
     * 删除授权由调用方（api/delete.php）先校验，本方法只负责安全删除。
     */
    public static function deleteRecord(array $row): bool
    {
        foreach ([$row['file_path'] ?? '', $row['thumb_path'] ?? ''] as $rel) {
            if (!self::safeUnlink((string)$rel)) {
                return false;
            }
        }

        $stmt = db()->prepare('DELETE FROM images WHERE id = ?');
        $stmt->execute([(int)$row['id']]);

        log_write('delete', "删除图片 id={$row['id']} file=" . ($row['file_path'] ?? ''));
        return true;
    }

    /**
     * 防目录穿越的文件删除：解析 realpath 后必须位于站点根目录内
     */
    private static function safeUnlink(string $relPath): bool
    {
        if ($relPath === '') {
            return true; // 缩略图可能为空，视为成功
        }

        $root = realpath(ROOT_PATH);
        $abs  = realpath(ROOT_PATH . '/' . ltrim($relPath, '/'));

        if ($abs === false || $root === false) {
            return true; // 文件本就不存在，继续删记录
        }

        // 必须位于 uploads/ 或 thumbs/ 目录之内，且未逃逸站点根目录
        $normalized = str_replace('\\', '/', $abs);
        $rootNorm   = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $storage    = rtrim((string)config('storage_path', 'uploads/'), '/') . '/';
        $thumb      = rtrim((string)config('thumb_path', 'thumbs/'), '/') . '/';

        if (!str_starts_with($normalized, $rootNorm)) {
            log_write('security', '删除操作路径越界被拦截: ' . $relPath);
            return false;
        }
        $inside = str_starts_with($normalized, $rootNorm . $storage) || str_starts_with($normalized, $rootNorm . $thumb);
        if (!$inside) {
            log_write('security', '删除操作目标不在存储目录内被拦截: ' . $relPath);
            return false;
        }

        if (is_file($abs)) {
            return @unlink($abs);
        }
        return true;
    }

    /** 把上传错误码转成可读信息（不暴露服务器路径等敏感细节） */
    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件过大，超出服务器限制',
            UPLOAD_ERR_PARTIAL   => '文件只被部分上传，请重试',
            UPLOAD_ERR_NO_FILE   => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR=> '服务器临时目录缺失',
            UPLOAD_ERR_CANT_WRITE=> '服务器磁盘写入失败',
            UPLOAD_ERR_EXTENSION => '上传被服务器扩展中断',
            default              => '上传失败（错误码 ' . $code . '）',
        };
    }

    /**
     * 将数据库行转换为对外 JSON 结构（URL 转绝对地址，不暴露 delete_key / ip）
     */
    public static function toApiRow(array $row): array
    {
        return [
            'id'          => (int)$row['id'],
            'url'         => asset_url((string)$row['file_path']),
            'thumb_url'   => ($row['thumb_path'] ?? '') !== '' ? asset_url((string)$row['thumb_path']) : asset_url((string)$row['file_path']),
            'filename'    => (string)$row['filename'],
            'size'        => (int)$row['file_size'],
            'width'       => (int)($row['file_width'] ?? 0),
            'height'      => (int)($row['file_height'] ?? 0),
            'upload_time' => (string)$row['upload_time'],
        ];
    }
}
