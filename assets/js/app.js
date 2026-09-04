/**
 * PonyImage 主逻辑
 * 按页面 data-page 分发：upload / manage / login / view / admin
 */
'use strict';

/* ================= 前端运行时配置 ================= */
/* 读取 layout 注入的 JSON 数据块（CSP 友好，替代原 assets/js/config.php） */
window.PONY_CONFIG = (() => {
  try {
    return JSON.parse(document.getElementById('pony-config')?.textContent || '{}');
  } catch (_) {
    return {};
  }
})();

/* ================= 基础封装 ================= */

const App = {
  csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
  loggedIn: window.PONY_CONFIG?.loggedIn === true,

  /**
   * 站点根相对路径 → 实际 URL
   * 自动加 BASE_URL 前缀，根目录 / 子目录 / /admin/ 页面下均正确
   */
  url(path) {
    const base = String(window.PONY_CONFIG?.baseUrl || '');
    return base + '/' + String(path).replace(/^\/+/, '');
  },

  /** 带 CSRF 的 API 请求，返回解析后的 JSON（code !== 200 时抛错） */
  async api(url, options = {}) {
    const opts = {
      method: options.method || 'GET',
      headers: { 'X-CSRF-Token': this.csrf },
      credentials: 'same-origin',
      ...options,
    };
    if (opts.body && !(opts.body instanceof FormData) && typeof opts.body !== 'string') {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch(url, opts);
    let json;
    try { json = await res.json(); }
    catch (_) { throw new Error('服务器响应异常（HTTP ' + res.status + '）'); }

    if (json.code !== 200) {
      const err = new Error(json.msg || '请求失败');
      err.code = json.code;
      throw err;
    }
    return json.data;
  },

  copy(text, tip) {
    PonyUtils.copyText(text).then((ok) => {
      PonyUtils.toast(ok ? (tip || '已复制到剪贴板') : '复制失败，请手动复制', ok ? 'success' : 'danger');
    });
  },
};

/* ================= 通用：退出登录 ================= */

document.getElementById('btnLogout')?.addEventListener('click', async () => {
  try {
    await App.api(App.url('api/auth.php?action=logout'), { method: 'POST' });
    location.href = App.url('index.php');
  } catch (err) {
    PonyUtils.toast(err.message, 'danger');
  }
});

/* ================= 页面分发 ================= */

const page = document.body.dataset.page;

if (page === 'upload') initUploadPage();
if (page === 'manage') initManagePage();
if (page === 'login') initLoginPage();
if (page === 'register') initRegisterPage();
if (page === 'view') initViewPage();
if (page === 'admin') initAdminPage();

/* ================= 上传页 ================= */

function initUploadPage() {
  const dropZone   = document.getElementById('dropZone');
  const fileInput  = document.getElementById('fileInput');
  const progressWrap = document.getElementById('uploadProgressWrap');
  const progressBar  = document.getElementById('uploadProgressBar');
  const progressText = document.getElementById('uploadProgressText');
  const progressCount = document.getElementById('uploadProgressCount');
  const resultHead = document.getElementById('resultHead');
  const results    = document.getElementById('uploadResults');

  /** 本次会话上传结果（内存中保存 delete_key，刷新即失效） */
  const uploadedItems = [];

  const ALLOWED = window.PONY_CONFIG?.allowedExtensions || ['jpg', 'jpeg', 'png', 'gif', 'webp'];

  function pickFiles(fileList) {
    const files = Array.from(fileList || []).filter((f) => {
      const ext = f.name.split('.').pop().toLowerCase();
      if (!ALLOWED.includes(ext)) {
        PonyUtils.toast(`跳过「${f.name}」：不支持的类型`, 'warning');
        return false;
      }
      return true;
    });
    if (files.length) uploadQueue(files);
  }

  dropZone.addEventListener('click', () => fileInput.click());
  dropZone.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
  });
  fileInput.addEventListener('change', () => { pickFiles(fileInput.files); fileInput.value = ''; });

  ['dragenter', 'dragover'].forEach((ev) =>
    dropZone.addEventListener(ev, (e) => { e.preventDefault(); dropZone.classList.add('dragging'); }));
  ['dragleave', 'drop'].forEach((ev) =>
    dropZone.addEventListener(ev, (e) => { e.preventDefault(); dropZone.classList.remove('dragging'); }));
  dropZone.addEventListener('drop', (e) => pickFiles(e.dataTransfer?.files));

  /** 顺序上传队列，XHR 监听 progress */
  async function uploadQueue(files) {
    progressWrap.classList.remove('d-none');
    resultHead.classList.remove('d-none');

    let index = 0;
    for (const file of files) {
      index++;
      progressCount.textContent = `第 ${index} / ${files.length} 张`;

      const item = await uploadOne(file);
      uploadedItems.push(item);
      renderResultCard(item);
    }

    progressText.textContent = '全部上传完成';
    PonyUtils.toast(`上传完成：成功 ${uploadedItems.length} 张`, 'success');
  }

  function uploadOne(file) {
    return new Promise((resolve) => {
      const fd = new FormData();
      fd.append('file', file);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', App.url('api/upload.php'));
      xhr.setRequestHeader('X-CSRF-Token', App.csrf);
      xhr.responseType = 'json';

      progressText.textContent = `正在上传：${file.name}`;

      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          const pct = Math.round((e.loaded / e.total) * 100);
          progressBar.style.width = pct + '%';
        }
      });

      xhr.addEventListener('load', () => {
        const res = xhr.response || { code: xhr.status || 500, msg: '上传失败（HTTP ' + xhr.status + '）' };
        if (res.code === 200) {
          progressBar.style.width = '100%';
          resolve(res.data);
        } else {
          PonyUtils.toast(`「${file.name}」${res.msg || '上传失败'}`, 'danger');
          resolve(null);
        }
      });

      xhr.addEventListener('error', () => {
        PonyUtils.toast(`「${file.name}」网络错误`, 'danger');
        resolve(null);
      });

      xhr.send(fd);
    });
  }

  /** 渲染单张上传结果卡片（安全：全部经 escapeHtml） */
  function renderResultCard(item) {
    if (!item) return;

    const card = document.createElement('div');
    card.className = 'card shadow-sm upload-result-card';
    card.innerHTML = `
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col-md-3 text-center">
            <img src="${escapeHtml(item.thumb_url)}" class="img-thumbnail" style="max-height:120px" alt="${escapeHtml(item.filename)}">
            <div class="small text-muted mt-1">${item.width} × ${item.height}</div>
          </div>
          <div class="col-md-9">
            <div class="fw-semibold small text-truncate mb-1">${escapeHtml(item.filename)}</div>
            <div class="text-muted small mb-2">${formatBytes(item.size)} · ${escapeHtml(item.upload_time)}</div>

            <div class="input-group input-group-sm mb-2">
              <span class="input-group-text" style="width:78px">URL</span>
              <input type="text" class="form-control font-monospace" readonly value="${escapeHtml(item.url)}">
              <button class="btn btn-outline-primary btn-copy-url" type="button"><i class="bi bi-clipboard"></i> 复制</button>
            </div>

            <div class="input-group input-group-sm mb-2">
              <span class="input-group-text" style="width:78px">Markdown</span>
              <input type="text" class="form-control font-monospace" readonly value="${escapeHtml('![image](' + item.url + ')')}">
              <button class="btn btn-outline-primary btn-copy-md" type="button"><i class="bi bi-clipboard"></i> 复制</button>
            </div>

            <div class="input-group input-group-sm">
              <span class="input-group-text" style="width:78px">删除密钥</span>
              <input type="text" class="form-control font-monospace" readonly value="${escapeHtml(item.delete_key)}">
              <button class="btn btn-outline-warning btn-copy-key" type="button"><i class="bi bi-clipboard"></i> 复制</button>
            </div>
            <div class="form-text">删除密钥仅显示一次，请立即保存</div>
          </div>
        </div>
      </div>`;
    results.prepend(card);

    card.querySelector('.btn-copy-url').addEventListener('click', () => App.copy(item.url));
    card.querySelector('.btn-copy-md').addEventListener('click', () => App.copy(`![image](${item.url})`));
    card.querySelector('.btn-copy-key').addEventListener('click', () => App.copy(item.delete_key, '删除密钥已复制'));
  }
}

