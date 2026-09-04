-- ============================================================
-- PonyImage MySQL 数据库结构
-- 使用方式:
--   mysql -u root -p ponyimage < schema.sql
--   (先创建数据库: CREATE DATABASE ponyimage DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;)
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 图片表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `images` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `filename`    VARCHAR(255) NOT NULL                COMMENT '上传时的原始文件名',
    `saved_name`  VARCHAR(255) NOT NULL                COMMENT '服务器保存的随机文件名',
    `file_path`   VARCHAR(500) NOT NULL                COMMENT '相对站点根目录的存储路径',
    `thumb_path`  VARCHAR(500) NOT NULL DEFAULT ''     COMMENT '缩略图路径，可为空',
    `file_size`   INT(11)      NOT NULL DEFAULT 0      COMMENT '文件大小（字节）',
    `mime_type`   VARCHAR(50)  NOT NULL DEFAULT ''     COMMENT '图片 MIME 类型',
    `file_width`  INT(6)       NOT NULL DEFAULT 0      COMMENT '宽度（px）',
    `file_height` INT(6)       NOT NULL DEFAULT 0      COMMENT '高度（px）',
    `delete_key`  VARCHAR(32)  NOT NULL                COMMENT '删除密钥（仅上传时返回一次）',
    `user_id`     INT(11)      NOT NULL DEFAULT 0      COMMENT '归属用户，0 为游客',
    `upload_time` DATETIME     NOT NULL                COMMENT '上传时间',
    `ip_address`  VARCHAR(45)  NOT NULL DEFAULT ''     COMMENT '上传者 IP（日志/防滥用）',
    `user_agent`  VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '上传者 UA',
    PRIMARY KEY (`id`),
    KEY `idx_images_user_id` (`user_id`),
    KEY `idx_images_upload_time` (`upload_time` DESC)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '图片记录表';

-- ------------------------------------------------------------
-- 用户表（多用户模式）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(50)  NOT NULL                COMMENT '用户名',
    `password`   VARCHAR(255) NOT NULL                COMMENT '密码哈希（password_hash）',
    `email`      VARCHAR(100) NOT NULL DEFAULT ''     COMMENT '邮箱',
    `role`       VARCHAR(20)  NOT NULL DEFAULT 'user' COMMENT '角色 admin/user',
    `created_at` DATETIME     NOT NULL                COMMENT '注册时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '用户表';
