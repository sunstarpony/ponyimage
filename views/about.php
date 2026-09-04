<?php
/**
 * 视图：关于
 */
if (!defined('PONY_IMAGE')) { exit; }
?>
<h1 class="h4 mb-4"><i class="bi bi-info-circle me-2 text-primary"></i>关于</h1>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= e(config('app_name', 'PonyImage')) ?> <span class="badge text-bg-secondary">v<?= e(PONY_VERSION) ?></span></h2>
                <p class="text-muted">
                    自托管、轻量的个人 / 小团队图床程序。代码完全自研，不依赖任何重型框架：
                    前端使用 Bootstrap 5 + 原生 JavaScript，后端使用原生 PHP + PDO，
                    数据库支持 MySQL 与 SQLite，部署简单、资源占用低、完全可控。
                </p>
                <hr>
                <h3 class="h6 mb-2">主要特性</h3>
                <ul class="text-muted small mb-0">
                    <li>拖拽 / 多选批量上传，实时进度显示</li>
                    <li>按 年/月 自动分目录存储，随机文件名防止信息泄露</li>
                    <li>GD 自动生成等比缩略图（支持透明通道）</li>
                    <li>每张图片独立删除密钥，无需登录即可安全删除</li>
                    <li>文件真实类型双重校验（finfo + getimagesize），防伪造上传</li>
                    <li>PDO 预处理语句 + CSP / CSRF / XSS / 限流多重安全防护</li>
                    <li>可选多用户模式，支持用户管理与权限隔离</li>
                    <li>支持固定 Token 供外部工具（脚本、图床客户端）调用</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 mb-3"><i class="bi bi-lightning-charge me-1 text-warning"></i>快速开始</h3>
                <ol class="small text-muted mb-0 ps-3">
                    <li class="mb-1">点击左侧「上传图片」</li>
                    <li class="mb-1">拖入或选择图片</li>
                    <li class="mb-1">上传完成后一键复制链接</li>
                    <li>妥善保存返回的删除密钥</li>
                </ol>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="h6 mb-3"><i class="bi bi-shield-check me-1 text-success"></i>安全提示</h3>
                <p class="small text-muted mb-0">
                    删除密钥仅在上传成功时展示一次，请自行保存。任何拥有删除密钥的人都可以删除对应图片，请勿泄露。
                </p>
            </div>
        </div>
    </div>
</div>