/* ================= 管理页（我的图片） ================= */

function initManagePage() {
  const grid       = document.getElementById('imageGrid');
  const emptyState = document.getElementById('emptyState');
  const loading    = document.getElementById('loadingState');
  const pagination = document.getElementById('pagination');
  const summary    = document.getElementById('listSummary');
  const modalEl    = document.getElementById('modalDelete');

  const state = { page: 1, pages: 1, total: 0 };
  let pendingDelete = null;

  document.getElementById('btnRefresh')?.addEventListener('click', () => loadList(state.page));

  async function loadList(page = 1) {
    loading.classList.remove('d-none');
    emptyState.classList.add('d-none');
    grid.innerHTML = '';

    try {
      const data = await App.api(App.url(`api/list.php?page=${page}&per_page=20`));
      state.page = data.page; state.pages = data.pages; state.total = data.total;
      summary.textContent = `共 ${data.total} 张 · 第 ${data.page} / ${data.pages} 页`;

      loading.classList.add('d-none');

      if (!data.images?.length) {
        emptyState.classList.remove('d-none');
        pagination.innerHTML = '';
        return;
      }

      data.images.forEach((img) => grid.appendChild(renderCard(img)));
      renderPagination();
    } catch (err) {
      loading.classList.add('d-none');
      PonyUtils.toast(err.message, 'danger');
      if (err.code === 401) setTimeout(() => (location.href = App.url('login.php')), 1200);
    }
  }

  function renderCard(img) {
    const detailUrl = App.url('view.php?id=' + img.id);
    const col = document.createElement('div');
    col.className = 'col';
    col.innerHTML = `
      <div class="card image-card h-100">
        <a href="${detailUrl}" class="image-card-thumb">
          <img src="${escapeHtml(img.thumb_url)}" loading="lazy" alt="${escapeHtml(img.filename)}">
        </a>
        <div class="card-body p-2">
          <div class="small text-truncate fw-semibold" title="${escapeHtml(img.filename)}">${escapeHtml(img.filename)}</div>
          <div class="text-muted" style="font-size:.75rem">${formatBytes(img.size)} · ${escapeHtml(img.upload_time.slice(0, 16))}</div>
        </div>
        <div class="card-footer p-2 d-flex gap-1 bg-white border-top-0">
          <button class="btn btn-xs btn-outline-primary btn-copy-url" title="复制链接"><i class="bi bi-link-45deg"></i></button>
          <button class="btn btn-xs btn-outline-success btn-copy-md" title="复制 Markdown"><i class="bi bi-markdown"></i></button>
          <a class="btn btn-xs btn-outline-secondary" href="${detailUrl}" title="详情"><i class="bi bi-zoom-in"></i></a>
          <button class="btn btn-xs btn-outline-danger ms-auto btn-del" title="删除"><i class="bi bi-trash3"></i></button>
        </div>
      </div>`;

    col.querySelector('.btn-copy-url').addEventListener('click', () => App.copy(img.url));
    col.querySelector('.btn-copy-md').addEventListener('click', () => App.copy(`![image](${img.url})`));
    col.querySelector('.btn-del').addEventListener('click', () => askDelete(img));
    return col;
  }

  function askDelete(img) {
    pendingDelete = img;
    document.getElementById('delThumb').src = img.thumb_url;
    document.getElementById('delThumbWrap').classList.remove('d-none');
    document.getElementById('delTargetName').textContent = img.filename;
    document.getElementById('delKeyInput').value = '';
    // 已登录用户（所有者/管理员）无需输入密钥；游客必须提供
    document.getElementById('delKeyWrap').classList.toggle('d-none', App.loggedIn);
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  document.getElementById('delConfirmBtn').addEventListener('click', async () => {
    if (!pendingDelete) return;
    const body = { id: pendingDelete.id };
    if (!App.loggedIn) {
      const key = document.getElementById('delKeyInput').value.trim();
      if (!key) { PonyUtils.toast('请输入删除密钥', 'warning'); return; }
      body.delete_key = key;
    }
    try {
      await App.api(App.url('api/delete.php'), { method: 'POST', body });
      bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      PonyUtils.toast('已删除', 'success');
      loadList(state.page);
    } catch (err) {
      PonyUtils.toast(err.message, 'danger');
    }
  });

  function renderPagination() {
    const { page, pages } = state;
    if (pages <= 1) { pagination.innerHTML = ''; return; }

    const mk = (label, target, disabled, active) => `
      <li class="page-item ${disabled ? 'disabled' : ''} ${active ? 'active' : ''}">
        <button class="page-link" data-page="${target}">${label}</button>
      </li>`;

    let html = mk('上一页', page - 1, page <= 1, false);
    const start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
    if (start > 1) html += mk('1', 1, false, false);
    if (start > 2) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
    for (let p = start; p <= end; p++) html += mk(String(p), p, false, p === page);
    if (end < pages - 1) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
    if (end < pages) html += mk(String(pages), pages, false, false);
    html += mk('下一页', page + 1, page >= pages, false);

    pagination.innerHTML = `<ul class="pagination pagination-sm mb-0">${html}</ul>`;
    pagination.querySelectorAll('button[data-page]').forEach((btn) =>
      btn.addEventListener('click', () => loadList(Number(btn.dataset.page))));
  }

  loadList(1);
}

/* ================= 登录页 ================= */

function initLoginPage() {
  const form = document.getElementById('loginForm');
  const btn  = document.getElementById('loginSubmitBtn');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = document.getElementById('loginUsername').value.trim();
    const password = document.getElementById('loginPassword').value;
    if (!username || !password) { PonyUtils.toast('请输入用户名和密码', 'warning'); return; }

    btn.disabled = true;
    try {
      await App.api(App.url('api/auth.php?action=login'), { method: 'POST', body: { username, password } });
      PonyUtils.toast('登录成功，正在跳转…', 'success');
      const redirect = new URLSearchParams(location.search).get('redirect');
      setTimeout(() => {
        location.href = redirect === 'admin'
          ? App.url('admin/')
          : App.url('index.php?p=manage');
      }, 600);
    } catch (err) {
      PonyUtils.toast(err.message, 'danger');
      btn.disabled = false;
    }
  });
}

