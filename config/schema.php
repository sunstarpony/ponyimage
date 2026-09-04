<?php
/**
 * PonyImage 数据库表结构定义（唯一权威来源）
 *
 * 被 config/database.php（SQLite 自动初始化）与 install.php（安装向导建表）共用。
 * 修改表结构只需改本文件；schema.sql 仅为 MySQL 手动导入提供的纯 SQL 副本。
 *
 * 语句按分号拆分逐条执行，禁止在注释外的 SQL 中出现分号。
 */

declare(strict_types=1);

return [
    'sqlite' => [
        // 建表
        <<<'SQL'
CREATE TABLE IF NOT EXISTS images (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    filename     TEXT    NOT NULL,
    saved_name   TEXT    NOT NULL,
    file_path    TEXT    NOT NULL,
    thumb_path   TEXT    NOT NULL DEFAULT '',
    file_size    INTEGER NOT NULL DEFAULT 0,
    mime_type    TEXT    NOT NULL DEFAULT '',
    file_width   INTEGER NOT NULL DEFAULT 0,
    file_height  INTEGER NOT NULL DEFAULT 0,
    delete_key   TEXT    NOT NULL,
    user_id      INTEGER NOT NULL DEFAULT 0,
    upload_time  TEXT    NOT NULL,
    ip_address   TEXT    NOT NULL DEFAULT '',
    user_agent   TEXT    NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT    NOT NULL UNIQUE,
    password   TEXT    NOT NULL,
    email      TEXT    NOT NULL DEFAULT '',
    role       TEXT    NOT NULL DEFAULT 'user',
    created_at TEXT    NOT NULL
)
SQL,
        // 索引（分开执行，避免与建表串在一个语句里）
        <<<'SQL'
CREATE INDEX IF NOT EXISTS idx_images_user_id ON images (user_id);
CREATE INDEX IF NOT EXISTS idx_images_upload_time ON images (upload_time DESC)
SQL,
    ],

    'mysql' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `images` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `filename` VARCHAR(255) NOT NULL,
    `saved_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `thumb_path` VARCHAR(500) NOT NULL DEFAULT '',
    `file_size` INT(11) NOT NULL DEFAULT 0,
    `mime_type` VARCHAR(50) NOT NULL DEFAULT '',
    `file_width` INT(6) NOT NULL DEFAULT 0,
    `file_height` INT(6) NOT NULL DEFAULT 0,
    `delete_key` VARCHAR(32) NOT NULL,
    `user_id` INT(11) NOT NULL DEFAULT 0,
    `upload_time` DATETIME NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_images_user_id` (`user_id`),
    KEY `idx_images_upload_time` (`upload_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(50)  NOT NULL,
    `password`   VARCHAR(255) NOT NULL,
    `email`      VARCHAR(100) NOT NULL DEFAULT '',
    `role`       VARCHAR(20)  NOT NULL DEFAULT 'user',
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
