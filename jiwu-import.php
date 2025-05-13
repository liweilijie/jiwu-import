<?php
/**
 * Plugin Name: jiwu-import
 * Description: 后台定时从自定义表导入房源数据的插件
 * Version: 1.0
 * Author: Li Wei
 */

// 如果直接访问插件文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件目录常量，方便引用其他文件
define('JIWU_IMPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));

// print
error_log(print_r(JIWU_IMPORT_PLUGIN_DIR, true));

$import_handler_path = JIWU_IMPORT_PLUGIN_DIR . 'includes/import-handler.php';
if (file_exists($import_handler_path)) {
    require_once $import_handler_path;
} else {
    error_log("文件不存在: " . $import_handler_path);
}

if ( ! class_exists( 'EXMAGE_WP_IMAGE_LINKS' ) ) {
    include_once WP_PLUGIN_DIR . '/exmage-wp-image-links/exmage-wp-image-links.php';
}


// 引入插件的各功能模块文件
require_once JIWU_IMPORT_PLUGIN_DIR . 'includes/cron-schedule.php';
require_once JIWU_IMPORT_PLUGIN_DIR . 'includes/import-handler.php';
require_once JIWU_IMPORT_PLUGIN_DIR . 'includes/data-validator.php';

// 添加 WP-Cron 自定义时间间隔（每5分钟执行一次）
add_filter('cron_schedules', 'jiwu_import_cron_interval');

// 注册插件激活和停用钩子，用于设置和清理定时事件
register_activation_hook(__FILE__, 'jiwu_import_activate');
register_deactivation_hook(__FILE__, 'jiwu_import_deactivate');

// 将导入任务函数绑定到 WP-Cron 事件钩子
add_action('jiwu_import_cron_event', 'jiwu_import_cron_task');
?>