<?php
/**
 * PonyImage 主入口（页面路由）
 *
 * 通过 ?p=xxx 在同一布局下切换视图：
 *   /index.php?p=upload   上传页（默认）
 *   /index.php?p=manage   我的图片
 *   /index.php?p=about    关于
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

/* ---- 视图白名单（禁止包含任意文件） ---- */
$allowPages = ['upload', 'manage', 'about'];
$page  = (string)(request_input('p', 'upload'));
if (!in_array($page, $allowPages, true)) {
    $page = 'upload';
}

/* ---- 多用户模式：管理页需要登录 ---- */
$currentUser = Auth::user();
if ($page === 'manage' && config('multi_user', false) && $currentUser === null) {
    header('Location: ' . app_url() . '/login.php?redirect=manage');
    exit;
}

$pageTitles = [
    'upload' => '上传图片',
    'manage' => '我的图片',
    'about'  => '关于',
];

render_page($page . '.php', $page, $pageTitles[$page] ?? 'PonyImage');
