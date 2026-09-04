<?php
/**
 * PonyImage 网页安装向导
 *
 * 流程：环境检查 → 填写配置 → 执行安装 → 完成自锁
 *
 * 安全设计：
 *   - 安装成功后写入 runtime/install.lock，之后本文件拒绝执行
 *   - 若 config/config.php 已存在且无锁文件（疑似已配置站点），同样拒绝，防止覆盖攻击
 *   - 表单带 CSRF 校验；SQLite 路径固定，不接受外部输入
 *   - 配置文件以临时文件 + rename 原子写入
 *
 * 安装完成后请立即删除本文件。
 */

declare(strict_types=1);

define('PONY_INSTALL', true);
define('ROOT_PATH', __DIR__);

/* ---------------- 小工具 ---------------- */

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inst_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/* ---------------- 安全会话（仅安装器使用） ---------------- */

session_name('PONYINSTALL');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
]);
session_start();

$csrf = $_SESSION['inst_csrf'] ??= bin2hex(random_bytes(32));

function csrf_ok(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && hash_equals($_SESSION['inst_csrf'] ?? '', $token);
}

/* ---------------- 状态检查 ---------------- */

$lockFile    = ROOT_PATH . '/runtime/install.lock';
$configFile  = ROOT_PATH . '/config/config.php';
$alreadyLocked  = is_file($lockFile);
$configExists   = is_file($configFile);

/* 写日志到 runtime（尽力而为） */
function inst_log(string $msg): void
{
    $dir = ROOT_PATH . '/runtime/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($dir . '/app-' . date('Ymd') . '.log',
        '[' . date('Y-m-d H:i:s') . "] [INSTALL] $msg\n", FILE_APPEND | LOCK_EX);
}

/* ---------------- 环境要求 ---------------- */

function check_dir_writable(string $rel): array
{
    $abs = ROOT_PATH . '/' . $rel;
    if (!is_dir($abs)) {
        return [false, "目录不存在（$rel）"];
    }
    $test = $abs . '/.write_test_' . bin2hex(random_bytes(4));
    if (@file_put_contents($test, 'ok') === false) {
        return [false, "不可写（$rel）"];
    }
    @unlink($test);
    return [true, '可写'];
}

