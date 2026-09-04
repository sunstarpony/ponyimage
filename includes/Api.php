<?php
/**
 * PonyImage API 请求生命周期封装
 *
 * 将各端点重复的"方法校验 / CSRF / 认证 / 限流"样板收敛为单行调用。
 * 所有方法在校验失败时直接输出 JSON 并终止脚本（与 json_out 行为一致）。
 *
 * 用法示例（api/xxx.php）:
 *   require_once dirname(__DIR__) . '/includes/bootstrap.php';
 *   Api::requireMethod('POST');
 *   Api::requireCsrf();
 *   $user = Api::requireAdmin();
 *   Api::rateLimit('upload', 60, (int)config('upload_rate_limit', 30));
 */

declare(strict_types=1);

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Api
{
    /**
     * 限定 HTTP 方法，不符时输出 405
     */
    public static function requireMethod(string $method): void
    {
        $actual = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($actual !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            json_out(405, '仅支持 ' . strtoupper($method) . ' 请求');
        }
    }

    /**
     * 要求 CSRF 校验通过（写操作必须调用）
     */
    public static function requireCsrf(): void
    {
        if (!Auth::verifyCsrf()) {
            json_out(403, 'CSRF 校验失败，请刷新页面后重试');
        }
    }

    /**
     * 要求已登录，返回当前用户数组
     *
     * @return array{id:int,username:string,role:string,...}
     */
    public static function requireLogin(): array
    {
        $user = Auth::user();
        if ($user === null) {
            json_out(401, '请先登录');
        }
        return $user;
    }

    /**
     * 要求管理员登录，返回当前用户数组
     *
     * @return array{id:int,username:string,role:string,...}
     */
    public static function requireAdmin(): array
    {
        $user = Auth::user();
        if ($user === null || $user['role'] !== 'admin') {
            log_write('security', '非管理员尝试访问管理接口 user_id=' . (string)($_SESSION['user_id'] ?? 0));
            json_out(401, '需要管理员权限');
        }
        return $user;
    }

    /**
     * 按 IP 限流，超限时输出 429
     *
     * @param string $action 动作名（构成桶名 action:IP）
     * @param int    $window 时间窗口（秒）
     * @param int    $max    窗口内最大次数
     */
    public static function rateLimit(string $action, int $window, int $max): void
    {
        if (!RateLimiter::hit($action . ':' . client_ip(), max(1, $window), max(1, $max))) {
            log_write('ratelimit', "限流触发 action={$action} ip=" . client_ip());
            json_out(429, '操作过于频繁，请稍后再试', null, 429);
        }
    }

    /**
     * 读取单张图片记录（含存在性校验），失败时输出 400
     *
     * @return array 图片行
     */
    public static function requireImage(int $id): array
    {
        if ($id <= 0) {
            json_out(400, '参数错误：缺少有效的 id');
        }
        $stmt = db()->prepare('SELECT * FROM images WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_out(400, '图片不存在或已被删除');
        }
        return $row;
    }

    /**
     * 私有列表模式下要求访问者可见该图片（所有者或管理员）
     */
    public static function requireImageVisible(array $row): void
    {
        if (config('allow_public_list', true)) {
            return;
        }
        $user    = Auth::user();
        $isOwner = $user !== null && (int)$user['id'] === (int)$row['user_id'];
        $isAdmin = $user !== null && $user['role'] === 'admin';
        if (!$isOwner && !$isAdmin) {
            json_out(401, '请先登录后查看');
        }
    }
}
