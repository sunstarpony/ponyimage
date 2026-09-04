<?php
/**
 * 视图：登录表单（login.php 使用）
 */
if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit;
}
?>
<div class="login-wrap">
    <div class="card shadow login-card">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-person-circle login-icon"></i>
                <h1 class="h5 mt-2 mb-1"><?= e(config('app_name', 'PonyImage')) ?></h1>
                <p class="text-muted small mb-0">登录以管理你的图片</p>
            </div>

            <form id="loginForm" novalidate>
                <div class="mb-3">
                    <label for="loginUsername" class="form-label">用户名</label>
                    <input type="text" class="form-control" id="loginUsername" name="username"
                           autocomplete="username" required maxlength="50" autofocus>
                </div>
                <div class="mb-4">
                    <label for="loginPassword" class="form-label">密码</label>
                    <input type="password" class="form-control" id="loginPassword" name="password"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="loginSubmitBtn">
                    <i class="bi bi-box-arrow-in-right me-1"></i>登录
                </button>
            </form>

            <p class="text-center text-muted small mt-4 mb-0">
                <a href="<?= url('index.php?p=upload') ?>" class="text-decoration-none">返回首页</a>
<?php if (config('allow_register', false)): ?>
                <span class="mx-2">|</span>
                <a href="<?= url('login.php?mode=register') ?>" class="text-decoration-none">注册新账号</a>
<?php endif; ?>
            </p>
        </div>
    </div>
</div>
