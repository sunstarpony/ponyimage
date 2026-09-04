<?php
/**
 * 视图：管理面板（admin/index.php 使用）
 */
if (!defined('PONY_IMAGE')) {
    http_response_code(403);
    exit;
}
?>
<h1 class="h4 mb-4"><i class="bi bi-shield-lock me-2 text-primary"></i>管理面板</h1>

<!-- 统计卡片 -->
<div class="row g-3 mb-4" id="statsRow">
    <div class="col-md-4">
        <div class="card shadow-sm stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-image fs-2 text-primary"></i>
                <div>
                    <div class="text-muted small">图片总数</div>
                    <div class="fs-4 fw-semibold" id="statImages">-</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-hdd fs-2 text-success"></i>
                <div>
                    <div class="text-muted small">占用空间</div>
                    <div class="fs-4 fw-semibold" id="statSize">-</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-people fs-2 text-info"></i>
                <div>
                    <div class="text-muted small">用户数</div>
                    <div class="fs-4 fw-semibold" id="statUsers">-</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 选项卡 -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabImages" type="button" role="tab">
            <i class="bi bi-grid-3x3 me-1"></i>全部图片
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabUsers" type="button" role="tab" id="tabUsersBtn">
            <i class="bi bi-people me-1"></i>用户管理
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- 全部图片 -->
    <div class="tab-pane fade show active" id="tabImages" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <select class="form-select form-select-sm" id="adminUserFilter" style="width:auto">
                    <option value="0">全部用户</option>
                </select>
                <span class="text-muted small" id="adminListSummary"></span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="adminRefreshBtn">
                <i class="bi bi-arrow-clockwise me-1"></i>刷新
            </button>
        </div>
        <div id="adminImageGrid" class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3"></div>
        <nav class="mt-4 d-flex justify-content-center" id="adminPagination" aria-label="图片分页"></nav>
    </div>

    <!-- 用户管理 -->
    <div class="tab-pane fade" id="tabUsers" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-2">用户列表</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th><th>用户名</th><th>邮箱</th><th>角色</th><th>注册时间</th><th class="text-end">操作</th>
                                </tr>
                            </thead>
                            <tbody id="userTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-2"><i class="bi bi-person-plus me-1"></i>创建用户</div>
                    <div class="card-body">
                        <form id="createUserForm">
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="cuUsername">用户名（字母/数字/_/-，3-50 位）</label>
                                <input type="text" class="form-control form-control-sm" id="cuUsername" required maxlength="50" autocomplete="off">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="cuPassword">密码（至少 8 位）</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" id="cuPassword" required minlength="8" autocomplete="off">
                                    <button type="button" class="btn btn-outline-secondary" id="genPasswordBtn" title="生成随机密码">
                                        <i class="bi bi-shuffle"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="cuEmail">邮箱（可选）</label>
                                <input type="email" class="form-control form-control-sm" id="cuEmail" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small mb-1" for="cuRole">角色</label>
                                <select class="form-select form-select-sm" id="cuRole">
                                    <option value="user">普通用户</option>
                                    <option value="admin">管理员</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-plus-circle me-1"></i>创建
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 删除图片确认（管理员会话内删除，无需密钥） -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title small">删除确认</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body small">确定删除图片 <strong id="delTargetName"></strong> 吗？不可恢复。</div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-sm btn-danger" id="delConfirmBtn">确认删除</button>
            </div>
        </div>
    </div>
</div>

<!-- 重置密码弹窗 -->
<div class="modal fade" id="modalResetPwd" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title small">重置密码</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small mb-1" for="rpPassword">为用户 <strong id="rpUsername"></strong> 设置新密码（至少 8 位）</label>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="rpPassword" minlength="8" autocomplete="off">
                    <button type="button" class="btn btn-outline-secondary" id="rpGenBtn" title="生成随机密码"><i class="bi bi-shuffle"></i></button>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-sm btn-primary" id="rpConfirmBtn">确认</button>
            </div>
        </div>
    </div>
</div>
