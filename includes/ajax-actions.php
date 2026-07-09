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
