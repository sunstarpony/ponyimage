<?php
/**
 * PonyImage 管理面板（仅 admin 角色可访问）
 *
 * 功能：站点统计 / 全部图片管理 / 用户管理（创建、删除、重置密码）
 * 数据均通过 api/admin.php 获取（带 CSRF）。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$currentUser = Auth::user();

if ($currentUser === null) {
    header('Location: ' . app_url() . '/login.php?redirect=admin');
    exit;
}
if ($currentUser['role'] !== 'admin') {
    http_response_code(403);
    $error = '该页面仅管理员可访问';
    render_page('view_error.php', 'admin', '无权访问');
}

render_page('admin_panel.php', 'admin', '管理面板');
