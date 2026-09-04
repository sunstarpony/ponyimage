# PonyImage

自托管、轻量的个人 / 小团队图床程序。代码完全自研，不依赖 Laravel / ThinkPHP 等框架：前端 Bootstrap 5 + 原生 JavaScript，后端原生 PHP 8 + PDO，支持 MySQL 与 SQLite。

## 功能特性

- 拖拽 / 多选批量上传，实时进度条
- 按 年/月 分目录存储，随机文件名
- GD 等比缩略图（保留 PNG/GIF/WebP 透明通道）
- 每张图片独立 32 位删除密钥（仅上传时返回一次）
- 单图预览页（URL / Markdown / HTML / BBCode 一键复制）
- 单用户（免登录）与多用户两种模式，用户只能管理自己的图片
- 管理面板：站点统计、全部图片管理、用户管理（创建/删除/重置密码）
- 固定 API Token，供外部程序（脚本、图床客户端）调用
- 文件上传多重校验：扩展名白名单 + finfo 内容检测 + getimagesize 交叉验证
- PDO 全预处理、CSP / CSRF / XSS 转义 / 登录限流 / 上传限流 / 目录穿越防护

## 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 8.0+（推荐 8.2），CLI 与 FPM |
| 扩展 | pdo_mysql 或 pdo_sqlite、gd、fileinfo、mbstring、json、session |
| 数据库 | MySQL 5.7+ 或 SQLite 3 |
| Web 服务器 | Nginx（推荐，附配置）或 Apache |

## 快速部署（Nginx + SQLite，3 分钟）

### 方式一：网页安装向导（推荐）

```bash
# 1. 放置代码
sudo mkdir -p /var/www/ponyimage
sudo cp -r . /var/www/ponyimage
cd /var/www/ponyimage

# 2. 权限（Web 用户示例为 www-data）
sudo chown -R www-data:www-data uploads thumbs runtime db config
sudo chmod 755 uploads thumbs && sudo chmod 750 runtime db

# 3. 安装 PHP 扩展（Debian/Ubuntu 示例）
sudo apt install php-fpm php-gd php-mbstring php-sqlite3 php-mysql

# 4. Nginx 配置
sudo cp nginx.conf /etc/nginx/conf.d/ponyimage.conf
sudo vim /etc/nginx/conf.d/ponyimage.conf   # 改 server_name / root / fpm socket
sudo nginx -t && sudo systemctl reload nginx

# 5. 浏览器访问 https://你的域名/install.php
#    向导会自动完成：环境检查 → 填写站点/数据库/管理员 → 建表 → 生成配置
# 6. 安装完成后立即删除向导文件（向导也会自锁，但删除更安全）
sudo rm /var/www/ponyimage/install.php
```

### 方式二：命令行手工安装

```bash
cp config/config.example.php config/config.php
vim config/config.php      # 修改 app_url；多用户模式将 multi_user 改为 true

# SQLite 模式表结构会在首次访问时自动创建
# MySQL 模式先导入 schema.sql：
mysql -u root -p -e "CREATE DATABASE ponyimage DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p ponyimage < schema.sql

# （多用户模式）创建管理员
php tools/create_admin.php admin 'YourStrongPassword' admin@example.com
```

## API 一览

所有响应统一为 `{ code, msg, data }`，`code=200` 表示成功。

| 方法 | 路径 | 说明 | 认证 |
|---|---|---|---|
| POST | `/api/upload.php` | 上传图片（multipart 字段 `file`） | 公开 / Session+CSRF / Token |
| GET | `/api/list.php?page=&per_page=` | 图片列表（默认每页 20，最大 100） | 视配置 |
| POST | `/api/delete.php` | 删除（`id` + `delete_key`，或会话内直接删） | delete_key 或会话 |
| GET | `/api/info.php?id=` | 单图信息 | 视配置 |
| POST | `/api/auth.php?action=login` | 登录（`username` / `password`） | CSRF |
| POST | `/api/auth.php?action=logout` | 退出登录 | CSRF |
| GET | `/api/auth.php?action=me` | 当前用户信息 | - |
| GET/POST | `/api/admin.php?action=...` | 管理接口（stats/images/users/create_user/delete_user/reset_password） | admin |

外部程序调用示例（需在 `config.php` 的 `api_tokens` 中配置 Token）：

```bash
curl -F "file=@photo.jpg" -F "token=YOUR_API_TOKEN" https://image.example.com/api/upload.php
```

## 目录结构

```
/
├─ index.php            # 页面入口（上传/管理/关于），render_page() 统一渲染
├─ login.php            # 登录 / 注册页（多用户模式）
├─ view.php             # 单图预览页
├─ api/                 # JSON API（方法/CSRF/认证/限流走 Api 类单行声明）
├─ admin/               # 管理面板（仅 admin）
├─ config/              # 配置、数据库连接、表结构定义（schema.php 唯一来源）
├─ includes/            # 引导与核心类（spl_autoload 自动加载）
│   ├─ Api.php          # API 生命周期：requireMethod/requireCsrf/requireAdmin/rateLimit
│   ├─ Auth.php         # 认证、会话、CSRF、Token
│   ├─ ImageManager.php # 上传校验、存储、删除、API 行转换
│   ├─ Thumbnail.php    # GD 缩略图生成
│   ├─ RateLimiter.php  # 文件型限流器
│   └─ helpers.php      # 通用函数（含 render_page / paginate_query）
├─ views/               # 视图片段（layout.php 为布局壳）
├─ assets/              # Bootstrap 5 与自定义 JS/CSS（本地化）
├─ uploads/{Y}/{m}/     # 原图存储
├─ thumbs/{Y}/{m}/      # 缩略图存储
├─ runtime/             # 日志 / 会话 / 限流数据
├─ db/                  # SQLite 数据库
├─ tools/               # 命令行工具
├─ schema.sql           # MySQL 建表脚本（与 config/schema.php 同步）
└─ nginx.conf           # Nginx 配置示例
```

## 安全清单（生产环境自查）

- [ ] `config.php` 中 `debug` 保持 `false`
- [ ] `app_url` 使用 HTTPS 地址并部署 TLS 证书
- [ ] Nginx 按项目附带的 `nginx.conf` 配置（uploads/thumbs 禁 PHP、敏感目录 404）
- [ ] 若 Nginx 前另有代理，才开启 `trust_proxy`，否则保持 `false`
- [ ] `php.ini`：`upload_max_filesize` / `post_max_size` ≥ `client_max_body_size` ≥ `max_file_size`
- [ ] 多用户模式：`multi_user=true`、`allow_public_upload=false`
- [ ] 私有图床：`allow_public_list=false`
- [ ] `runtime/`、`db/` 目录权限 750，属主为 PHP-FPM 运行用户
- [ ] 定期备份 `db/`（或 MySQL）与 `uploads/` 目录

## 命令行工具

```bash
php tools/create_admin.php <用户名> <密码> [邮箱]     # 创建管理员
php tools/create_admin.php --reset <用户名> <新密码>   # 重置密码
```
