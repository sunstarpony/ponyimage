<?php
/**
 * PonyImage 配置模板
 *
 * 部署时执行：cp config/config.example.php config/config.php
 * 然后按需修改下方各项。生产环境务必修改 app_url 与数据库密码。
 */

return [
    /* ---------------- 站点基本设置 ---------------- */

    // 站点名称（显示在侧边栏与页面标题）
    'app_name' => 'PonyImage',

    // 站点访问地址，末尾不带斜杠，用于生成图片完整 URL
    'app_url'  => 'https://image.example.com',

    // 时区
    'timezone' => 'Asia/Shanghai',

    /* ---------------- 运行模式 ---------------- */

    // 多用户模式：开启后需要登录才能上传/管理，用户只能管理自己的图片
    'multi_user' => false,

    // 是否允许未登录用户上传（单用户图床设为 true；多用户模式请设为 false）
    'allow_public_upload' => true,

    // 是否允许未登录用户查看图片列表（公开图床设为 true，私有图床设为 false）
    'allow_public_list'   => true,

    // 是否允许自助注册（多用户模式下生效；开启后登录页显示"注册"入口）
    'allow_register'      => false,

    // 注册接口限流：每个 IP 在该时间窗口（秒）内最多 3 次注册
    'register_rate_limit' => 3600,

    /* ---------------- 存储设置 ---------------- */

    // 原图存储目录（相对站点根目录）
    'storage_path' => 'uploads/',

    // 缩略图存储目录（相对站点根目录）
    'thumb_path'   => 'thumbs/',

    // 最大文件大小（字节），默认 10MB；需同步调整 Nginx client_max_body_size 与 php.ini upload_max_filesize
    'max_file_size' => 10485760,

    // 允许的图片扩展名白名单（扩展名与真实 MIME 都必须匹配才允许上传）
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

    /* ---------------- 数据库设置 ---------------- */

    // 数据库类型：mysql 或 sqlite
    'db_type' => 'sqlite',

    // ---- MySQL 连接信息（db_type = mysql 时使用）----
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'ponyimage',
    'db_user' => 'ponyimage',
    'db_pass' => 'change-me',

    // ---- SQLite 数据库文件路径（db_type = sqlite 时使用）----
    'db_file' => __DIR__ . '/../db/ponyimage.db',

    /* ---------------- 缩略图设置 ---------------- */

    // 是否生成缩略图（需要 GD 扩展）
    'enable_thumb'  => true,

    // 缩略图最大宽度 / 高度（等比缩放，不裁剪）
    'thumb_width'   => 320,
    'thumb_height'  => 320,

    /* ---------------- 安全设置 ---------------- */

    // Session 过期时间（秒），闲置超过该时长自动登出
    'session_expire' => 3600,

    // 固定 API Token 列表，供外部程序（如 PicGo、脚本）调用上传接口使用。
    // 留空数组表示禁用 Token 认证。Token 调用方式：
    //   POST /api/upload.php ，表单字段 token=<值> 或请求头 Authorization: Bearer <值>
    'api_tokens' => [
        // '为每个客户端生成一个足够长的随机串',
    ],

    // 上传接口限流：每个 IP 每 60 秒最多上传的次数
    'upload_rate_limit' => 30,

    // 登录接口限流：每个 IP 每 900 秒（15 分钟）最多尝试登录的次数
    'login_rate_limit' => 5,

    // 注册接口限流：每个 IP 在该时间窗口（秒）内最多 3 次注册
    'register_rate_limit' => 3600,

    // 是否信任反向代理传递的 X-Forwarded-For 头（仅在 Nginx 前置且未传递客户端伪造头时开启）
    'trust_proxy' => false,

    // 调试模式：生产环境必须为 false（关闭后错误只写入日志，不展示给访问者）
    'debug' => false,
];
