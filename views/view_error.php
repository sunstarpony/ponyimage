<?php
/**
 * 视图：单图预览错误页
 */
if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit;
}
/** @var string $error 错误提示 */
$error = $error ?? '发生错误';
?>
<div class="text-center py-5">
    <i class="bi bi-emoji-frown fs-1 text-muted"></i>
    <h1 class="h5 mt-3"><?= e($error) ?></h1>
    <a href="<?= url('index.php') ?>" class="btn btn-outline-primary btn-sm mt-2">返回首页</a>
</div>
