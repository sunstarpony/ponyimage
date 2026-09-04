<?php
/**
 * PonyImage 管理员初始化 / 密码重置工具（仅在服务器命令行执行）
 *
 * 用法:
 *   创建管理员:  php tools/create_admin.php <用户名> <密码> [邮箱]
 *   重置密码:    php tools/create_admin.php --reset <用户名> <新密码>
 *
 * 示例:
 *   php tools/create_admin.php admin 'S3cure!Pass' admin@example.com
 *
 * 说明:
 *   - SQLite 模式会自动创建数据库表结构，开箱即用
 *   - MySQL 模式请先导入 schema.sql
 *   - 本脚本禁止通过 Web 访问（nginx.conf / .htaccess 已拦截，此处再兜底）
 */

declare(strict_types=1);

/* ---- 仅允许命令行执行 ---- */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$argv = $_SERVER['argv'] ?? [];

function usage(): void
{
    echo <<<TXT
PonyImage 管理员工具

用法:
  创建管理员:  php tools/create_admin.php <用户名> <密码> [邮箱]
  重置密码:    php tools/create_admin.php --reset <用户名> <新密码>

示例:
  php tools/create_admin.php admin 'S3cure!Pass' admin@example.com
  php tools/create_admin.php --reset admin 'NewS3cure!Pass'

TXT;
    exit(1);
}

if (count($argv) < 3) {
    usage();
}

$reset = false;
$args  = array_slice($argv, 1);

if ($args[0] === '--reset') {
    $reset = true;
    array_shift($args);
    if (count($args) < 2) {
        usage();
    }
    [$username, $password] = [$args[0], $args[1]];
    $email = '';
} else {
    if (count($args) < 2) {
        usage();
    }
    [$username, $password] = [$args[0], $args[1]];
    $email = $args[2] ?? '';
}

/* ---- 参数校验 ---- */
if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $username)) {
    fwrite(STDERR, "错误: 用户名只能包含字母、数字、下划线和连字符，长度 3-50\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "错误: 密码长度至少 8 位\n");
    exit(1);
}

/* ---- SQLite 自动初始化表结构 ---- */
if (strtolower((string)config('db_type')) === 'sqlite') {
    db_init_sqlite();
}

$pdo = db();

try {
    if ($reset) {
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
        if ($stmt->rowCount() === 0) {
            fwrite(STDERR, "错误: 用户 {$username} 不存在\n");
            exit(1);
        }
        echo "[OK] 已重置用户 {$username} 的密码\n";
        exit(0);
    }

    // 创建管理员
    $exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $exists->execute([$username]);
    if ($exists->fetch()) {
        fwrite(STDERR, "错误: 用户 {$username} 已存在（如需重置密码请使用 --reset）\n");
        exit(1);
    }

    $stmt = $pdo->prepare('INSERT INTO users (username, password, email, role, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email, 'admin', date('Y-m-d H:i:s')]);

    echo "[OK] 管理员创建成功: {$username} (id=" . $pdo->lastInsertId() . ")\n";
    echo "     现在可访问 login.php 登录，并进入 admin/ 管理面板\n";
} catch (PDOException $e) {
    fwrite(STDERR, '数据库错误: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "提示: MySQL 模式请先导入 schema.sql\n");
    exit(1);
}
