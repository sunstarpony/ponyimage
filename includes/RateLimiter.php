<?php
/**
 * PonyImage 基于文件的轻量限流器（无 Redis 依赖）
 *
 * 原理：每个限流桶（bucket）对应 runtime/ratelimit/ 下一个 JSON 文件，
 * 记录窗口内的请求时间戳，flock 互斥保证并发安全。
 */

declare(strict_types=1);

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

final class RateLimiter
{
    private static string $dir = '';

    /** 返回限流目录（懒创建） */
    private static function dir(): string
    {
        if (self::$dir === '') {
            self::$dir = RUNTIME_PATH . '/ratelimit';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0750, true);
            }
        }
        return self::$dir;
    }

    /**
     * 尝试占用一次配额
     *
     * @param string $bucket 桶名（建议: 动作:IP，如 upload:1.2.3.4）
     * @param int    $window 窗口期（秒）
     * @param int    $max    窗口内最大次数
     * @return bool  true=允许本次请求，false=已超限
     */
    public static function hit(string $bucket, int $window, int $max): bool
    {
        $file = self::dir() . '/' . sha1($bucket) . '.json';
        $now  = time();

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            // 限流器自身故障时放行（可用性优先），并记录日志
            log_write('ratelimit', '无法打开限流文件: ' . $file);
            return true;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return true;
            }

            $stat  = fstat($fh);
            $raw   = $stat['size'] > 0 ? (string)stream_get_contents($fh) : '';
            $times = [];
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $times = array_values(array_filter($decoded, 'is_int'));
                }
            }

            // 只保留窗口内的时间戳
            $times = array_values(array_filter($times, static fn(int $t): bool => ($now - $t) < $window));

            if (count($times) >= $max) {
                return false; // 超限，不记录本次
            }

            $times[] = $now;

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($times));
            fflush($fh);

            return true;
        } finally {
            if (is_resource($fh)) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
        }
    }

    /**
     * 查询当前剩余可用次数（不消耗配额）
     */
    public static function remaining(string $bucket, int $window, int $max): int
    {
        $file = self::dir() . '/' . sha1($bucket) . '.json';
        if (!is_file($file)) {
            return $max;
        }

        $decoded = json_decode((string)@file_get_contents($file), true);
        if (!is_array($decoded)) {
            return $max;
        }

        $now   = time();
        $count = count(array_filter($decoded, static fn($t): bool => is_int($t) && ($now - $t) < $window));

        return max(0, $max - $count);
    }

    /**
     * 清空指定桶（例如登录成功后清空该 IP 的失败计数）
     */
    public static function clear(string $bucket): void
    {
        $file = self::dir() . '/' . sha1($bucket) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
