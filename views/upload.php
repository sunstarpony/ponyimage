<?php
/**
 * 视图：上传图片
 * 拖拽 / 点击选择上传，XHR 进度条，结果展示链接与删除密钥
 */
if (!defined('PONY_IMAGE')) { exit; }
?>
<div class="d-flex justify-content-between align-items-center mb-4 page-head">
    <h1 class="h4 mb-0"><i class="bi bi-cloud-arrow-up me-2 text-primary"></i>上传图片</h1>
    <span class="text-muted small">
        允许类型：<?= e(implode(' / ', (array)config('allowed_extensions'))) ?> ·
        单文件最大 <?= e(format_bytes((int)config('max_file_size'))) ?> · 支持多选批量
    </span>
</div>

<!-- 拖拽上传区 -->
<div id="dropZone" class="dropzone" role="button" tabindex="0" aria-label="点击或拖拽图片到此处上传">
    <i class="bi bi-cloud-arrow-up-fill dropzone-icon"></i>
    <p class="mb-1 fw-semibold">点击选择图片，或将图片拖拽到此处</p>
    <p class="text-muted small mb-0">可一次选择多张图片批量上传</p>
    <input type="file" id="fileInput" class="d-none"
           accept="<?= e(implode(',', array_map(static fn($x) => '.' . $x, (array)config('allowed_extensions')))) ?>" multiple>
</div>

<!-- 全局进度 -->
<div id="uploadProgressWrap" class="mt-3 d-none">
    <div class="d-flex justify-content-between small text-muted mb-1">
        <span id="uploadProgressText">准备上传…</span>
        <span id="uploadProgressCount"></span>
    </div>
    <div class="progress" style="height:8px">
        <div id="uploadProgressBar" class="progress-bar" style="width:0%"></div>
    </div>
</div>

<!-- 上传结果 -->
<h2 class="h6 mt-4 mb-3 d-none" id="resultHead"><i class="bi bi-check2-circle me-1 text-success"></i>上传结果</h2>
<div id="uploadResults" class="vstack gap-3"></div>

<!-- 删除确认弹窗（按删除密钥） -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title small">删除确认</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">确定删除图片 <strong id="delTargetName"></strong> 吗？此操作不可恢复。</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-sm btn-danger" id="delConfirmBtn">确认删除</button>
            </div>
        </div>
    </div>
</div>
