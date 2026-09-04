<?php
/**
 * PonyImage 公共辅助函数
 */

declare(strict_types=1);

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 读取配置项（支持点号取值，如 config('db.host')）
 *
 * @return mixed 配置值或默认值
 */
function config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['PONY_CONFIG'] ?? [];

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $seg) {
        if (!is_array($value) || !array_key_exists($seg, $value)) {
            return $default;
        }
        $value = $value[$seg];
    }

    return $value;
}

/**
 * HTML 转义输出（防 XSS），所有输出到页面的动态内容必须经过本函数
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 统一 JSON 响应并终止脚本
 *
 * 结构固定为 { code, msg, data }
 */
function json_out(int $code, string $msg, mixed $data = null, ?int $httpStatus = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        http_response_code($httpStatus ?? (int)$code);
    }
    echo json_encode(
        ['code' => $code, 'msg' => $msg, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * 获取客户端真实 IP
 *
 * 默认只信任 REMOTE_ADDR；仅在 trust_proxy 开启时才读取 X-Forwarded-For（取第一个，由前置代理写入）
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (config('trust_proxy', false)) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * 获取当前请求的 User-Agent（截断防止超长写入）
 */
function user_agent(): string
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
}

/**
 * 判断当前是否 HTTPS 请求
 */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && config('trust_proxy', false)) {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
        return true;
    }
    return false;
}

/**
 * 站点根 URL（去除末尾斜杠）
 */
function app_url(): string
{
    $url = rtrim((string)config('app_url', ''), '/');
    if ($url === '') {
        // 未配置 app_url 时按当前请求推断（仅作兜底，生产环境务必显式配置）
        $scheme = is_https() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url    = $scheme . '://' . $host;
    }
    return $url;
}

/**
 * 根据数据库中的相对路径生成可访问的完整 URL
 */
function asset_url(string $relativePath): string
{
    $path = ltrim(str_replace('\\', '/', $relativePath), '/');
    return app_url() . '/' . $path;
}

/**
 * 生成密码学安全的随机密钥（十六进制）
 */
function random_key(int $length = 32): string
{
    return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
}

/**
 * 读取 POST 参数：兼容 JSON 请求体与普通表单
 */
function request_input(string $key, mixed $default = null): mixed
{
    // 普通表单
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }

    // JSON 请求体（仅解析一次并缓存）
    static $jsonBody = null;
    if ($jsonBody === null) {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw   = file_get_contents('php://input') ?: '';
            $parsed = json_decode($raw, true);
            $jsonBody = is_array($parsed) ? $parsed : [];
        } else {
            $jsonBody = [];
        }
    }
    if (array_key_exists($key, $jsonBody)) {
        return $jsonBody[$key];
    }

    // 最后回退到 URL 查询参数（仅用于 action / page 等非敏感字段）
    return $_GET[$key] ?? $default;
}

/**
 * 仅从请求体（POST 表单或 JSON body）读取参数，不回退 URL 查询参数。
 * 敏感凭证类字段（password / delete_key / csrf_token 等）必须使用本函数，
 * 避免密钥出现在 URL 中被代理日志、浏览器历史记录泄露。
 */
function request_body_input(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }

    static $jsonBodyOnly = null;
    if ($jsonBodyOnly === null) {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw    = file_get_contents('php://input') ?: '';
            $parsed = json_decode($raw, true);
            $jsonBodyOnly = is_array($parsed) ? $parsed : [];
        } else {
            $jsonBodyOnly = [];
        }
    }

    return $jsonBodyOnly[$key] ?? $default;
}

/**
 * 获取并校验整数参数
 */