/* ================= 注册页 ================= */

function initRegisterPage() {
  const form = document.getElementById('registerForm');
  const btn  = document.getElementById('registerSubmitBtn');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const username = document.getElementById('regUsername').value.trim();
    const password = document.getElementById('regPassword').value;
    const confirm  = document.getElementById('regConfirmPassword').value;
    const email    = document.getElementById('regEmail').value.trim();

    // 前端二次校验（后端也有校验，此处提升体验）
    if (!/^[A-Za-z0-9_\-]{3,50}$/.test(username)) {
      PonyUtils.toast('用户名只能包含字母、数字、下划线和连字符，3-50 位', 'warning');
      return;
    }
    if (password.length < 8 || password.length > 72) {
      PonyUtils.toast('密码长度需为 8-72 位', 'warning');
      return;
    }
    if (password !== confirm) {
      PonyUtils.toast('两次输入的密码不一致', 'warning');
      return;
    }

    btn.disabled = true;
    const body = { username, password, confirm_password: confirm };
    if (email) body.email = email;

    try {
      await App.api(App.url('api/auth.php?action=register'), { method: 'POST', body });
      PonyUtils.toast('注册成功，正在跳转…', 'success');
      setTimeout(() => { location.href = App.url('index.php?p=manage'); }, 800);
    } catch (err) {
      PonyUtils.toast(err.message, 'danger');
      btn.disabled = false;
    }
  });
}

