<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verify AJAX requests for authorization and valid nonce.
 *
 * @param string $nonce_action The nonce action name to verify.
 * @return void
 */
function wppc_verify_ajax_request($nonce_action) {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้'), 403);
    }
    if (!check_ajax_referer($nonce_action, 'nonce', false)) {
        wp_send_json_error(array('message' => 'การเชื่อมต่อหมดอายุ กรุณารีเฟรชหน้าเว็บ'), 403);
    }
}

/**
 * AJAX callback to create a new custom meta table.
 */
function wppc_ajax_create_table() {
    wppc_verify_ajax_request('wppc_create_table');

    $new_slug = isset($_POST['new_table_slug']) ? wppc_normalize_slug(wp_unslash($_POST['new_table_slug'])) : '';
    if ($new_slug === '') {
        wp_send_json_error(array('message' => 'รหัสตารางไม่ถูกต้อง'));
    }

    $slugs = wppc_get_registered_slugs();
    if (in_array($new_slug, $slugs, true)) {
        wp_send_json_error(array('message' => 'รหัสตารางนี้มีอยู่แล้ว'));
    }

    $result = wppc_create_meta_table($new_slug);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array(
        'message' => 'สร้างตารางเรียบร้อย',
        'html' => wppc_render_table_types_table_html()
    ));
}
add_action('wp_ajax_wppc_create_table', 'wppc_ajax_create_table');

/**
 * AJAX callback to delete an existing custom meta table.
 */
function wppc_ajax_delete_table() {
    wppc_verify_ajax_request('wppc_delete_table');

    $delete_slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    if ($delete_slug === '') {
        wp_send_json_error(array('message' => 'รหัสตารางไม่ถูกต้อง'));
    }

    $slugs = wppc_get_registered_slugs();
    if (!in_array($delete_slug, $slugs, true)) {
        wp_send_json_error(array('message' => 'ไม่พบตารางที่เลือก'));
    }

    $result = wppc_drop_meta_table($delete_slug);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array(
        'message' => 'ลบตารางเรียบร้อย',
        'html' => wppc_render_table_types_table_html()
    ));
}
add_action('wp_ajax_wppc_delete_table', 'wppc_ajax_delete_table');
