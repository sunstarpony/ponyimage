<?php
/**
 * 视图：我的图片（管理）
 * 网格展示 + 分页 + 复制链接 / Markdown / 删除
 */
if (!defined('PONY_IMAGE')) { exit; }
?>
<div class="d-flex justify-content-between align-items-center mb-4 page-head flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-grid-3x3 me-2 text-primary"></i>我的图片</h1>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small" id="listSummary"></span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="btnRefresh">
            <i class="bi bi-arrow-clockwise me-1"></i>刷新
        </button>
    </div>
</div>

<!-- 图片网格 -->
<div id="imageGrid" class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3"></div>

<!-- 空状态 -->
<div id="emptyState" class="text-center py-5 d-none">
    <i class="bi bi-inbox fs-1 text-muted"></i>
    <p class="text-muted mt-2 mb-3">还没有图片，去上传第一张吧</p>
    <a href="<?= url('index.php?p=upload') ?>" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>去上传</a>
</div>

<!-- 加载状态 -->
<div id="loadingState" class="text-center py-5">
    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">加载中</span></div>
</div>

<!-- 分页 -->
<nav class="mt-4 d-flex justify-content-center" id="pagination" aria-label="图片分页"></nav>

<!-- 删除弹窗 -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title small">删除图片</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2" id="delThumbWrap"><img id="delThumb" class="rounded border" style="max-height:140px" alt="缩略图"></div>
                <p class="small mb-2">确定删除 <strong id="delTargetName"></strong> 吗？此操作不可恢复。</p>
                <div class="mb-2 d-none" id="delKeyWrap">
                    <label class="form-label small mb-1" for="delKeyInput">删除密钥（上传时返回的 delete_key）</label>
                    <input type="text" class="form-control form-control-sm" id="delKeyInput"
                           placeholder="粘贴删除密钥" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-sm btn-danger" id="delConfirmBtn">
                    <i class="bi bi-trash3 me-1"></i>确认删除
                </button>
            </div>
        </div>
    </div>
</div>
