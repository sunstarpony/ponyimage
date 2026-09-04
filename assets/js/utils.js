/**
 * PonyImage 工具函数库
 * 全局命名空间：PonyUtils
 */
'use strict';

const PonyUtils = {

  /** 字节数 → 人类可读 */
  formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) return '-';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0, size = bytes;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return (i === 0 ? Math.round(size) : size.toFixed(2)) + ' ' + units[i];
  },

  /** SQL 时间 → 本地友好显示 */
  formatTime(sqlTime) {
    if (!sqlTime) return '-';
    const t = new Date(String(sqlTime).replace(' ', 'T'));
    if (Number.isNaN(t.getTime())) return sqlTime;
    return t.toLocaleString('zh-CN', { hour12: false });
  },

  /** HTML 转义（前端渲染动态内容统一使用） */
  escapeHtml(str) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(str ?? '').replace(/[&<>"']/g, (c) => map[c]);
  },

  /** 复制文本到剪贴板（优先 Clipboard API，降级 execCommand） */
  async copyText(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (_) { /* 降级 */ }
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;left:-9999px;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      ta.remove();
      return ok;
    } catch (_) {
      return false;
    }
  },

  /** Bootstrap Toast 提示 */
  toast(message, type = 'info', delay = 2600) {
    const container = document.getElementById('toastContainer');
    if (!container) { alert(message); return; }

    const icons = { success: 'check-circle-fill', danger: 'exclamation-triangle-fill', warning: 'exclamation-circle-fill', info: 'info-circle-fill' };
    const icon = icons[type] || icons.info;

    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${type === 'danger' ? 'danger' : type} border-0`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `
      <div class="d-flex">
        <div class="toast-body"><i class="bi bi-${icon} me-1"></i>${this.escapeHtml(message)}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="关闭"></button>
      </div>`;

    container.appendChild(el);
    const toast = new bootstrap.Toast(el, { delay });
    toast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  },

  /** 防抖 */
  debounce(fn, wait = 300) {
    let timer = null;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), wait);
    };
  },

  /** 生成随机密码（创建用户 / 重置密码用） */
  randomPassword(len = 14) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    const arr = new Uint32Array(len);
    crypto.getRandomValues(arr);
    return Array.from(arr, (n) => chars[n % chars.length]).join('');
  },
};

/* 供调用方直接使用的快捷别名 */
const formatBytes = (b) => PonyUtils.formatBytes(b);
const formatTime = (t) => PonyUtils.formatTime(t);
const escapeHtml = (s) => PonyUtils.escapeHtml(s);
