<?php
/**
 * PonyImage 数据库连接
 *
 * 通过 includes/bootstrap.php 自动加载，业务代码请使用：
 *   $pdo = db();   // 返回 PDO 单例（预处理语句模式）
 */

declare(strict_types=1);

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 获取 PDO 数据库连接（单例）
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = config();
    $type   = strtolower((string)($config['db_type'] ?? 'sqlite'));

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // 禁用模拟预处理，由 MySQL 服务端真正预处理，进一步降低注入风险
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        if ($type === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['db_host'] ?? '127.0.0.1',
                (int)($config['db_port'] ?? 3306),
                $config['db_name'] ?? 'ponyimage'
            );
            $pdo = new PDO($dsn, (string)$config['db_user'], (string)$config['db_pass'], $options);
        } elseif ($type === 'sqlite') {
            $file = (string)(config('db_file') ?? '');
            if ($file === '') {
                $file = ROOT_PATH . '/db/ponyimage.db';
            }
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $pdo = new PDO('sqlite:' . $file, null, null, $options);
            // SQLite 常规性能与安全设置
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            throw new RuntimeException('不支持的数据库类型: ' . $type);
        }
    } catch (PDOException $e) {
        log_write('db', '数据库连接失败: ' . $e->getMessage());
        json_out(500, config('debug') ? '数据库连接失败: ' . $e->getMessage() : '服务暂时不可用');
    }

    return $pdo;
}

/**
 * 逐条执行 schema.php 中的 SQL 块（按分号拆分，跳过空语句）
 *
 * @param array $chunks config/schema.php 中某个驱动的语句块列表
 */
function db_exec_schema(PDO $pdo, array $chunks): void
{
    foreach ($chunks as $chunk) {
        foreach (explode(';', (string)$chunk) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }
}

/**
 * 初始化 SQLite 表结构（仅 SQLite 模式、表不存在时自动执行一次，方便开箱即用）
 * 表结构定义见 config/schema.php（唯一权威来源）
 * MySQL 用户请导入项目根目录的 schema.sql
 */
function db_init_sqlite(): void
{
    $schema = require ROOT_PATH . '/config/schema.php';
    db_exec_schema(db(), $schema['sqlite'] ?? []);
}