/* ================= 单图预览页 ================= */

function initViewPage() {
  document.querySelectorAll('.btn-copy').forEach((btn) =>
    btn.addEventListener('click', () => {
      const target = document.getElementById(btn.dataset.target);
      if (target) App.copy(target.value);
    }));
}

/* ================= 管理面板 ================= */

function initAdminPage() {
  /* ---- 统计 ---- */
  App.api(App.url('api/admin.php?action=stats'))
    .then((d) => {
      document.getElementById('statImages').textContent = d.images;
      document.getElementById('statSize').textContent = formatBytes(d.total_size);
      document.getElementById('statUsers').textContent = d.users;
    })
    .catch((e) => PonyUtils.toast(e.message, 'danger'));

  /* ---- 图片列表 ---- */
  const adminGrid = document.getElementById('adminImageGrid');
  const adminPagination = document.getElementById('adminPagination');
  const userFilter = document.getElementById('adminUserFilter');
  const state = { page: 1, pages: 1 };
  let pendingDelete = null;

  async function loadImages(page = 1) {
    adminGrid.innerHTML = '';
    try {
      const uid = userFilter.value;
      const data = await App.api(App.url(`api/admin.php?action=images&page=${page}&per_page=20&user_id=${uid}`));
      state.page = data.page; state.pages = data.pages;
      document.getElementById('adminListSummary').textContent = `共 ${data.total} 张 · 第 ${data.page} / ${data.pages} 页`;

      if (!data.images?.length) {
        adminGrid.innerHTML = '<div class="col-12 text-center text-muted py-5">暂无图片</div>';
        adminPagination.innerHTML = '';
        return;
      }
      data.images.forEach((img) => {
        const detailUrl = App.url('view.php?id=' + img.id);
        const col = document.createElement('div');
        col.className = 'col';
        col.innerHTML = `
          <div class="card image-card h-100">
            <a href="${detailUrl}" class="image-card-thumb">
              <img src="${escapeHtml(img.thumb_url)}" loading="lazy" alt="${escapeHtml(img.filename)}">
            </a>
            <div class="card-body p-2">
              <div class="small text-truncate fw-semibold" title="${escapeHtml(img.filename)}">${escapeHtml(img.filename)}</div>
              <div class="text-muted" style="font-size:.75rem">
                ${formatBytes(img.size)} · ${escapeHtml(img.upload_time.slice(0, 16))}
              </div>
              <div class="text-muted" style="font-size:.75rem">
                <i class="bi bi-person me-1"></i>${escapeHtml(img.username || '游客')}
              </div>
            </div>
            <div class="card-footer p-2 d-flex gap-1 bg-white border-top-0">
              <button class="btn btn-xs btn-outline-primary btn-cp" title="复制链接"><i class="bi bi-link-45deg"></i></button>
              <a class="btn btn-xs btn-outline-secondary" href="${detailUrl}" title="详情"><i class="bi bi-zoom-in"></i></a>
              <button class="btn btn-xs btn-outline-danger ms-auto btn-del" title="删除"><i class="bi bi-trash3"></i></button>
            </div>
          </div>`;
        col.querySelector('.btn-cp').addEventListener('click', () => App.copy(img.url));
        col.querySelector('.btn-del').addEventListener('click', () => {
          pendingDelete = img;
          document.getElementById('delTargetName').textContent = img.filename;
          bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDelete')).show();
        });
        adminGrid.appendChild(col);
      });
      renderAdminPagination();
    } catch (err) { PonyUtils.toast(err.message, 'danger'); }
  }

  function renderAdminPagination() {
    const { page, pages } = state;
    if (pages <= 1) { adminPagination.innerHTML = ''; return; }
    let html = page <= 1 ? '' : `<li class="page-item"><button class="page-link" data-p="${page - 1}">上一页</button></li>`;
    html += `<li class="page-item active"><span class="page-link">${page} / ${pages}</span></li>`;
    if (page < pages) html += `<li class="page-item"><button class="page-link" data-p="${page + 1}">下一页</button></li>`;
    adminPagination.innerHTML = `<ul class="pagination pagination-sm mb-0">${html}</ul>`;
    adminPagination.querySelectorAll('button[data-p]').forEach((b) =>
      b.addEventListener('click', () => loadImages(Number(b.dataset.p))));
  }

  document.getElementById('delConfirmBtn').addEventListener('click', async () => {
    if (!pendingDelete) return;
    try {
      await App.api(App.url('api/delete.php'), { method: 'POST', body: { id: pendingDelete.id } });
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDelete')).hide();
      PonyUtils.toast('已删除', 'success');
      loadImages(state.page);
    } catch (err) { PonyUtils.toast(err.message, 'danger'); }
  });

  userFilter.addEventListener('change', () => loadImages(1));
  document.getElementById('adminRefreshBtn').addEventListener('click', () => loadImages(state.page));

  /* ---- 用户管理 ---- */
  const tbody = document.getElementById('userTableBody');
  let pendingResetUser = null;

  async function loadUsers() {
    try {
      const data = await App.api(App.url('api/admin.php?action=users'));

      // 刷新筛选下拉
      userFilter.innerHTML = '<option value="0">全部用户</option>' + data.users
        .map((u) => `<option value="${u.id}">${escapeHtml(u.username)}</option>`).join('');
      userFilter.value = userFilter.dataset.keep || '0';

      tbody.innerHTML = data.users.map((u) => `
        <tr>
          <td>${u.id}</td>
          <td class="fw-semibold">${escapeHtml(u.username)}${u.role === 'admin' ? ' <span class="badge text-bg-primary">admin</span>' : ''}</td>
          <td class="text-muted">${escapeHtml(u.email || '-')}</td>
          <td>${escapeHtml(u.role)}</td>
          <td class="text-muted">${escapeHtml(String(u.created_at).slice(0, 16))}</td>
          <td class="text-end">
            <button class="btn btn-xs btn-outline-primary btn-rp" data-id="${u.id}" data-name="${escapeHtml(u.username)}" title="重置密码"><i class="bi bi-key"></i></button>
            <button class="btn btn-xs btn-outline-danger btn-du" data-id="${u.id}" data-name="${escapeHtml(u.username)}" title="删除用户"><i class="bi bi-person-x"></i></button>
          </td>
        </tr>`).join('');

      tbody.querySelectorAll('.btn-rp').forEach((b) => b.addEventListener('click', () => {
        pendingResetUser = { id: b.dataset.id, name: b.dataset.name };
        document.getElementById('rpUsername').textContent = pendingResetUser.name;
        document.getElementById('rpPassword').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalResetPwd')).show();
      }));

      tbody.querySelectorAll('.btn-du').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(`确定删除用户「${b.dataset.name}」？其图片将保留并转为无主。`)) return;
        try {
          await App.api(App.url('api/admin.php?action=delete_user'), { method: 'POST', body: { id: Number(b.dataset.id) } });
          PonyUtils.toast('用户已删除', 'success');
          loadUsers();
        } catch (err) { PonyUtils.toast(err.message, 'danger'); }
      }));
    } catch (err) { PonyUtils.toast(err.message, 'danger'); }
  }

  document.getElementById('genPasswordBtn')?.addEventListener('click', () => {
    document.getElementById('cuPassword').value = PonyUtils.randomPassword();
  });
  document.getElementById('rpGenBtn')?.addEventListener('click', () => {
    document.getElementById('rpPassword').value = PonyUtils.randomPassword();
  });

  document.getElementById('createUserForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = {
      username: document.getElementById('cuUsername').value.trim(),
      password: document.getElementById('cuPassword').value,
      email: document.getElementById('cuEmail').value.trim(),
      role: document.getElementById('cuRole').value,
    };
    try {
      await App.api(App.url('api/admin.php?action=create_user'), { method: 'POST', body });
      PonyUtils.toast('用户创建成功', 'success');
      e.target.reset();
      loadUsers();
    } catch (err) { PonyUtils.toast(err.message, 'danger'); }
  });

  document.getElementById('rpConfirmBtn').addEventListener('click', async () => {
    if (!pendingResetUser) return;
    const pwd = document.getElementById('rpPassword').value;
    if (pwd.length < 8) { PonyUtils.toast('密码至少 8 位', 'warning'); return; }
    try {
      await App.api(App.url('api/admin.php?action=reset_password'), {
        method: 'POST',
        body: { id: Number(pendingResetUser.id), password: pwd },
      });
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalResetPwd')).hide();
      PonyUtils.toast('密码已重置', 'success');
    } catch (err) { PonyUtils.toast(err.message, 'danger'); }
  });

  // 首次进入用户管理标签时加载
  document.getElementById('tabUsersBtn').addEventListener('click', () => { loadUsers(); loadImages(1); });

  loadImages(1);
}
