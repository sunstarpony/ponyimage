<?php
/**
 * API: 管理接口（仅管理员）
 *
 * 所有操作均要求: 1) 已登录且 role=admin  2) POST 请求携带 X-CSRF-Token
 *
 * GET  ?action=stats            站点统计
 * GET  ?action=images&page=&per_page=&user_id=   全部图片列表
 * GET  ?action=users            用户列表
 * POST ?action=create_user      创建用户  { username, password, email?, role? }
 * POST ?action=delete_user      删除用户  { id }（仅删除用户记录，图片保留、user_id 置 0）
 * POST ?action=reset_password   重置密码  { id, password }
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin  = Api::requireAdmin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = request_string('action', 20, 'stats');

/* ---- 写操作统一要求 POST + CSRF ---- */
if ($method === 'POST') {
    Api::requireMethod('POST');
    Api::requireCsrf();
}

switch ($action) {

    case 'stats':
        $pdo = db();
        json_out(200, 'ok', [
            'images'     => (int)$pdo->query('SELECT COUNT(*) FROM images')->fetchColumn(),
            'total_size' => (int)$pdo->query('SELECT COALESCE(SUM(file_size), 0) FROM images')->fetchColumn(),
            'users'      => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        ]);

    case 'images':
        $userId = request_int('user_id', 0, 0); // 0 = 全部

        $where  = '';
        $params = [];
        if ($userId > 0) {
            $where    = ' WHERE i.user_id = ?';
            $params[] = $userId;
        }

        $result = paginate_query(
            'FROM images i LEFT JOIN users u ON u.id = i.user_id',
            $where,
            $params,
            request_int('page', 1, 1),
            request_int('per_page', 20, 1, 100),
            'i.*, u.username',
            'i.id DESC'
        );

        $images = array_map(static function (array $r): array {
            $out = ImageManager::toApiRow($r);
            $out['user_id']  = (int)$r['user_id'];
            $out['username'] = (string)($r['username'] ?? '');
            return $out;
        }, $result['rows']);

        json_out(200, 'ok', [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'pages'    => $result['pages'],
            'per_page' => $result['per_page'],
            'images'   => $images,
        ]);

    case 'users':
        $stmt = db()->query('SELECT id, username, email, role, created_at FROM users ORDER BY id ASC');
        json_out(200, 'ok', ['users' => $stmt->fetchAll()]);

    case 'create_user':
        $username = request_string('username', 50);
        $password = (string)(request_body_input('password', ''));
        $email    = request_string('email', 100);
        $role     = request_string('role', 20, 'user');

        if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $username)) {
            json_out(400, '用户名只能包含字母、数字、下划线和连字符，长度 3-50');
        }
        if (strlen($password) < 8) {
            json_out(400, '密码长度至少 8 位');
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            json_out(400, '角色参数非法');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(400, '邮箱格式不正确');
        }

        $exists = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $exists->execute([$username]);
        if ($exists->fetch()) {
            json_out(400, '用户名已存在');
        }

        $stmt = db()->prepare('INSERT INTO users (username, password, email, role, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email, $role, date('Y-m-d H:i:s')]);

        log_write('admin', "创建用户 username={$username} role={$role} by={$admin['username']}");
        json_out(200, '用户创建成功', ['id' => (int)db()->lastInsertId()]);

    case 'delete_user':
        $targetId = request_int('id', 0, 1);
        if ($targetId === (int)$admin['id']) {
            json_out(400, '不能删除自己');
        }

        $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        if ($stmt->rowCount() === 0) {
            json_out(400, '用户不存在');
        }

        // 该用户的图片归属清零，保留文件（避免误删他人数据）
        db()->prepare('UPDATE images SET user_id = 0 WHERE user_id = ?')->execute([$targetId]);

        log_write('admin', "删除用户 id={$targetId} by={$admin['username']}");
        json_out(200, '用户已删除');

    case 'reset_password':
        $targetId = request_int('id', 0, 1);
        $password = (string)(request_body_input('password', ''));
        if (strlen($password) < 8) {
            json_out(400, '密码长度至少 8 位');
        }

        $stmt = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
        if ($stmt->rowCount() === 0) {
            json_out(400, '用户不存在');
        }

        log_write('admin', "重置密码 id={$targetId} by={$admin['username']}");
        json_out(200, '密码已重置');

    default:
        json_out(400, '未知的 action 参数');
}
