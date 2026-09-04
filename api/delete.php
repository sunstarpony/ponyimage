<?php
/**
 * API: 删除图片
 *
 * POST /api/delete.php  (JSON 或 form)
 *
 * 参数:
 *   id         (int)    必填，图片 ID
 *   delete_key (string) 未登录时必填，上传成功时返回的删除密钥
 *
 * 授权规则:
 *   1. 管理员                —— 可删除任意图片（需 CSRF）
 *   2. 登录用户且为图片所有者 —— 可直接删除（需 CSRF）
 *   3. 其余情况               —— delete_key 必须与库中记录恒等匹配
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Api::requireMethod('POST');

$id  = request_int('id', 0, 1);
$key = mb_substr((string)(request_body_input('delete_key', '')), 0, 64, 'UTF-8');

$row = Api::requireImage($id);

/* ---- 授权判断 ---- */
$user      = Auth::user();
$isOwner   = $user !== null && (int)$user['id'] === (int)$row['user_id'];
$isAdmin   = $user !== null && $user['role'] === 'admin';

if ($isOwner || $isAdmin) {
    // 会话内删除必须通过 CSRF 校验
    Api::requireCsrf();
} else {
    // 未授权会话：删除密钥恒等比较（防时序攻击）
    if ($key === '' || !hash_equals((string)$row['delete_key'], $key)) {
        log_write('security', "删除密钥不匹配 id={$id}");
        json_out(403, '删除密钥不正确');
    }
}

/* ---- 执行删除 ---- */
if (!ImageManager::deleteRecord($row)) {
    json_out(500, '文件删除失败，请联系管理员');
}

json_out(200, '删除成功', ['id' => $id]);
