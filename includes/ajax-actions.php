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

/**
 * AJAX callback to retrieve the data manager table HTML.
 */
function wppc_ajax_get_data_table() {
    wppc_verify_ajax_request('wppc_get_data_table');

    $slug = isset($_GET['table']) ? wppc_normalize_slug(wp_unslash($_GET['table'])) : '';
    $post_id = isset($_GET['filter_post_id']) ? sanitize_text_field(wp_unslash($_GET['filter_post_id'])) : '';
    $meta_key = isset($_GET['filter_meta_key']) ? sanitize_text_field(wp_unslash($_GET['filter_meta_key'])) : '';
    $meta_value = isset($_GET['filter_meta_value']) ? sanitize_text_field(wp_unslash($_GET['filter_meta_value'])) : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

    $html = wppc_render_data_manager_table_html($slug, $post_id, $meta_key, $meta_value, $paged);

    wp_send_json(array(
        'success' => true,
        'html' => $html,
    ));
}
add_action('wp_ajax_wppc_get_data_table', 'wppc_ajax_get_data_table');

/**
 * AJAX callback to retrieve a single record for editing.
 */
function wppc_ajax_get_record() {
    wppc_verify_ajax_request('wppc_get_data_table');

    $meta_id = isset($_GET['meta_id']) ? absint($_GET['meta_id']) : 0;
    if ($meta_id <= 0) {
        $meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
    }
    $slug = isset($_GET['table']) ? wppc_normalize_slug(wp_unslash($_GET['table'])) : '';
    if ($slug === '') {
        $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    }

    $slugs = wppc_get_registered_slugs();
    if (!in_array($slug, $slugs, true)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางที่เลือก'));
    }

    $record = wppc_get_record_by_id($slug, $meta_id);
    if (!$record) {
        wp_send_json(array(
            'success' => false,
            'message' => 'ไม่พบข้อมูลที่ต้องการแก้ไข',
        ));
    }

    wp_send_json(array(
        'success' => true,
        'record' => array(
            'post_id'   => absint($record['post_id']),
            'meta_key'  => $record['meta_key'],
            'meta_value' => $record['meta_value'],
        )
    ));
}
add_action('wp_ajax_wppc_ajax_get_record', 'wppc_ajax_get_record');

/**
 * AJAX callback to save/update a record.
 */
function wppc_ajax_save_record() {
    global $wpdb;
    wppc_verify_ajax_request('wppc_save_record');

    $slugs = wppc_get_registered_slugs();
    $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    if (!in_array($slug, $slugs, true)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางที่เลือก'));
    }

    $meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $meta_key = isset($_POST['meta_key']) ? wppc_normalize_meta_key(wp_unslash($_POST['meta_key'])) : '';
    $meta_value = isset($_POST['meta_value']) ? wp_unslash($_POST['meta_value']) : '';
    if ($post_id <= 0 || $meta_key === '') {
        wp_send_json(array('success' => false, 'message' => 'กรุณากรอก post_id และ meta_key ให้ครบ'));
    }

    if (!wppc_table_exists($slug)) {
        $created = wppc_create_meta_table($slug);
        if (is_wp_error($created)) {
            wp_send_json(array('success' => false, 'message' => $created->get_error_message()));
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
        wp_send_json(array('success' => false, 'message' => 'บันทึกข้อมูลไม่สำเร็จ'));
    }

    wp_send_json(array('success' => true, 'message' => 'บันทึกข้อมูลเรียบร้อย'));
}
add_action('wp_ajax_wppc_ajax_save_record', 'wppc_ajax_save_record');

/**
 * AJAX callback to delete a record.
 */
function wppc_ajax_delete_record() {
    global $wpdb;
    wppc_verify_ajax_request('wppc_delete_record');

    $slugs = wppc_get_registered_slugs();
    $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    $meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
    if ($meta_id <= 0 || !in_array($slug, $slugs, true)) {
        wp_send_json(array('success' => false, 'message' => 'ข้อมูลสำหรับลบไม่ถูกต้อง'));
    }

    $table_name = wppc_get_table_name($slug);
    $deleted = $wpdb->delete($table_name, array('meta_id' => $meta_id), array('%d'));
    if ($deleted === false) {
        wp_send_json(array('success' => false, 'message' => 'ลบข้อมูลไม่สำเร็จ'));
    }

    wp_send_json(array('success' => true, 'message' => 'ลบข้อมูลเรียบร้อย'));
}
add_action('wp_ajax_wppc_ajax_delete_record', 'wppc_ajax_delete_record');

/**
 * AJAX callback to bulk delete records.
 */
function wppc_ajax_bulk_delete() {
    global $wpdb;
    wppc_verify_ajax_request('wppc_bulk_delete');

    $slugs = wppc_get_registered_slugs();
    $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    if (!in_array($slug, $slugs, true)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางที่เลือก'));
    }

    $bulk_ids = isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids']) ? array_map('absint', $_POST['bulk_ids']) : array();
    $bulk_ids = array_filter($bulk_ids, function ($id) { return $id > 0; });
    if (empty($bulk_ids)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่ได้เลือกข้อมูลที่ต้องการลบ'));
    }

    $table_name = wppc_get_table_name($slug);
    $table_sql = wppc_escape_identifier($table_name);
    $placeholders = implode(',', array_fill(0, count($bulk_ids), '%d'));
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table_sql} WHERE meta_id IN ({$placeholders})", $bulk_ids));
    if ($deleted === false) {
        wp_send_json(array('success' => false, 'message' => 'ลบข้อมูลไม่สำเร็จ'));
    }

    $message = sprintf('ลบข้อมูลเรียบร้อย %d รายการ', (int) $deleted);
    wp_send_json(array('success' => true, 'message' => $message));
}
add_action('wp_ajax_wppc_ajax_bulk_delete', 'wppc_ajax_bulk_delete');

/**
 * AJAX callback to truncate a table.
 */
function wppc_ajax_truncate_table() {
    global $wpdb;
    wppc_verify_ajax_request('wppc_truncate_table');

    $slugs = wppc_get_registered_slugs();
    $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    if (!in_array($slug, $slugs, true)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางที่เลือก'));
    }
    if (!wppc_table_exists($slug)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางในฐานข้อมูล'));
    }

    $table_name = wppc_get_table_name($slug);
    $result = $wpdb->query('TRUNCATE TABLE ' . wppc_escape_identifier($table_name));
    if ($result === false) {
        wp_send_json(array('success' => false, 'message' => 'ล้างข้อมูลไม่สำเร็จ'));
    }

    wp_send_json(array('success' => true, 'message' => 'ล้างข้อมูลทั้งตารางเรียบร้อย'));
}
add_action('wp_ajax_wppc_ajax_truncate_table', 'wppc_ajax_truncate_table');