function requirements(): array
{
    $items = [];

    $items[] = ['PHP 版本 ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), '当前 ' . PHP_VERSION, true];

    foreach (['pdo', 'json', 'session', 'mbstring', 'fileinfo'] as $ext) {
        $items[] = ["扩展 $ext", extension_loaded($ext), extension_loaded($ext) ? '已启用' : '未启用', true];
    }

    $gd = extension_loaded('gd');
    $items[] = ['扩展 gd（缩略图）', $gd, $gd ? '已启用' : '未启用（将不生成缩略图）', false];

    $dbDriver = extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql');
    $items[] = ['pdo_sqlite 或 pdo_mysql（至少一个）', $dbDriver,
        $dbDriver ? '可用' : '两者均未启用', true];

    foreach (['runtime', 'uploads', 'thumbs'] as $dir) {
        [$ok, $note] = check_dir_writable($dir);
        $items[] = ["目录 $dir 可写", $ok, $note, true];
    }

    // SQLite 时 db 目录需要可写；MySQL 时仅提示
    if (extension_loaded('pdo_sqlite')) {
        if (!is_dir(ROOT_PATH . '/db')) {
            @mkdir(ROOT_PATH . '/db', 0750, true);
        }
        [$ok, $note] = check_dir_writable('db');
        $items[] = ['目录 db 可写（SQLite）', $ok, $note, false];
    }

    return $items;
}

/* ---------------- 安装执行 ---------------- */

/**
 * @return array{ok:bool,error?:string}
 */
function run_install(array $in): array
{
    /* ---- 1. 参数校验 ---- */
    $appName = trim((string)($in['app_name'] ?? ''));
    if ($appName === '' || mb_strlen($appName) > 30) {
        return ['ok' => false, 'error' => '站点名称不能为空且不超过 30 字'];
    }

    $appUrl = rtrim(trim((string)($in['app_url'] ?? '')), '/');
    if (!preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+#i', $appUrl)) {
        return ['ok' => false, 'error' => '站点地址格式不正确（需以 http:// 或 https:// 开头）'];
    }

    $mode   = ($in['mode'] ?? 'single') === 'multi' ? 'multi' : 'single';
    $dbType = ($in['db_type'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';

    $admin = null;
    if ($mode === 'multi') {
        $username = trim((string)($in['admin_user'] ?? ''));
        $password = (string)($in['admin_pass'] ?? '');
        $email    = trim((string)($in['admin_email'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $username)) {
            return ['ok' => false, 'error' => '管理员用户名只能包含字母、数字、下划线和连字符，长度 3-50'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => '管理员密码长度至少 8 位'];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => '管理员邮箱格式不正确'];
        }
        $admin = [$username, $password, $email];
    }

    /* ---- 2. 建立数据库连接并建表 ---- */
    try {
        if ($dbType === 'mysql') {
            $host = trim((string)($in['db_host'] ?? ''));
            $port = (int)($in['db_port'] ?? 3306);
            $name = trim((string)($in['db_name'] ?? ''));
            $user = trim((string)($in['db_user'] ?? ''));
            $pass = (string)($in['db_pass'] ?? '');
            $createDb = !empty($in['db_create']);

            if ($host === '' || $name === '' || $user === '') {
                return ['ok' => false, 'error' => 'MySQL 主机 / 数据库名 / 用户名不能为空'];
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                return ['ok' => false, 'error' => '数据库名只能包含字母、数字和下划线'];
            }
            if ($port < 1 || $port > 65535) {
                return ['ok' => false, 'error' => 'MySQL 端口不合法'];
            }

            // 先连服务器（不指定库），按需建库
            $base = new PDO(
                "mysql:host=$host;port=$port;charset=utf8mb4",
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            if ($createDb) {
                $base->exec("CREATE DATABASE IF NOT EXISTS `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $base->exec("USE `$name`");

            $pdo = $base;
            foreach (explode(';', __MYSQL_SCHEMA__) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
        } else {
            $dbDir = ROOT_PATH . '/db';
            if (!is_dir($dbDir)) {
                @mkdir($dbDir, 0750, true);
            }
            $dbFile = $dbDir . '/ponyimage.db';
            $pdo = new PDO('sqlite:' . $dbFile, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            foreach ([__SQLITE_SCHEMA__, __SQLITE_INDEXES__] as $schemaChunk) {
                foreach (explode(';', $schemaChunk) as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt !== '') {
                        $pdo->exec($stmt);
                    }
                }
            }
        }

        /* ---- 3. 创建管理员（多用户模式） ---- */
        if ($admin !== null) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $exists->execute([$admin[0]]);
            if ((int)$exists->fetchColumn() > 0) {
                return ['ok' => false, 'error' => '管理员用户名已存在，请更换'];
            }
            $pdo->prepare('INSERT INTO users (username, password, email, role, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$admin[0], password_hash($admin[1], PASSWORD_DEFAULT), $admin[2], 'admin', date('Y-m-d H:i:s')]);
        }
    } catch (PDOException $ex) {
        inst_log('安装失败: ' . $ex->getMessage());
        return ['ok' => false, 'error' => '数据库操作失败：' . $ex->getMessage()];
    }

    /* ---- 4. 原子写入配置文件 ---- */
    $config    = build_config($appName, $appUrl, $mode, $dbType, $in);
    $configFile = ROOT_PATH . '/config/config.php';
    $tmp        = $configFile . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $config, LOCK_EX) === false) {
        return ['ok' => false, 'error' => '无法写入 config/config.php，请检查目录权限'];
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $configFile)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => '配置文件保存失败，请检查目录权限'];
    }

    /* ---- 5. 写入安装锁 ---- */
    @file_put_contents(ROOT_PATH . '/runtime/install.lock',
        'installed_at=' . date('Y-m-d H:i:s') . "\nkey=" . bin2hex(random_bytes(16)) . "\n");

    inst_log("安装完成 mode=$mode db=$dbType" . ($admin !== null ? " admin={$admin[0]}" : ''));
    return ['ok' => true];
}

function build_config(string $appName, string $appUrl, string $mode, string $dbType, array $in): string
{
    $multi    = $mode === 'multi';
    $pubUp    = $multi ? 'false' : 'true';
    $pubList  = $multi ? 'false' : 'true';
    $allowReg = ($multi && !empty($in['allow_register'])) ? 'true' : 'false';

    if ($dbType === 'mysql') {
        $dbBlock = "    'db_type' => 'mysql',\n"
            . "    'db_host' => " . var_export(trim((string)$in['db_host']), true) . ",\n"
            . "    'db_port' => " . (int)$in['db_port'] . ",\n"
            . "    'db_name' => " . var_export(trim((string)$in['db_name']), true) . ",\n"
            . "    'db_user' => " . var_export(trim((string)$in['db_user']), true) . ",\n"
            . "    'db_pass' => " . var_export((string)$in['db_pass'], true) . ",\n";
    } else {
        $dbBlock = "    'db_type' => 'sqlite',\n"
            . "    'db_file' => __DIR__ . '/../db/ponyimage.db',\n";
    }

    $date = date('Y-m-d H:i:s');

    $appNamePhp = var_export($appName, true);
    $appUrlPhp  = var_export($appUrl, true);
    $multiPhp   = $multi ? 'true' : 'false';

    return <<<PHP
<?php
/**
 * PonyImage 主配置文件
 * 由 install.php 于 {$date} 自动生成，可手工编辑后立即生效。
 */

return [
    /* ---------------- 站点基本设置 ---------------- */

    'app_name' => {$appNamePhp},
    'app_url'  => {$appUrlPhp},

    // 时区
    'timezone' => 'Asia/Shanghai',

    /* ---------------- 运行模式 ---------------- */

    // 多用户模式：需要登录才能上传/管理，用户只能管理自己的图片
    'multi_user' => {$multiPhp},

    // 是否允许未登录上传
    'allow_public_upload' => {$pubUp},

    // 是否允许未登录查看列表
    'allow_public_list'   => {$pubList},

    // 是否允许自助注册（多用户模式下生效）
    'allow_register'      => {$allowReg},

    // 注册限流：每 IP 在该时间窗口（秒）内最多 3 次
    'register_rate_limit' => 3600,

    /* ---------------- 存储设置 ---------------- */

    'storage_path' => 'uploads/',
    'thumb_path'   => 'thumbs/',

    // 最大文件大小（字节），默认 10MB；需同步调整 Nginx client_max_body_size 与 php.ini
    'max_file_size' => 10485760,

    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

    /* ---------------- 数据库设置 ---------------- */

{$dbBlock}
    /* ---------------- 缩略图设置 ---------------- */

    'enable_thumb'  => true,
    'thumb_width'   => 320,
    'thumb_height'  => 320,

    /* ---------------- 安全设置 ---------------- */

    // Session 闲置过期时间（秒）
    'session_expire' => 3600,

    // 固定 API Token（供外部程序调用上传接口），留空数组表示禁用
    'api_tokens' => [
    ],

    // 上传限流：每 IP 每 60 秒最多上传次数
    'upload_rate_limit' => 30,

    // 登录限流：每 IP 每 15 分钟最多尝试次数
    'login_rate_limit' => 5,

    // 注册限流：每 IP 在该时间窗口（秒）内最多 3 次注册
    'register_rate_limit' => 3600,

    // 仅在 Nginx 前另有可信代理时开启
    'trust_proxy' => false,

    // 生产环境必须为 false
    'debug' => false,
];

PHP;
}

/* ---------------- 表结构（统一引用 config/schema.php，避免多处维护） ---------------- */

$__schema = require ROOT_PATH . '/config/schema.php';

define('__MYSQL_SCHEMA__', implode(';', $__schema['mysql']));
define('__SQLITE_SCHEMA__', $__schema['sqlite'][0] ?? '');
define('__SQLITE_INDEXES__', $__schema['sqlite'][1] ?? '');
unset($__schema);

/* =========================================================================
 * 以下为页面渲染
 * ========================================================================= */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$step    = 'check';
$message = '';

/* ---- 状态拦截 ---- */
if ($alreadyLocked) {
    $step = 'locked';
} elseif ($configExists) {
    $step = 'refuse';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok()) {
        $message = '会话已过期，请刷新页面重新提交';
        $step = 'check';
    } else {
        $result = run_install($_POST);
        if ($result['ok']) {
            $step = 'done';
        } else {
            $message = $result['error'];
            $step = 'form';
        }
    }
}

$reqs      = requirements();
$reqFailed = false;
foreach ($reqs as [$label, $ok, $note, $required]) {
    if ($required && !$ok) {
        $reqFailed = true;
        break;
    }
}

if ($step === 'check' && !$reqFailed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    // 首次进入且环境全部满足时直接展示表单页（含环境摘要）
    $step = 'form';
}

$old = static fn(string $k, string $d = ''): string => e($_POST[$k] ?? $d);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>安装向导 - PonyImage</title>
<style>
/* 纯色设计，不使用渐变 */
* { box-sizing: border-box; }
body {
    margin: 0;
    background: #f1f5f9;
    color: #0f172a;
    font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans SC", "Microsoft YaHei", sans-serif;
    font-size: 15px;
    line-height: 1.6;
}
.wrap { max-width: 760px; margin: 40px auto; padding: 0 16px; }
.card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    margin-bottom: 18px;
    overflow: hidden;
}
.card-head {
    padding: 14px 22px;
    border-bottom: 1px solid #e2e8f0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-body { padding: 20px 22px; }
.brand { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
.brand-icon {
    width: 42px; height: 42px;
    background: #2563eb;
    color: #fff;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    font-weight: 700;
}
.brand h1 { margin: 0; font-size: 20px; }
.brand small { color: #64748b; font-weight: 400; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { padding: 9px 10px; border-bottom: 1px solid #eef2f7; text-align: left; }
th { color: #475569; font-weight: 600; width: 46%; }
.ok   { color: #16a34a; font-weight: 600; }
.bad  { color: #dc2626; font-weight: 600; }
.warn { color: #d97706; }
label { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 5px; }
label .hint { font-weight: 400; color: #64748b; font-size: 12.5px; }
input[type=text], input[type=password], input[type=email], select {
    width: 100%;
    padding: 8px 11px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 14px;
    background: #fff;
}
input:focus, select:focus { outline: 2px solid #93c5fd; border-color: #2563eb; }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
.radio-line { display: flex; gap: 22px; margin: 8px 0 2px; }
.radio-line label { display: flex; align-items: center; gap: 6px; margin: 0; font-weight: 400; cursor: pointer; }
.checkbox-line { display: flex; align-items: center; gap: 8px; margin: 14px 0 0; font-weight: 400; cursor: pointer; }
.checkbox-line input { width: 18px; height: 18px; }
.btn {
    display: inline-block;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 10px 26px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 20px;
}
.btn:hover { background: #1d4ed8; }
.btn[disabled] { background: #94a3b8; cursor: not-allowed; }
.msg-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    border-radius: 7px;
    padding: 10px 14px;
    margin-bottom: 6px;
    font-size: 14px;
}
.steps { display: flex; gap: 8px; margin-bottom: 18px; font-size: 13px; }
.step {
    flex: 1; text-align: center; padding: 7px 0;
    background: #e2e8f0; color: #64748b; border-radius: 6px;
}
.step.active { background: #2563eb; color: #fff; font-weight: 600; }
.step.passed { background: #dcfce7; color: #15803d; }
fieldset { border: 1px solid #e2e8f0; border-radius: 8px; margin: 18px 0 0; padding: 4px 16px 16px; }
legend { font-size: 14px; font-weight: 600; color: #334155; padding: 0 8px; }
code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
.note { font-size: 13px; color: #64748b; }
.checklist { margin: 0; padding-left: 20px; font-size: 14px; }
.checklist li { margin: 6px 0; }
</style>
</head>
<body>
<div class="wrap">

    <div class="brand">
        <div class="brand-icon">P</div>
        <div>
            <h1>PonyImage 安装向导 <small>v1.0.0</small></h1>
        </div>
    </div>

    <?php if ($step === 'locked'): ?>
    <div class="card">
        <div class="card-head">已安装</div>
        <div class="card-body">
            <p>检测到安装锁（<code>runtime/install.lock</code>），PonyImage 已完成安装，本向导已停用。</p>
            <p class="note">为了安全，请立即从服务器上<strong>删除 install.php 文件</strong>。</p>
            <p><a href="index.php">前往首页</a></p>
        </div>
    </div>

    <?php elseif ($step === 'refuse'): ?>
    <div class="card">
        <div class="card-head">无法运行安装向导</div>
        <div class="card-body">
            <p>检测到 <code>config/config.php</code> 已存在（站点可能已配置过），为防止配置被覆盖，安装向导拒绝继续。</p>
            <p class="note">如确需重新安装，请先删除 <code>config/config.php</code> 与 <code>runtime/install.lock</code>。</p>
        </div>
    </div>

    <?php elseif ($step === 'done'): ?>
    <div class="card">
        <div class="card-head">安装完成</div>
        <div class="card-body">
            <p>PonyImage 已成功安装！</p>
            <ul class="checklist">
                <li>配置文件已写入 <code>config/config.php</code></li>
                <li>数据表已创建<?=(isset($_POST['mode']) && $_POST['mode'] === 'multi') ? '，管理员账号已建立' : ''?></li>
                <li>安装锁已生成 <code>runtime/install.lock</code></li>
            </ul>
            <p><strong>请立即完成以下安全步骤：</strong></p>
            <ul class="checklist">
                <li><strong>删除本文件 install.php</strong>（必须）</li>
                <li>将 <code>runtime/</code> 与 <code>db/</code> 目录权限收紧为 750</li>
                <li>按项目内 <code>nginx.conf</code> 配置 Web 服务器（禁止上传目录执行 PHP）</li>
                <li>建议为站点启用 HTTPS</li>
            </ul>
            <p><a href="index.php">进入首页</a>　<a href="index.php?p=manage">进入管理</a></p>
        </div>
    </div>

    <?php else: /* check / form */ ?>

    <?php if ($step === 'form' && $reqFailed): ?>
        <div class="card"><div class="card-head">环境检查未通过</div>
        <div class="card-body">
            <p>存在未满足的必要条件，请先解决下列标红项后刷新本页：</p>
            <table>
                <?php foreach ($reqs as [$label, $ok, $note, $required]): ?>
                <tr>
                    <th><?=$label?><?=$required ? '' : ' <span class="warn">(可选)</span>'?></th>
                    <td class="<?=$ok ? 'ok' : 'bad'?>"><?=e($note)?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div></div>
    <?php else: ?>

    <div class="steps">
        <div class="step active">1 · 环境检查</div>
        <div class="step <?=$step === 'form' ? 'active' : ''?>">2 · 配置</div>
        <div class="step">3 · 完成</div>
    </div>

    <div class="card">
        <div class="card-head">服务器环境检查</div>
        <div class="card-body">
            <table>
                <?php foreach ($reqs as [$label, $ok, $note, $required]): ?>
                <tr>
                    <th><?=$label?><?=$required ? '' : ' <span class="warn">(可选)</span>'?></th>
                    <td class="<?=$ok ? 'ok' : ($required ? 'bad' : 'warn')?>"><?=e($note)?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if ($reqFailed): ?>
                <p class="note" style="color:#b91c1c">存在必要项未通过，请修复后刷新页面。</p>
            <?php else: ?>
                <p class="note">所有必要条件已满足，请在下方填写配置。</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$reqFailed): ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=e($csrf)?>">

        <?php if ($message !== ''): ?>
            <div class="msg-error"><?=e($message)?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head">站点设置</div>
            <div class="card-body">
                <label for="app_name">站点名称</label>
                <input type="text" id="app_name" name="app_name" maxlength="30" required value="<?=$old('app_name', 'PonyImage')?>">

                <label for="app_url">站点地址 <span class="hint">用于生成图片链接，末尾不带斜杠</span></label>
                <input type="text" id="app_url" name="app_url" required value="<?=$old('app_url', inst_url())?>">

                <label>运行模式</label>
                <div class="radio-line">
                    <label><input type="radio" name="mode" value="single" <?=($_POST['mode'] ?? 'single') === 'single' ? 'checked' : ''?> onchange="toggleMode()"> 单用户（免登录，公开上传）</label>
                    <label><input type="radio" name="mode" value="multi" <?=($_POST['mode'] ?? '') === 'multi' ? 'checked' : ''?> onchange="toggleMode()"> 多用户（需登录）</label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">数据库</div>
            <div class="card-body">
                <div class="radio-line">
                    <label><input type="radio" name="db_type" value="sqlite" <?=($_POST['db_type'] ?? 'sqlite') === 'sqlite' ? 'checked' : ''?> onchange="toggleDb()"> SQLite（推荐，零配置）</label>
                    <label><input type="radio" name="db_type" value="mysql" <?=($_POST['db_type'] ?? '') === 'mysql' ? 'checked' : ''?> onchange="toggleDb()"> MySQL</label>
                </div>

                <fieldset id="mysqlFields" style="display:none">
                    <legend>MySQL 连接</legend>
                    <div class="row2">
                        <div>
                            <label for="db_host">主机</label>
                            <input type="text" id="db_host" name="db_host" value="<?=$old('db_host', '127.0.0.1')?>">
                        </div>
                        <div>
                            <label for="db_port">端口</label>
                            <input type="text" id="db_port" name="db_port" value="<?=$old('db_port', '3306')?>">
                        </div>
                    </div>
                    <div class="row2">
                        <div>
                            <label for="db_name">数据库名</label>
                            <input type="text" id="db_name" name="db_name" value="<?=$old('db_name', 'ponyimage')?>">
                        </div>
                        <div>
                            <label for="db_user">用户名</label>
                            <input type="text" id="db_user" name="db_user" value="<?=$old('db_user', 'ponyimage')?>">
                        </div>
                    </div>
                    <label for="db_pass">密码</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?=$old('db_pass', '')?>">
                    <div class="radio-line" style="margin-top:10px">
                        <label><input type="checkbox" name="db_create" value="1" <?=!empty($_POST['db_create']) ? 'checked' : ''?>> 尝试自动创建数据库（账号需有权限）</label>
                    </div>
                </fieldset>

                <p class="note" id="sqliteNote">SQLite 数据库文件将保存在 <code>db/ponyimage.db</code>，无需其他配置。</p>
            </div>
        </div>

        <div class="card" id="adminCard" style="display:none">
            <div class="card-head">管理员账号</div>
            <div class="card-body">
                <label for="admin_user">用户名 <span class="hint">字母/数字/_/-，3-50 位</span></label>
                <input type="text" id="admin_user" name="admin_user" maxlength="50" value="<?=$old('admin_user', '')?>">

                <div class="row2">
                    <div>
                        <label for="admin_pass">密码 <span class="hint">至少 8 位</span></label>
                        <input type="password" id="admin_pass" name="admin_pass" value="">
                    </div>
                    <div>
                        <label for="admin_email">邮箱 <span class="hint">可选</span></label>
                        <input type="email" id="admin_email" name="admin_email" value="<?=$old('admin_email', '')?>">
                    </div>
                </div>

                <label class="checkbox-line">
                    <input type="checkbox" name="allow_register" value="1" <?=($_POST['allow_register'] ?? '') === '1' ? 'checked' : ''?>>
                    允许自助注册（开启后登录页显示注册入口，新用户默认普通权限）
                </label>
            </div>
        </div>

        <button type="submit" class="btn">开始安装</button>
    </form>

    <script>
    function toggleDb() {
        var isMysql = document.querySelector('input[name="db_type"]:checked').value === 'mysql';
        document.getElementById('mysqlFields').style.display = isMysql ? '' : 'none';
        document.getElementById('sqliteNote').style.display = isMysql ? 'none' : '';
    }
    function toggleMode() {
        var isMulti = document.querySelector('input[name="mode"]:checked').value === 'multi';
        document.getElementById('adminCard').style.display = isMulti ? '' : 'none';
    }
    toggleDb(); toggleMode();
    </script>
    <?php endif; /* !$reqFailed */ ?>

    <?php endif; /* $step === 'form' && $reqFailed 分支 */ ?>

    <?php endif; /* 主状态分支（locked / refuse / done / check+form） */ ?>

</div>
</body>
</html>
