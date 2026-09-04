<?php
/**
 * API: 认证接口（多用户模式）
 *
 * POST /api/auth.php?action=login     登录（需 CSRF）
 * POST /api/auth.php?action=logout    退出（需 CSRF）
 * POST /api/auth.php?action=register  自助注册（需 CSRF + allow_register 开启）
 * GET  /api/auth.php?action=me        当前登录用户信息
 *
 * 登录内置防护:
 *   - 每个 IP 每 15 分钟最多 login_rate_limit 次尝试
 *   - 登录成功后重新生成会话 ID（防会话固定）
 *   - 用户名不存在与密码错误返回相同提示（防用户名枚举）
 *
 * 注册内置防护:
 *   - 仅在 allow_register=true 且 multi_user=true 时开放
 *   - 每个 IP 在 register_rate_limit 秒内最多 3 次注册（防灌水）
 *   - 用户名白名单校验（字母/数字/下划线/连字符，3-50 位）
 *   - 密码长度校验（8-72 位，72 为 bcrypt 截断上限）
 *   - 邮箱格式校验（可选字段，提供则校验）
 *   - 注册成功后自动登录（regenerate session ID）
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$action = request_string('action', 20, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'me' : 'login');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($action) {

    case 'login':
        Api::requireMethod('POST');

        // 登录也要求 CSRF（防登录 CSRF / 跨站刷登录）
        if (!Auth::verifyCsrf()) {
            json_out(403, '会话已过期，请刷新页面后重试');
        }

        $username = request_string('username', 50);
        $password = (string)(request_body_input('password', ''));

        $result = Auth::attempt($username, $password);
        if (!$result['ok']) {
            json_out(401, $result['msg']);
        }

        json_out(200, $result['msg'], ['user' => Auth::user()]);

    case 'logout':
        Api::requireMethod('POST');
        Api::requireCsrf();

        Auth::logout();
        json_out(200, '已退出登录');

    case 'register':
        Api::requireMethod('POST');

        // 前置条件：必须开启多用户模式 + 允许注册
        if (!config('multi_user', false) || !config('allow_register', false)) {
            json_out(403, '本站未开放注册');
        }

        if (!Auth::verifyCsrf()) {
            json_out(403, '会话已过期，请刷新页面后重试');
        }

        // 注册限流：每个 IP 在时间窗口内最多 3 次
        Api::rateLimit('register', (int)config('register_rate_limit', 3600), 3);

        // ---- 读取与校验字段 ----
        $username = request_string('username', 50);
        $password = (string)(request_body_input('password', ''));
        $email    = request_string('email', 100);
        $confirm  = (string)(request_body_input('confirm_password', ''));

        // 用户名白名单校验：字母/数字/下划线/连字符，3-50 位
        if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $username)) {
            json_out(400, '用户名只能包含字母、数字、下划线和连字符，长度 3-50');
        }

        // 密码长度校验：8-72 位（bcrypt 截断上限为 72）
        $pwdLen = strlen($password);
        if ($pwdLen < 8 || $pwdLen > 72) {
            json_out(400, '密码长度需为 8-72 位');
        }

        // 两次密码必须一致
        if ($password !== $confirm) {
            json_out(400, '两次输入的密码不一致');
        }

        // 邮箱可选，提供则校验格式
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(400, '邮箱格式不正确');
        }

        // 用户名唯一性检查（防竞态：先 SELECT 后 INSERT，唯一索引兜底）
        $pdo = db();
        $exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $exists->execute([$username]);
        if ($exists->fetch()) {
            json_out(400, '用户名已存在');
        }

        // 插入新用户（自助注册固定 role=user，不可能提权为 admin）
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password, email, role, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        try {
            $stmt->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $email,
                'user',
                date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $ex) {
            // 唯一索引冲突（竞态注册）
            if (str_contains((string)$ex->getCode(), '23')) {
                json_out(400, '用户名已存在');
            }
            log_write('error', '注册写入失败 username=' . $username . ' err=' . $ex->getMessage());
            json_out(500, '注册失败，请稍后重试');
        }

        $userId = (int)$pdo->lastInsertId();
        log_write('auth', "注册成功 username={$username} id={$userId} ip=" . client_ip());

        // 注册成功后自动登录
        Auth::login(['id' => $userId, 'username' => $username, 'role' => 'user']);
        RateLimiter::clear('register:' . client_ip());

        json_out(200, '注册成功', ['user' => ['id' => $userId, 'username' => $username]]);

    case 'me':
        $user = Auth::user();
        json_out(200, 'ok', [
            'logged_in'  => $user !== null,
            'multi_user' => (bool)config('multi_user', false),
            'user'       => $user,
            'csrf_token' => Auth::csrfToken(),
        ]);

    default:
        json_out(400, '未知的 action 参数');
}