function request_int(string $key, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
{
    $value = request_input($key, $default);
    if (is_string($value)) {
        $value = ctype_digit(ltrim($value, '-')) ? (int)$value : $default;
    } elseif (!is_int($value)) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

/**
 * 获取并清理字符串参数（去除首尾空白、控制字符）
 */
function request_string(string $key, int $maxLength = 255, string $default = ''): string
{
    $value = (string)(request_input($key, $default));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($value)) ?? '';
    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

/**
 * 格式化文件大小（字节 → 人类可读）
 */
function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $size  = (float)$bytes;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    return ($index === 0 ? (string)(int)$size : round($size, 2)) . ' ' . $units[$index];
}

/**
 * 写应用日志到 runtime/logs/app-YYYYMMDD.log
 */
function log_write(string $type, string $message): void
{
    $dir = RUNTIME_PATH . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = sprintf(
        "[%s] [%s] [%s] %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($type),
        PHP_SAPI === 'cli' ? 'cli' : client_ip(),
        str_replace(["\r", "\n"], ' ', $message)
    );
    @file_put_contents($dir . '/app-' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * 生成基于站点根的 URL（自动带 BASE_URL 前缀，子目录部署 / admin 页面下均正确）
 *
 * url('api/upload.php')      → /api/upload.php 或 /pony/api/upload.php
 * url('index.php?p=upload')  → /index.php?p=upload
 */
function url(string $path = '/'): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * 当前页面 URL（用于跳转）
 */
function current_url(string $path = '/'): string
{
    return app_url() . '/' . ltrim($path, '/');
}

/**
 * 统一页面渲染入口
 *
 * 收敛各页面入口（index.php / login.php / view.php / admin/index.php）重复的
 * "设置标题与视图变量 → require layout"流程。
 *
 * @param string $view   视图文件名（views/ 目录下，含 .php 后缀）或绝对路径
 * @param string $page   页面标识（对应 body data-page 与侧边栏高亮）
 * @param string $title  浏览器标题
 */
function render_page(string $view, string $page, string $title): void
{
    $viewFile = str_starts_with($view, '/')
        ? $view
        : ROOT_PATH . '/views/' . $view;

    if (!is_file($viewFile)) {
        http_response_code(500);
        exit('视图不存在: ' . htmlspecialchars(basename($viewFile), ENT_QUOTES, 'UTF-8'));
    }

    /*
     * 将入口文件的全局变量（$currentUser / $image / $error 等）导入本函数作用域，
     * 使布局与视图文件如同在全局作用域中被包含一样访问它们。
     * EXTR_SKIP：不覆盖本函数已有局部变量（$viewFile 与参数）。
     */
    extract($GLOBALS, EXTR_SKIP);

    $pageTitle = $title;
    require ROOT_PATH . '/views/layout.php';
    exit;
}

/**
 * 通用分页查询（COUNT 总数 + LIMIT/OFFSET 取当前页）
 *
 * 收敛 list / admin 接口重复的分页样板。
 *
 * @param string $baseSql  不含 WHERE/LIMIT 的查询主体，如 'FROM images i LEFT JOIN users u ON u.id = i.user_id'
 * @param string $whereSql WHERE 子句（含 WHERE 关键字，可为空串），占位符按顺序
 * @param array  $params   WHERE 绑定参数
 * @param int    $page     页码（>=1）
 * @param int    $perPage  每页数量（1-100）
 * @param string $select   SELECT 列列表
 * @param string $orderBy  排序子句（JOIN 场景需带表别名，如 'i.id DESC'）
 * @return array{rows:array,total:int,page:int,pages:int,per_page:int}
 */
function paginate_query(
    string $baseSql,
    string $whereSql,
    array $params,
    int $page,
    int $perPage,
    string $select = '*',
    string $orderBy = 'id DESC'
): array {
    $perPage = max(1, min(100, $perPage));
    $page    = max(1, $page);

    $pdo = db();

    // 总数
    $countStmt = $pdo->prepare('SELECT COUNT(*) ' . $baseSql . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // 当前页：占位符按顺序统一绑定（WHERE 参数在前，LIMIT/OFFSET 在后且必须为 INT）
    $listStmt = $pdo->prepare(
        'SELECT ' . $select . ' ' . $baseSql . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT ? OFFSET ?'
    );
    $bindPos = 0;
    foreach ($params as $v) {
        $listStmt->bindValue(++$bindPos, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listStmt->bindValue(++$bindPos, $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(++$bindPos, $offset, PDO::PARAM_INT);
    $listStmt->execute();

    return [
        'rows'     => $listStmt->fetchAll(),
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
    ];
}
