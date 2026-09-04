<?php
/**
 * API: 上传图片
 *
 * POST /api/upload.php
 * Content-Type: multipart/form-data
 *
 * 参数:
 *   file  (file)   必填，图片文件
 *   token (string) 可选，config.api_tokens 中配置的固定 Token（外部程序调用用）
 *                  也可通过请求头 Authorization: Bearer <token> 传递
 *
 * 认证优先级:
 *   1. API Token          —— 跳过 CSRF（非浏览器场景）
 *   2. 已登录 Session     —— 必须携带 X-CSRF-Token
 *   3. allow_public_upload —— 允许匿名上传（单用户模式）
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Api::requireMethod('POST');

/* ---- 超过 php.ini post_max_size 的场景 ----
 * PHP 在请求体超过 post_max_size 时会静默丢弃全部 POST 数据与文件，
 * 此时（有请求体但 $_POST/$_FILES 全空）给出明确提示而不是误导性的认证错误 */
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    json_out(400, '请求体超过服务器 post_max_size 限制，请压缩图片或调整服务器配置');
}

$viaToken = Auth::tokenAuth();

/* ---- 认证与授权 ---- */
if ($viaToken) {
    // Token 认证通过
} elseif (Auth::check()) {
    Api::requireCsrf();
} elseif (!config('allow_public_upload', false)) {
    json_out(401, '请先登录后再上传');
}

/* ---- 上传限流 ---- */
Api::rateLimit('upload', 60, (int)config('upload_rate_limit', 30));

/* ---- 执行上传 ---- */
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_out(400, '请选择要上传的文件');
}

$userId = Auth::check() ? (int)$_SESSION['user_id'] : 0;
$result = ImageManager::handle($_FILES['file'], $userId);

if (!$result['ok']) {
    json_out(400, $result['msg']);
}

json_out(200, '上传成功', $result['data']);
