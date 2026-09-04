<?php
/**
 * PonyImage 认证与会话管理
 *
 * 覆盖：Session 安全配置、登录 / 登出、登录态校验、CSRF、API Token 认证。
 */

declare(strict_types=1);

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // 仅允许通过 Cookie 携带会话 ID，禁止 URL 传递（防会话固定/泄露）
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        $expire = (int)config('session_expire', 3600);

        session_name('PONYSESSID');
        session_set_cookie_params([
            'lifetime' => 0,                       // 浏览器会话级 Cookie，服务端另行控制过期
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_https(),             // HTTPS 下仅加密传输
            'httponly' => true,                   // JS 不可读取，防 XSS 窃取
            'samesite' => 'Lax',                  // 缓解 CSRF
        ]);
        // Session 文件统一存放于 runtime/sessions（权限 0750），避免落入系统共享 /tmp
        $sessionDir = RUNTIME_PATH . '/sessions';
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0750, true);
        }
        session_save_path($sessionDir);

        // 垃圾回收概率：1%
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string)$expire);

        session_start();

        // ---- 服务端闲置过期控制 ----
        $now = time();
        if (isset($_SESSION['_last_activity']) && ($now - (int)$_SESSION['_last_activity']) > $expire) {
            self::hardDestroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;

        // ---- 定期轮换会话 ID（每 30 分钟）----
        if (!isset($_SESSION['_rotated_at'])) {
            $_SESSION['_rotated_at'] = $now;
        } elseif ($now - (int)$_SESSION['_rotated_at'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_rotated_at'] = $now;
        }
    }

    /** 彻底销毁当前会话 */
    public static function hardDestroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (!headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
    }

    /** 当前是否已登录 */
    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /** 当前登录用户信息（含数据库实时角色），未登录返回 null */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $stmt = db()->prepare('SELECT id, username, email, role, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            // 用户已被删除，强制登出
            self::hardDestroy();
            return null;
        }
        return $user;
    }

    /** 当前用户是否为管理员 */
    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }

    /**
     * 尝试登录
     *
     * @return array{ok:bool,msg:string}
     */
    public static function attempt(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'msg' => '用户名或密码不能为空'];
        }

        // 登录限流：每个 IP 每 15 分钟 N 次
        $limit = (int)config('login_rate_limit', 5);
        if (!RateLimiter::hit('login:' . client_ip(), 900, max(1, $limit))) {
            log_write('auth', "登录限流触发 username={$username}");
            return ['ok' => false, 'msg' => '尝试次数过多，请 15 分钟后再试'];
        }

        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // password_verify 统一校验（无论用户是否存在都执行一次哈希比较，防时序侧信道探测用户名）
        $hash = $user['password'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
        if ($user === false || !password_verify($password, $hash)) {
            log_write('auth', "登录失败 username={$username}");
            return ['ok' => false, 'msg' => '用户名或密码错误'];
        }

        // 需要时自动升级哈希算法成本
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $up = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
            $up->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
        }

        self::login($user);
        RateLimiter::clear('login:' . client_ip());
        log_write('auth', "登录成功 username={$username}");
        return ['ok' => true, 'msg' => '登录成功'];
    }

    /** 写入登录态（含会话固定防护：重新生成会话 ID） */
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['role']     = $user['role'] ?? 'user';
        $_SESSION['_rotated_at'] = time();
    }

    /** 退出登录 */
    public static function logout(): void
    {
        log_write('auth', '退出登录 user_id=' . (string)($_SESSION['user_id'] ?? 0));
        self::hardDestroy();
    }

    /* ---------------- CSRF ---------------- */

    /** 获取（不存在则生成）CSRF Token */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_key(64);
        }
        return (string)$_SESSION['_csrf_token'];
    }

    /** 校验 CSRF Token（支持 X-CSRF-Token 头或 token 参数） */
    public static function verifyCsrf(?string $token = null): bool
    {
        $token = $token
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
            ?? (string)(request_body_input('csrf_token', ''));

        $known = (string)($_SESSION['_csrf_token'] ?? '');
        if ($known === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($known, (string)$token);
    }

    /* ---------------- API Token 认证 ---------------- */

    /**
     * 尝试 API Token 认证（供外部程序调用）
     *
     * 支持两种传递方式：
     *   1. 请求头 Authorization: Bearer <token>
     *   2. 参数 token=<token>
     *
     * @return bool 认证是否成功
     */
    public static function tokenAuth(): bool
    {
        $tokens = config('api_tokens', []);
        if (!is_array($tokens) || $tokens === []) {
            return false;
        }

        $presented = '';
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            $presented = $m[1];
        } elseif ((string)request_input('token', '') !== '') {
            $presented = (string)request_input('token', '');
        }

        if ($presented === '') {
            return false;
        }

        foreach ($tokens as $valid) {
            if (is_string($valid) && $valid !== '' && hash_equals($valid, $presented)) {
                return true;
            }
        }
        return false;
    }
}
