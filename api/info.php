<?php
/**
 * API: 单张图片信息
 *
 * GET /api/info.php?id=123
 *
 * 可见性:
 *   - 公开列表模式（allow_public_list=true）下任何人可查看（供单图预览页使用）
 *   - 私有模式下仅图片所有者或管理员可见
 * 返回数据不包含 delete_key 与 IP 等敏感字段
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Api::requireMethod('GET');

$row = Api::requireImage(request_int('id', 0, 1));
Api::requireImageVisible($row);

$data = ImageManager::toApiRow($row);
$data['mime_type'] = (string)$row['mime_type'];

json_out(200, 'ok', $data);
