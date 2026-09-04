<?php
/**
 * API: 图片列表
 *
 * GET /api/list.php
 *
 * 参数:
 *   page     (int) 页码，默认 1
 *   per_page (int) 每页数量，默认 20，最大 100
 *
 * 可见范围:
 *   - 未登录 + allow_public_list=true  —— 全部图片（公开图床浏览）
 *   - 未登录 + allow_public_list=false —— 401
 *   - 登录（多用户模式）普通用户      —— 仅自己的图片
 *   - 登录（多用户模式）管理员        —— 默认自己的图片，传 all=1 查看全部
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Api::requireMethod('GET');

/* ---- 可见范围 ---- */
$user = Auth::user();
$scopeUserId = null; // null = 全部

if ($user !== null) {
    $wantAll = request_int('all', 0, 0, 1) === 1;
    if (!($user['role'] === 'admin' && $wantAll)) {
        $scopeUserId = (int)$user['id'];
    }
} elseif (!config('allow_public_list', true)) {
    json_out(401, '请先登录后查看图片列表');
}

/* ---- 查询 ---- */
$where  = '';
$params = [];
if ($scopeUserId !== null) {
    $where    = ' WHERE user_id = ?';
    $params[] = $scopeUserId;
}

$result = paginate_query('FROM images', $where, $params, request_int('page', 1, 1), request_int('per_page', 20, 1, 100));

json_out(200, 'ok', [
    'total'    => $result['total'],
    'page'     => $result['page'],
    'pages'    => $result['pages'],
    'per_page' => $result['per_page'],
    'images'   => array_map(static fn(array $r): array => ImageManager::toApiRow($r), $result['rows']),
]);
