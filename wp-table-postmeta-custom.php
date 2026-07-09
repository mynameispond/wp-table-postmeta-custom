<?php
/**
 * Plugin Name: WP Table Postmeta Custom
 * Description: จัดการหลายตาราง postmeta แบบกำหนด slug ได้ พร้อมรองรับ meta_query_wppc-{table_slug} ใน WP_Query
 * Version: 1.0.0
 * Author: Peera
 * Text Domain: wp-table-postmeta-custom
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPPC_VERSION', '1.0.0');
define('WPPC_TABLE_REGISTRY_OPTION', 'wppc_table_registry');
define('WPPC_SYNC_STATE_OPTION', 'wppc_sync_state');

require_once plugin_dir_path(__FILE__) . 'includes/db-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-views.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax-actions.php';

add_filter('posts_where', 'wppc_filter_posts_where_for_meta_query_wppc', 10, 2);
add_action('admin_notices', 'wppc_render_admin_notice');
add_action('admin_enqueue_scripts', 'wppc_enqueue_admin_assets');

function wppc_enqueue_admin_assets()
{
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $allowed_pages = wppc_get_admin_pages();
    if (!in_array($page, $allowed_pages, true)) {
        return;
    }

    wp_enqueue_style(
        'wppc-admin-css',
        plugin_dir_url(__FILE__) . 'assets/wppc-admin.css',
        array(),
        WPPC_VERSION
    );

    wp_enqueue_script(
        'wppc-admin-js',
        plugin_dir_url(__FILE__) . 'assets/wppc-admin.js',
        array('jquery'),
        WPPC_VERSION,
        true
    );

    wp_localize_script('wppc-admin-js', 'wppc_params', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'active_slug' => wppc_get_admin_active_slug(),
        'nonces' => array(
            'create_table' => wp_create_nonce('wppc_create_table'),
            'delete_table' => wp_create_nonce('wppc_delete_table'),
            'save_record' => wp_create_nonce('wppc_save_record'),
            'delete_record' => wp_create_nonce('wppc_delete_record'),
            'bulk_delete' => wp_create_nonce('wppc_bulk_delete'),
            'truncate_table' => wp_create_nonce('wppc_truncate_table'),
            'import_data' => wp_create_nonce('wppc_import_data'),
            'get_data_table' => wp_create_nonce('wppc_get_data_table'),
            'get_table_types' => wp_create_nonce('wppc_get_table_types'),
        )
    ));
}

function wppc_handle_admin_actions()
{
    global $wpdb;
    if (!is_admin() || !current_user_can('manage_options') || !wppc_is_wppc_admin_page()) {
        return;
    }

    $action = isset($_REQUEST['wppc_action']) ? sanitize_key(wp_unslash($_REQUEST['wppc_action'])) : '';
    if ($action === '') {
        return;
    }

    $slug = wppc_get_admin_active_slug();
    $slugs = wppc_get_registered_slugs();

    switch ($action) {
        case 'create_table':
            check_admin_referer('wppc_create_table');
            $new_slug = isset($_POST['new_table_slug']) ? wppc_normalize_slug(wp_unslash($_POST['new_table_slug'])) : '';
            if ($new_slug === '') {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'รหัสตารางไม่ถูกต้อง');
            }
            if (in_array($new_slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'รหัสตารางนี้มีอยู่แล้ว');
            }
            $result = wppc_create_meta_table($new_slug);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', $result->get_error_message());
            }
            wppc_admin_redirect_with_notice('wppc-table-types', 'success', 'สร้างตารางเรียบร้อย', array('table' => $new_slug));
            break;

        case 'delete_table':
            check_admin_referer('wppc_delete_table');
            $delete_slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
            if ($delete_slug === '') {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'รหัสตารางไม่ถูกต้อง');
            }
            if (!in_array($delete_slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'ไม่พบตารางที่เลือก');
            }
            $result = wppc_drop_meta_table($delete_slug);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', $result->get_error_message());
            }
            wppc_admin_redirect_with_notice('wppc-table-types', 'success', 'ลบตารางเรียบร้อย');
            break;

        case 'save_record':
            check_admin_referer('wppc_save_record');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }

            $meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $meta_key = isset($_POST['meta_key']) ? wppc_normalize_meta_key(wp_unslash($_POST['meta_key'])) : '';
            $meta_value = isset($_POST['meta_value']) ? wp_unslash($_POST['meta_value']) : '';
            if ($post_id <= 0 || $meta_key === '') {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'กรุณากรอก post_id และ meta_key ให้ครบ', array('table' => $slug));
            }

            if (!wppc_table_exists($slug)) {
                $created = wppc_create_meta_table($slug);
                if (is_wp_error($created)) {
                    wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $created->get_error_message(), array('table' => $slug));
                }
            }

            $table_name = wppc_get_table_name($slug);
            $stored_value = wppc_prepare_meta_value_for_store($meta_value);
            if ($meta_id > 0) {
                $result = $wpdb->update(
                    $table_name,
                    array(
                        'post_id' => $post_id,
                        'meta_key' => $meta_key,
                        'meta_value' => $stored_value,
                    ),
                    array('meta_id' => $meta_id),
                    array('%d', '%s', '%s'),
                    array('%d')
                );
            } else {
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'post_id' => $post_id,
                        'meta_key' => $meta_key,
                        'meta_value' => $stored_value,
                    ),
                    array('%d', '%s', '%s')
                );
            }

            if ($result === false) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'บันทึกข้อมูลไม่สำเร็จ', array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'บันทึกข้อมูลเรียบร้อย', array('table' => $slug));
            break;

        case 'delete_record':
            check_admin_referer('wppc_delete_record');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            $meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
            if ($meta_id <= 0 || !in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ข้อมูลสำหรับลบไม่ถูกต้อง', array('table' => $slug));
            }
            $table_name = wppc_get_table_name($slug);
            $deleted = $wpdb->delete($table_name, array('meta_id' => $meta_id), array('%d'));
            if ($deleted === false) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ลบข้อมูลไม่สำเร็จ', array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'ลบข้อมูลเรียบร้อย', array('table' => $slug));
            break;

        case 'bulk_delete':
            check_admin_referer('wppc_bulk_delete');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก', array('table' => $slug));
            }
            $bulk_ids = isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids']) ? array_map('absint', $_POST['bulk_ids']) : array();
            $bulk_ids = array_filter($bulk_ids, function ($id) { return $id > 0; });
            if (empty($bulk_ids)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่ได้เลือกข้อมูลที่ต้องการลบ', array('table' => $slug));
            }
            $table_name = wppc_get_table_name($slug);
            $table_sql = wppc_escape_identifier($table_name);
            $placeholders = implode(',', array_fill(0, count($bulk_ids), '%d'));
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table_sql} WHERE meta_id IN ({$placeholders})", $bulk_ids));
            if ($deleted === false) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ลบข้อมูลไม่สำเร็จ', array('table' => $slug));
            }
            $message = sprintf('ลบข้อมูลเรียบร้อย %d รายการ', (int) $deleted);
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', $message, array('table' => $slug));
            break;

        case 'truncate_table':
            check_admin_referer('wppc_truncate_table');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก', array('table' => $slug));
            }
            if (!wppc_table_exists($slug)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางในฐานข้อมูล', array('table' => $slug));
            }
            $table_name = wppc_get_table_name($slug);
            $result = $wpdb->query('TRUNCATE TABLE ' . wppc_escape_identifier($table_name));
            if ($result === false) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ล้างข้อมูลไม่สำเร็จ', array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'ล้างข้อมูลทั้งตารางเรียบร้อย', array('table' => $slug));
            break;

        case 'add_index':
            check_admin_referer('wppc_add_index');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $preset = isset($_POST['index_preset']) ? sanitize_key(wp_unslash($_POST['index_preset'])) : '';
            $result = wppc_add_index_by_preset($slug, $preset);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $result->get_error_message(), array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'เพิ่มดัชนีเรียบร้อย', array('table' => $slug));
            break;

        case 'drop_index':
            check_admin_referer('wppc_drop_index');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $index_name = isset($_POST['index_name']) ? sanitize_key(wp_unslash($_POST['index_name'])) : '';
            $result = wppc_drop_index_by_name($slug, $index_name);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $result->get_error_message(), array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'ลบดัชนีเรียบร้อย', array('table' => $slug));
            break;

        case 'import_data':
            check_admin_referer('wppc_import_data');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-import-export', 'error', 'ไม่พบตารางที่เลือก');
            }
            $target_type = isset($_POST['import_target']) && wp_unslash($_POST['import_target']) === 'main' ? 'main' : 'custom';
            if ($target_type === 'custom' && !wppc_table_exists($slug)) {
                $created = wppc_create_meta_table($slug);
                if (is_wp_error($created)) {
                    wppc_admin_redirect_with_notice('wppc-import-export', 'error', $created->get_error_message(), array('table' => $slug));
                }
            }
            $format = isset($_POST['import_format']) ? sanitize_key(wp_unslash($_POST['import_format'])) : '';
            if (!in_array($format, array('json', 'csv'), true)) {
                wppc_admin_redirect_with_notice('wppc-import-export', 'error', 'รูปแบบไฟล์นำเข้าไม่ถูกต้อง', array('table' => $slug));
            }
            if (empty($_FILES['import_file']) || !isset($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                wppc_admin_redirect_with_notice('wppc-import-export', 'error', 'ไฟล์อัปโหลดไม่ถูกต้อง', array('table' => $slug));
            }
            $file = $_FILES['import_file'];
            if (!empty($file['error'])) {
                wppc_admin_redirect_with_notice('wppc-import-export', 'error', 'อัปโหลดไฟล์ไม่สำเร็จ', array('table' => $slug));
            }
            if (!empty($file['size']) && $file['size'] > 10 * 1024 * 1024) {
                wppc_admin_redirect_with_notice('wppc-import-export', 'error', 'ไฟล์ใหญ่เกิน 10MB', array('table' => $slug));
            }

            $rows = $format === 'json'
                ? wppc_import_rows_from_json_file($file['tmp_name'])
                : wppc_import_rows_from_csv_file($file['tmp_name']);
            if (is_wp_error($rows)) {
                wppc_admin_redirect_with_notice('wppc-import-export', 'error', $rows->get_error_message(), array('table' => $slug));
            }

            $result = wppc_import_rows_into_table($slug, $rows, $target_type);
            $message = sprintf('นำเข้าข้อมูลเสร็จแล้ว: สำเร็จ %d รายการ, ข้าม %d รายการ', $result['inserted'], $result['skipped']);
            wppc_admin_redirect_with_notice('wppc-import-export', 'success', $message, array('table' => $slug));
            break;

        case 'export_data':
            $format = isset($_REQUEST['format']) ? sanitize_key(wp_unslash($_REQUEST['format'])) : '';
            if (!in_array($format, array('json', 'csv'), true)) {
                wp_die(esc_html__('รูปแบบไฟล์ส่งออกไม่ถูกต้อง', 'wp-table-postmeta-custom'));
            }
            check_admin_referer('wppc_export_data');
            $slug = isset($_REQUEST['table']) ? wppc_normalize_slug(wp_unslash($_REQUEST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wp_die(esc_html__('ไม่พบตารางที่เลือก', 'wp-table-postmeta-custom'));
            }

            $source_type = isset($_REQUEST['export_source']) && wp_unslash($_REQUEST['export_source']) === 'main' ? 'main' : 'custom';
            $export_keys_raw = isset($_REQUEST['export_keys']) ? sanitize_text_field(wp_unslash($_REQUEST['export_keys'])) : '';
            $keys = array();
            if ($export_keys_raw !== '') {
                $parts = explode(',', $export_keys_raw);
                foreach ($parts as $part) {
                    $key = wppc_normalize_meta_key($part);
                    if ($key !== '') {
                        $keys[] = $key;
                    }
                }
            }

            wppc_stream_export_table_data($slug, $format, $source_type, $keys);
            break;


    }
}
add_action('admin_init', 'wppc_handle_admin_actions');

function wppc_register_admin_menu()
{
    add_submenu_page(
        'tools.php',
        'WP Postmeta Custom',
        'WP Postmeta Custom',
        'manage_options',
        'wppc-table-types',
        'wppc_render_table_types_page'
    );

    add_submenu_page(
        null,
        'จัดการข้อมูล',
        '',
        'manage_options',
        'wppc-data-manager',
        'wppc_render_data_manager_page'
    );

    add_submenu_page(
        null,
        'นำเข้า/ส่งออกข้อมูล',
        '',
        'manage_options',
        'wppc-import-export',
        'wppc_render_import_export_page'
    );
}
add_action('admin_menu', 'wppc_register_admin_menu', 99);

function wppc_move_menu_to_tools_bottom()
{
    global $submenu;
    if (!isset($submenu['tools.php']) || !is_array($submenu['tools.php'])) {
        return;
    }

    $target_slug = 'wppc-table-types';
    $target_item = null;
    foreach ($submenu['tools.php'] as $index => $item) {
        if (isset($item[2]) && $item[2] === $target_slug) {
            $target_item = $item;
            unset($submenu['tools.php'][$index]);
            break;
        }
    }

    if ($target_item !== null) {
        $submenu['tools.php'][] = $target_item;
        $submenu['tools.php'] = array_values($submenu['tools.php']);
    }
}
add_action('admin_menu', 'wppc_move_menu_to_tools_bottom', 99999);

