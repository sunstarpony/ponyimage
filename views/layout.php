<?php
/**
 * PonyImage 公共布局：窄侧边栏（64px 纯图标，悬停提示文字）+ 右侧自适应主区域
 *
 * 页面级变量约定（由 index.php / login.php / view.php / admin/index.php 提供）：
 *   $pageTitle   string  浏览器标题
 *   $page        string  当前页面标识（upload/manage/about/login/view/admin）
 *   $viewFile    string  视图文件绝对路径
 *   $currentUser ?array  当前登录用户
 */

if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit('Forbidden');
}

$currentUser = $currentUser ?? null;
$appName     = (string)config('app_name', 'PonyImage');
$multiUser   = (bool)config('multi_user', false);
$csrfToken   = Auth::csrfToken();

/*
 * 前端运行时配置：以 JSON 数据块注入页面（非可执行脚本，CSP script-src 'self' 下合法），
 * 替代原 assets/js/config.php，避免 /admin/ 等子路径下相对引用 404。
 */
$ponyConfig = [
    'baseUrl'           => BASE_URL,
    'allowedExtensions' => array_values(array_map(
        static fn($x): string => strtolower((string)$x),
        (array)config('allowed_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp'])
    )),
    'maxFileSize' => (int)config('max_file_size', 10485760),
    'loggedIn'    => Auth::check(),
];
$ponyConfigJson = json_encode(
    $ponyConfig,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

/* 内容安全策略：只允许加载本站资源，禁止内联脚本 */
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($csrfToken) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> - <?= e($appName) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='3' fill='%232563eb'/><circle cx='5.2' cy='5.2' r='1.6' fill='%23ffffff'/><path d='M2.6 12.8L6 9l2.4 2.8 2-2 3 3z' fill='%23ffffff'/></svg>">
<link rel="stylesheet" href="<?= url('assets/package/css/bootstrap.min.css') ?>?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= url('assets/package/css/bootstrap-icons.min.css') ?>?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= url('assets/css/custom.css') ?>?v=<?= ASSET_VERSION ?>">
</head>
<body data-page="<?= e($page ?? '') ?>">

<div class="app-wrapper">
    <!-- ============ 侧边栏（纯图标窄栏，悬停显示文字提示） ============ -->
    <aside class="sidebar">
        <a class="sidebar-brand" href="<?= url('index.php') ?>" data-tip="<?= e($appName) ?>" aria-label="<?= e($appName) ?>">
            <i class="bi bi-images"></i>
        </a>

        <nav class="sidebar-nav" aria-label="主导航">
            <a href="<?= url('index.php?p=upload') ?>" class="sidebar-link <?= ($page ?? '') === 'upload' ? 'active' : '' ?>" data-tip="上传图片" aria-label="上传图片">
                <i class="bi bi-cloud-arrow-up"></i>
            </a>
            <a href="<?= url('index.php?p=manage') ?>" class="sidebar-link <?= ($page ?? '') === 'manage' ? 'active' : '' ?>" data-tip="我的图片" aria-label="我的图片">
                <i class="bi bi-grid-3x3"></i>
            </a>
            <?php if ($currentUser !== null && $currentUser['role'] === 'admin'): ?>
            <a href="<?= url('admin/') ?>" class="sidebar-link <?= ($page ?? '') === 'admin' ? 'active' : '' ?>" data-tip="管理面板" aria-label="管理面板">
                <i class="bi bi-shield-lock"></i>
            </a>
            <?php endif; ?>
            <a href="<?= url('index.php?p=about') ?>" class="sidebar-link <?= ($page ?? '') === 'about' ? 'active' : '' ?>" data-tip="关于" aria-label="关于">
                <i class="bi bi-info-circle"></i>
            </a>
        </nav>

        <div class="sidebar-footer">
            <?php if ($currentUser !== null): ?>
                <div class="sidebar-user" data-tip="<?= e($currentUser['username']) ?>（<?= $currentUser['role'] === 'admin' ? '管理员' : '用户' ?>）">
                    <i class="bi bi-person-circle"></i>
                </div>
                <button type="button" class="btn btn-outline-light btn-sm w-100" id="btnLogout" data-tip="退出登录" aria-label="退出登录">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            <?php elseif ($multiUser): ?>
                <a href="<?= url('login.php') ?>" class="sidebar-link" data-tip="登录" aria-label="登录">
                    <i class="bi bi-box-arrow-in-right"></i>
                </a>
                <?php if (config('allow_register', false)): ?>
                <a href="<?= url('login.php?mode=register') ?>" class="sidebar-link" data-tip="注册" aria-label="注册">
                    <i class="bi bi-person-plus"></i>
                </a>
                <?php endif; ?>
            <?php else: ?>
                <div class="sidebar-user" data-tip="公开模式 · 免登录使用">
                    <i class="bi bi-globe"></i>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <!-- ============ 主区域 ============ -->
    <main class="app-main">
        <?php require $viewFile; ?>
    </main>
</div>

<!-- 全局提示 Toast -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer" style="z-index:1090"></div>

<!-- 前端运行时配置（JSON 数据块，由 app.js 读取，浏览器不会执行） -->
<script type="application/json" id="pony-config"><?= $ponyConfigJson ?></script>
<script src="<?= url('assets/package/js/bootstrap.bundle.min.js') ?>?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= url('assets/js/utils.js') ?>?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= url('assets/js/app.js') ?>?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
