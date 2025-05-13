<?php

// 校验房源记录是否包含必要字段：unique_id、address和有效的 images 列表
function jiwu_import_validate_listing($listing)
{
    // 检查 unique_id 是否存在且非空
    if (empty($listing->unique_id)) {
        return false;
    }
    // 检查 address 是否存在且非空
    if (empty($listing->address)) {
        return false;
    }
    // 检查 images 字段是否存在且不为空
    if (!isset($listing->images) || $listing->images === '') {
        return false;
    }
    // 如果 images 为字符串，则尝试解析 JSON，确保其为数组格式
    $imgs = $listing->images;
    if (is_string($imgs)) {
        $decoded_imgs = json_decode($imgs, true);
        if ($decoded_imgs !== null) {
            $imgs = $decoded_imgs;
        }
    }
    // 检查 images 是否为非空数组
    if (!is_array($imgs) || count($imgs) === 0) {
        return false;
    }

    // 通过以上检查则认为记录有效
    return true;
}

