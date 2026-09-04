<?php
/**
 * 视图：单图预览详情
 */
if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit;
}

/** @var array $image 图片记录 */
/** @var string $imageUrl $thumbUrl $markdown $htmlCode $bbcode $ownerName */
?>
<div class="d-flex justify-content-between align-items-center mb-4 page-head flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-image me-2 text-primary"></i>图片 #<?= (int)$image['id'] ?></h1>
    <a href="<?= url('index.php?p=manage') ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>返回列表
    </a>
</div>

<div class="row g-4">
    <!-- 原图预览 -->
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body text-center bg-light rounded">
                <a href="<?= e($imageUrl) ?>" target="_blank" rel="noopener">
                    <img src="<?= e($imageUrl) ?>" class="img-fluid rounded" style="max-height:640px"
                         alt="<?= e($image['filename']) ?>" loading="lazy">
                </a>
            </div>
        </div>
    </div>

    <!-- 图片信息 -->
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2"><i class="bi bi-info-circle me-1"></i>文件信息</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 text-muted">原始文件名</dt>
                    <dd class="col-8 text-break"><?= e($image['filename']) ?></dd>

                    <dt class="col-4 text-muted">尺寸</dt>
                    <dd class="col-8"><?= (int)$image['file_width'] ?> × <?= (int)$image['file_height'] ?> px</dd>

                    <dt class="col-4 text-muted">大小</dt>
                    <dd class="col-8"><?= e(format_bytes((int)$image['file_size'])) ?></dd>

                    <dt class="col-4 text-muted">类型</dt>
                    <dd class="col-8"><?= e($image['mime_type']) ?></dd>

                    <dt class="col-4 text-muted">上传时间</dt>
                    <dd class="col-8"><?= e($image['upload_time']) ?></dd>

                    <?php if ($ownerName !== ''): ?>
                    <dt class="col-4 text-muted">上传者</dt>
                    <dd class="col-8"><?= e($ownerName) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white py-2"><i class="bi bi-link-45deg me-1"></i>链接</div>
            <div class="card-body vstack gap-3">
                <?php
                $links = [
                    ['label' => 'URL',        'value' => $imageUrl],
                    ['label' => 'Markdown',   'value' => $markdown],
                    ['label' => 'HTML',       'value' => $htmlCode],
                    ['label' => 'BBCode',     'value' => $bbcode],
                    ['label' => '缩略图 URL', 'value' => $thumbUrl],
                ];
                foreach ($links as $i => $link): ?>
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="width:90px"><?= e($link['label']) ?></span>
                    <input type="text" class="form-control font-monospace" readonly
                           value="<?= e($link['value']) ?>" id="linkField<?= $i ?>">
                    <button type="button" class="btn btn-outline-primary btn-copy" data-target="linkField<?= $i ?>">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <p class="text-muted small mt-3 mb-0">
            <i class="bi bi-shield-lock me-1"></i>删除密钥仅在上传成功时返回一次，本页不提供；如需删除请前往「我的图片」或使用删除密钥。
        </p>
    </div>
</div>
