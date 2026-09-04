<?php
/**
 * PonyImage 单图预览页
 *
 * URL: /view.php?id=123
 *
 * 展示：原图、缩略图链接、尺寸、大小、上传时间、复制链接。
 * 私有模式（allow_public_list=false）下仅所有者或管理员可访问。
 * 安全说明：删除密钥按设计仅在上传响应中返回一次，本页不再展示。
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

/** 渲染错误页（本文件多个分支复用） */
function render_view_error(int $status, string $title, string $message): void
{
    http_response_code($status);
    // 写入超全局数组，确保 render_page() 的 extract($GLOBALS) 能带到视图
    $GLOBALS['error'] = $message;
    render_page('view_error.php', 'view', $title);
}

$id = filter_var((string)($_GET['id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    render_view_error(400, '参数错误', '缺少有效的图片 ID');
}

$stmt = db()->prepare('SELECT * FROM images WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$image = $stmt->fetch();

$currentUser = Auth::user();

if (!$image) {
    render_view_error(404, '图片不存在', '图片不存在或已被删除');
}

// 私有模式访问控制
if (!config('allow_public_list', true)) {
    $isOwner = $currentUser !== null && (int)$currentUser['id'] === (int)$image['user_id'];
    $isAdmin = $currentUser !== null && $currentUser['role'] === 'admin';
    if (!$isOwner && !$isAdmin) {
        render_view_error(401, '无权访问', config('multi_user', false) ? '该图床为私有模式，请先登录' : '该图片不存在或已被删除');
    }
}

$imageUrl   = asset_url((string)$image['file_path']);
$thumbUrl   = ($image['thumb_path'] ?? '') !== '' ? asset_url((string)$image['thumb_path']) : $imageUrl;
$markdown   = '![image](' . $imageUrl . ')';
// 注意：此处的 $htmlCode 输出时统一经 e() 转义，因此这里不做预转义，避免双重转义
$htmlCode   = '<img src="' . $imageUrl . '" alt="image">';
$bbcode     = '[img]' . $imageUrl . '[/img]';
$ownerName  = '';
if ((int)$image['user_id'] > 0) {
    $u = db()->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
    $u->execute([(int)$image['user_id']]);
    $ownerName = (string)($u->fetchColumn() ?: '');
}

render_page('view_detail.php', 'view', '图片 #' . (int)$image['id']);
