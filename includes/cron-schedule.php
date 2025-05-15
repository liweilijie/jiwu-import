<?php

// 定义 WP Cron 时间间隔（每5分钟）
function jiwu_import_cron_interval($schedules)
{
    // 如果未定义 five_minutes 时间表，则添加它
    if (!isset($schedules['five_minutes'])) {
        $schedules['five_minutes'] = array(
            'interval' => 60, // 300秒，即5分钟
            'display' => __('Every 5 Minutes', 'jiwu-import') // 时间间隔名称
        );
    }
    return $schedules;
}

// 插件激活时调用：安排每5分钟执行一次的定时事件
function jiwu_import_activate()
{
    // 如果尚未安排我们的事件，则使用自定义间隔安排事件
    if (!wp_next_scheduled('jiwu_import_cron_event')) {
        wp_schedule_event(time(), 'five_minutes', 'jiwu_import_cron_event');
    }
}

// 插件停用时调用：清除定时事件
function jiwu_import_deactivate()
{
    // 移除所有已安排的 jiwu_import_cron_event 事件
    wp_clear_scheduled_hook('jiwu_import_cron_event');
}


