<?php
/**
 * PonyImage 全局引导文件
 *
 * 所有入口（页面 / API）第一行 require 本文件。
 *
 * 初始化顺序（不可调换）：
 *   1. 常量与配置加载
 *   2. 辅助函数（helpers.php 提供 config / log_write 等）
 *   3. 数据库连接与核心类
 *   4. 错误与日志处理器（依赖 log_write）
 *   5. 安全响应头
 *   6. SQLite 表结构自动初始化
 *   7. Session 安全启动
 */

declare(strict_types=1);

/* ---------------- 1. 常量与配置 ---------------- */

define('PONY_IMAGE', true);
define('PONY_VERSION', '1.0.0');

/*
 * 静态资源版本号：附加在 CSS/JS URL 后（?v=xxx）。
 * 每次修改 assets 下的文件后递增此值，强制所有访问者浏览器放弃缓存重新下载，
 * 避免"服务端已更新但浏览器仍在执行旧 JS/旧样式"的问题。
 */
define('ASSET_VERSION', '1.1.0');

define('ROOT_PATH', dirname(__DIR__));
define('RUNTIME_PATH', ROOT_PATH . '/runtime');

/*
 * 站点 URL 基路径（用于生成 /assets、/api 等链接前缀）
 * - 部署在域名根目录: ''（如 https://img.com）
 * - 部署在子目录:     '/pony'（如 https://img.com/pony）
 * - 从 /admin/index.php 进入时自动去掉 /admin 后缀，保证资源与 API 始终指向站点根
 */
$__scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($__scriptDir === '/' || $__scriptDir === '.') {
    $__scriptDir = '';
}
if (substr($__scriptDir, -6) === '/admin') {
    $__scriptDir = substr($__scriptDir, 0, -6);
}
define('BASE_URL', $__scriptDir);
unset($__scriptDir);

$__configFile = ROOT_PATH . '/config/config.php';
if (!is_file($__configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("尚未安装：请先访问 /install.php 运行安装向导，或复制 config/config.example.php 为 config.php 手工配置");
}

$GLOBALS['PONY_CONFIG'] = require $__configFile;

/* ---------------- 2. 辅助函数 ---------------- */

require ROOT_PATH . '/includes/helpers.php';

$__debug = (bool)config('debug', false);

/* ---------------- 3. 数据库连接与核心类 ---------------- */

require ROOT_PATH . '/config/database.php';

/*
 * 类自动加载：按 "类名 → includes/类名.php" 约定解析。
 * 新增核心类（如 includes/Foo.php）无需修改本文件。
 */
spl_autoload_register(static function (string $class): void {
    $file = ROOT_PATH . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/* ---------------- 4. 错误与日志处理器 ---------------- */

error_reporting(E_ALL);
ini_set('display_errors', $__debug ? '1' : '0');
ini_set('display_startup_errors', $__debug ? '1' : '0');
ini_set('log_errors', '1');

$__logDir = RUNTIME_PATH . '/logs';
if (!is_dir($__logDir)) {
    @mkdir($__logDir, 0750, true);
}
ini_set('error_log', $__logDir . '/php-error.log');

date_default_timezone_set((string)config('timezone', 'Asia/Shanghai'));

// 生产环境：未捕获的异常统一记录日志，对外只返回 500，不泄露任何细节
set_exception_handler(static function (Throwable $e) use ($__debug): void {
    log_write('fatal', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[Fatal] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $body = ['code' => 500, 'msg' => '服务器内部错误', 'data' => null];
    if ($__debug) {
        $body['debug'] = [
            'error' => $e->getMessage(),
            'file'  => $e->getFile() . ':' . $e->getLine(),
        ];
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit(1);
});

// 警告 / 通知记录到日志但不中断流程（致命错误类型不会进入本回调，由异常处理器兜底）
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    log_write('php', "[$severity] $message @ $file:$line");
    return true;
});

/* ---------------- 5. 通用安全响应头 ---------------- */

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0'); // 现代浏览器下开启反而引入风险，关闭并依赖 CSP
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header_remove('X-Powered-By');
}

/* ---------------- 6. SQLite 表结构自动初始化（开箱即用） ---------------- */

if (strtolower((string)config('db_type', 'sqlite')) === 'sqlite') {
    $dbFile = (string)(config('db_file', '') ?: ROOT_PATH . '/db/ponyimage.db');
    $needInit = !is_file($dbFile);
    if (!$needInit) {
        // 数据库文件存在时，仅当 images 表缺失才初始化（幂等，开销为一次轻量查询）
        $check = db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='images'");
        $needInit = ((int)$check->fetchColumn() === 0);
    }
    if ($needInit) {
        db_init_sqlite();
        log_write('init', 'SQLite 数据库表结构已自动初始化');
    }
}

/* ---------------- 7. Session 安全启动 ---------------- */

Auth::startSession();
