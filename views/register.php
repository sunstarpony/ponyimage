<?php
/**
 * 视图：注册表单（login.php?mode=register 使用）
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
                <i class="bi bi-person-plus login-icon"></i>
                <h1 class="h5 mt-2 mb-1"><?= e(config('app_name', 'PonyImage')) ?></h1>
                <p class="text-muted small mb-0">注册新账号</p>
            </div>

            <form id="registerForm" novalidate>
                <div class="mb-3">
                    <label for="regUsername" class="form-label">用户名</label>
                    <input type="text" class="form-control" id="regUsername" name="username"
                           autocomplete="username" required maxlength="50" autofocus
                           pattern="[A-Za-z0-9_\-]{3,50}"
                           title="只能包含字母、数字、下划线和连字符，3-50 位">
                    <div class="form-text">字母、数字、下划线、连字符，3-50 位</div>
                </div>
                <div class="mb-3">
                    <label for="regPassword" class="form-label">密码</label>
                    <input type="password" class="form-control" id="regPassword" name="password"
                           autocomplete="new-password" required minlength="8" maxlength="72">
                    <div class="form-text">至少 8 位</div>
                </div>
                <div class="mb-3">
                    <label for="regConfirmPassword" class="form-label">确认密码</label>
                    <input type="password" class="form-control" id="regConfirmPassword" name="confirm_password"
                           autocomplete="new-password" required minlength="8" maxlength="72">
                </div>
                <div class="mb-4">
                    <label for="regEmail" class="form-label">邮箱 <span class="text-muted">(可选)</span></label>
                    <input type="email" class="form-control" id="regEmail" name="email"
                           autocomplete="email" maxlength="100">
                </div>
                <button type="submit" class="btn btn-primary w-100" id="registerSubmitBtn">
                    <i class="bi bi-person-plus me-1"></i>注册
                </button>
            </form>

            <p class="text-center text-muted small mt-4 mb-0">
                已有账号？<a href="<?= url('login.php') ?>" class="text-decoration-none">去登录</a>
            </p>
        </div>
    </div>
</div>
