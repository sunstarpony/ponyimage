<?php
/**
 * PonyImage 登录 / 注册页（多用户模式）
 *
 * 单用户（公开）模式下访问本页自动跳回首页。
 * ?mode=register 显示注册表单（仅在 allow_register=true 时）
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!config('multi_user', false)) {
    header('Location: ' . app_url() . '/index.php');
    exit;
}

// 已登录无需再看登录/注册页
if (Auth::check()) {
    header('Location: ' . app_url() . '/index.php?p=manage');
    exit;
}

$mode = request_string('mode', 10, 'login');

// 注册模式但未开启注册功能 → 跳回登录
if ($mode === 'register' && !config('allow_register', false)) {
    header('Location: ' . app_url() . '/login.php');
    exit;
}

$currentUser = null;
render_page(
    $mode === 'register' ? 'register.php' : 'login.php',
    $mode === 'register' ? 'register' : 'login',
    $mode === 'register' ? '注册' : '登录'
);
