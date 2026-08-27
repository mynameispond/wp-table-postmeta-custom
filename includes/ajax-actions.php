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

    $source = isset($_GET['source']) ? wppc_normalize_data_source(wp_unslash($_GET['source'])) : 'custom';
    if ($source === '') {
        wp_send_json(array('success' => false, 'message' => 'แหล่งข้อมูลไม่ถูกต้อง'));
    }

    $slug = isset($_GET['table']) ? wppc_normalize_slug(wp_unslash($_GET['table'])) : '';
    if ($source === 'custom' && !in_array($slug, wppc_get_registered_slugs(), true)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางที่เลือก'));
    }

    $post_id = isset($_GET['filter_post_id']) ? trim(sanitize_text_field(wp_unslash($_GET['filter_post_id']))) : '';
    if ($post_id !== '' && (!ctype_digit($post_id) || absint($post_id) <= 0)) {
        wp_send_json(array('success' => false, 'message' => 'post_id สำหรับค้นหาไม่ถูกต้อง'));
    }
    $meta_key = isset($_GET['filter_meta_key']) ? sanitize_text_field(wp_unslash($_GET['filter_meta_key'])) : '';
    $meta_value = isset($_GET['filter_meta_value']) ? sanitize_text_field(wp_unslash($_GET['filter_meta_value'])) : '';
    if (strlen($meta_key) > 255 || strlen($meta_value) > 1000) {
        wp_send_json(array('success' => false, 'message' => 'คำค้นยาวเกินขอบเขตที่รองรับ'));
    }
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

    $html = wppc_render_data_manager_table_html($slug, $post_id, $meta_key, $meta_value, $paged, $source);

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

    $source_value = isset($_GET['source']) ? $_GET['source'] : (isset($_POST['source']) ? $_POST['source'] : 'custom');
    $source = wppc_normalize_data_source(wp_unslash($source_value));
    if ($source === '') {
        wp_send_json(array('success' => false, 'message' => 'แหล่งข้อมูลไม่ถูกต้อง'));
    }

    $meta_id_value = isset($_GET['meta_id']) ? wp_unslash($_GET['meta_id']) : 0;
    $meta_id = $source === 'main' ? wppc_normalize_main_postmeta_id($meta_id_value) : absint($meta_id_value);
    if ($meta_id <= 0 && isset($_POST['meta_id'])) {
        $meta_id_value = wp_unslash($_POST['meta_id']);
        $meta_id = $source === 'main' ? wppc_normalize_main_postmeta_id($meta_id_value) : absint($meta_id_value);
    }
    if ($meta_id <= 0) {
        wp_send_json(array('success' => false, 'message' => 'ข้อมูลที่ต้องการแก้ไขไม่ถูกต้อง'));
    }

    $slug = isset($_GET['table']) ? wppc_normalize_slug(wp_unslash($_GET['table'])) : '';
    if ($slug === '') {
        $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    }

    if ($source === 'custom' && !in_array($slug, wppc_get_registered_slugs(), true)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางที่เลือก'));
    }

    $record = wppc_get_record_by_id($slug, $meta_id, $source);
    if (!$record) {
        wp_send_json(array(
            'success' => false,
            'message' => 'ไม่พบข้อมูลที่ต้องการแก้ไข',
        ));
    }
    if ($source === 'main') {
        $target = wppc_validate_main_postmeta_target($record['post_id']);
        if (is_wp_error($target)) {
            wp_send_json(array('success' => false, 'message' => $target->get_error_message()));
        }
        if (!wppc_is_main_postmeta_value_editable($record['meta_value'])) {
            wp_send_json(array('success' => false, 'message' => 'ไม่รองรับการแก้ไขค่า PHP serialized จากหน้านี้'));
        }
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

    $source = isset($_POST['source']) ? wppc_normalize_data_source(wp_unslash($_POST['source'])) : 'custom';
    if ($source === '' || !wppc_is_data_source_action_allowed($source, 'save_record')) {
        wp_send_json(array('success' => false, 'message' => 'แหล่งข้อมูลหรือการทำงานไม่ถูกต้อง'));
    }

    $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    if ($source === 'custom' && !in_array($slug, wppc_get_registered_slugs(), true)) {
        wp_send_json(array('success' => false, 'message' => 'ไม่พบตารางที่เลือก'));
    }

    $meta_id_value = isset($_POST['meta_id']) ? wp_unslash($_POST['meta_id']) : 0;
    $post_id_value = isset($_POST['post_id']) ? wp_unslash($_POST['post_id']) : 0;
    $meta_id = $source === 'main' ? wppc_normalize_main_postmeta_id($meta_id_value) : absint($meta_id_value);
    $post_id = $source === 'main' ? wppc_normalize_main_postmeta_id($post_id_value) : absint($post_id_value);
    $meta_key_input = isset($_POST['meta_key']) ? wp_unslash($_POST['meta_key']) : '';
    $meta_value = isset($_POST['meta_value']) ? wp_unslash($_POST['meta_value']) : '';

    if (
        $source === 'main'
        && array_key_exists('meta_id', $_POST)
        && !wppc_is_main_postmeta_new_id($meta_id_value)
        && $meta_id <= 0
    ) {
        wp_send_json(array('success' => false, 'message' => 'meta_id สำหรับแก้ไขไม่ถูกต้อง'));
    }

    if ($source === 'main') {
        $result = $meta_id > 0
            ? wppc_update_main_postmeta_record($meta_id, $meta_key_input, $meta_value)
            : wppc_add_main_postmeta_record($post_id, $meta_key_input, $meta_value);
        if (is_wp_error($result)) {
            wp_send_json(array('success' => false, 'message' => $result->get_error_message()));
        }

        wp_send_json(array('success' => true, 'message' => 'บันทึกข้อมูลเรียบร้อย'));
    }

    $meta_key = wppc_normalize_meta_key($meta_key_input);
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

    $source = isset($_POST['source']) ? wppc_normalize_data_source(wp_unslash($_POST['source'])) : 'custom';
    if ($source === '' || !wppc_is_data_source_action_allowed($source, 'delete_record')) {
        wp_send_json(array('success' => false, 'message' => 'แหล่งข้อมูลหรือการทำงานไม่ถูกต้อง'));
    }

    $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    $meta_id_value = isset($_POST['meta_id']) ? wp_unslash($_POST['meta_id']) : 0;
    $meta_id = $source === 'main' ? wppc_normalize_main_postmeta_id($meta_id_value) : absint($meta_id_value);
    if ($meta_id <= 0 || ($source === 'custom' && !in_array($slug, wppc_get_registered_slugs(), true))) {
        wp_send_json(array('success' => false, 'message' => 'ข้อมูลสำหรับลบไม่ถูกต้อง'));
    }

    if ($source === 'main') {
        $deleted = wppc_delete_main_postmeta_record($meta_id);
        if (is_wp_error($deleted)) {
            wp_send_json(array('success' => false, 'message' => $deleted->get_error_message()));
        }

        wp_send_json(array('success' => true, 'message' => 'ลบข้อมูลเรียบร้อย'));
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

    $source = isset($_POST['source']) ? wppc_normalize_data_source(wp_unslash($_POST['source'])) : 'custom';
    if ($source === 'main') {
        wp_send_json(array('success' => false, 'message' => 'ตาราง wp_postmeta หลักไม่รองรับการลบหลายรายการ'));
    }
    if ($source === '' || !wppc_is_data_source_action_allowed($source, 'bulk_delete')) {
        wp_send_json(array('success' => false, 'message' => 'แหล่งข้อมูลหรือการทำงานไม่ถูกต้อง'));
    }

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

    $source = isset($_POST['source']) ? wppc_normalize_data_source(wp_unslash($_POST['source'])) : 'custom';
    if ($source === 'main') {
        wp_send_json(array('success' => false, 'message' => 'ตาราง wp_postmeta หลักไม่รองรับการล้างข้อมูลทั้งตาราง'));
    }
    if ($source === '' || !wppc_is_data_source_action_allowed($source, 'truncate_table')) {
        wp_send_json(array('success' => false, 'message' => 'แหล่งข้อมูลหรือการทำงานไม่ถูกต้อง'));
    }

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

/**
 * AJAX callback to import CSV/JSON data.
 */
function wppc_ajax_import_data() {
    wppc_verify_ajax_request('wppc_import_data');

    $slugs = wppc_get_registered_slugs();
    $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
    if (!in_array($slug, $slugs, true)) {
        wp_send_json_error(array('message' => 'ไม่พบตารางที่เลือก'));
    }

    $target_type = isset($_POST['import_target']) && wp_unslash($_POST['import_target']) === 'main' ? 'main' : 'custom';
    if ($target_type === 'custom' && !wppc_table_exists($slug)) {
        $created = wppc_create_meta_table($slug);
        if (is_wp_error($created)) {
            wp_send_json_error(array('message' => $created->get_error_message()));
        }
    }

    $format = isset($_POST['import_format']) ? sanitize_key(wp_unslash($_POST['import_format'])) : '';
    if (!in_array($format, array('json', 'csv'), true)) {
        wp_send_json_error(array('message' => 'รูปแบบไฟล์นำเข้าไม่ถูกต้อง'));
    }

    if (empty($_FILES['import_file']) || !isset($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'ไฟล์อัปโหลดไม่ถูกต้อง'));
    }

    $file = $_FILES['import_file'];
    if (!empty($file['error'])) {
        wp_send_json_error(array('message' => 'อัปโหลดไฟล์ไม่สำเร็จ'));
    }

    if (!empty($file['size']) && $file['size'] > 10 * 1024 * 1024) {
        wp_send_json_error(array('message' => 'ไฟล์ใหญ่เกิน 10MB'));
    }

    $rows = $format === 'json'
        ? wppc_import_rows_from_json_file($file['tmp_name'])
        : wppc_import_rows_from_csv_file($file['tmp_name']);

    if (is_wp_error($rows)) {
        wp_send_json_error(array('message' => $rows->get_error_message()));
    }

    $result = wppc_import_rows_into_table($slug, $rows, $target_type);

    wp_send_json_success(array(
        'message' => sprintf('นำเข้าข้อมูลเรียบร้อย %d รายการ, ข้าม %d รายการ', $result['inserted'], $result['skipped'])
    ));
}
add_action('wp_ajax_wppc_import_data', 'wppc_ajax_import_data');

/**
 * AJAX callback to retrieve the table types list HTML.
 */
function wppc_ajax_get_table_types() {
    wppc_verify_ajax_request('wppc_get_table_types');

    $html = wppc_render_table_types_table_html();

    wp_send_json(array(
        'success' => true,
        'html' => $html,
    ));
}
add_action('wp_ajax_wppc_get_table_types', 'wppc_ajax_get_table_types');


