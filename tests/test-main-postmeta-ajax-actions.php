<?php
// AJAX entry-point regression tests for main wp_postmeta management.

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('WPPC_TABLE_REGISTRY_OPTION')) {
    define('WPPC_TABLE_REGISTRY_OPTION', 'wppc_table_registry');
}

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

class WppcJsonResponse extends RuntimeException
{
    public $payload;
    public $status_code;

    public function __construct($payload, $status_code = null)
    {
        parent::__construct('JSON response captured');
        $this->payload = $payload;
        $this->status_code = $status_code;
    }
}

$wppc_test_manage_options = true;
$wppc_test_nonce_valid = true;
$wppc_test_metadata_calls = array(
    'add' => array(),
    'update' => array(),
    'delete' => array(),
);

function absint($value)
{
    return abs((int) $value);
}

function wp_unslash($value)
{
    return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
}

function wp_slash($value)
{
    return is_array($value) ? array_map('wp_slash', $value) : addslashes((string) $value);
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function get_option($name, $default = false)
{
    return $name === WPPC_TABLE_REGISTRY_OPTION ? array('product') : $default;
}

function current_user_can($capability, ...$args)
{
    global $wppc_test_manage_options;
    if ($capability === 'manage_options') {
        return $wppc_test_manage_options;
    }

    return $capability === 'edit_post' && isset($args[0]) && (int) $args[0] === 42;
}

function check_ajax_referer($action, $field, $die = true)
{
    global $wppc_test_nonce_valid;
    return $wppc_test_nonce_valid;
}

function add_action($hook, $callback)
{
    return true;
}

function wp_send_json($payload, $status_code = null)
{
    throw new WppcJsonResponse($payload, $status_code);
}

function wp_send_json_error($data = null, $status_code = null)
{
    throw new WppcJsonResponse(array('success' => false, 'data' => $data), $status_code);
}

function wp_send_json_success($data = null, $status_code = null)
{
    throw new WppcJsonResponse(array('success' => true, 'data' => $data), $status_code);
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

function is_serialized($value)
{
    if (!is_string($value)) {
        return false;
    }
    $value = trim($value);
    return $value === 'N;' || preg_match('/^(?:a|O|C|s|b|i|d):.*[;}]+$/s', $value) === 1;
}

function get_post($post_id)
{
    return (int) $post_id === 42 ? (object) array('ID' => 42, 'post_type' => 'post') : null;
}

function add_metadata($meta_type, $post_id, $meta_key, $meta_value, $unique = false)
{
    global $wppc_test_metadata_calls;
    $wppc_test_metadata_calls['add'][] = array(
        'meta_type' => $meta_type,
        'post_id' => (int) $post_id,
        'meta_key' => wp_unslash($meta_key),
        'meta_value' => wp_unslash($meta_value),
        'unique' => (bool) $unique,
    );
    return 77;
}

function update_metadata_by_mid($meta_type, $meta_id, $meta_value, $meta_key = false)
{
    global $wppc_test_metadata_calls;
    $wppc_test_metadata_calls['update'][] = array(
        'meta_type' => $meta_type,
        'meta_id' => (int) $meta_id,
        'meta_key' => $meta_key,
        'meta_value' => $meta_value,
    );
    return true;
}

function delete_metadata_by_mid($meta_type, $meta_id)
{
    global $wppc_test_metadata_calls;
    $wppc_test_metadata_calls['delete'][] = array(
        'meta_type' => $meta_type,
        'meta_id' => (int) $meta_id,
    );
    return true;
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url)
{
    return (string) $url;
}

function esc_js($text)
{
    return addslashes((string) $text);
}

function admin_url($path = '')
{
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function add_query_arg($args, $url)
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}

function wp_nonce_field($action)
{
    echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr($action) . '">';
}

class MockWpdbMainAjax
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $write_queries = array();
    public $records = array(
        9 => array(
            'meta_id' => 9,
            'post_id' => 42,
            'meta_key' => 'price',
            'meta_value' => '199',
        ),
        10 => array(
            'meta_id' => 10,
            'post_id' => 42,
            'meta_key' => 'settings',
            'meta_value' => 'a:1:{s:3:"foo";s:3:"bar";}',
        ),
    );

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $arg) {
            if (preg_match('/%d/', $query)) {
                $query = preg_replace('/%d/', (string) ((int) $arg), $query, 1);
            } else {
                $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
            }
        }
        return $query;
    }

    public function get_var($query)
    {
        if (strpos($query, 'SHOW TABLES LIKE') !== false) {
            return 'wp_postmeta_product';
        }
        return 1;
    }

    public function get_results($query, $output_type = null)
    {
        return array($this->records[9]);
    }

    public function get_row($query, $output_type = null)
    {
        if (!preg_match('/WHERE meta_id = (\d+)/', $query, $matches)) {
            return null;
        }
        $meta_id = (int) $matches[1];
        return $this->records[$meta_id] ?? null;
    }

    public function insert($table, $data, $format = null)
    {
        $this->write_queries[] = array('insert', $table, $data);
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $this->write_queries[] = array('update', $table, $data, $where);
        return 1;
    }

    public function delete($table, $where, $format = null)
    {
        $this->write_queries[] = array('delete', $table, $where);
        return 1;
    }

    public function query($query)
    {
        $this->write_queries[] = array('query', $query);
        return 1;
    }
}

$wpdb = new MockWpdbMainAjax();

require_once __DIR__ . '/../includes/db-helpers.php';
require_once __DIR__ . '/../includes/admin-views.php';
require_once __DIR__ . '/../includes/ajax-actions.php';

function wppc_capture_json_response($callback)
{
    try {
        $callback();
    } catch (WppcJsonResponse $response) {
        return $response;
    }

    throw new RuntimeException('Expected the AJAX callback to send a JSON response.');
}

function wppc_response_message($payload)
{
    if (isset($payload['message'])) {
        return (string) $payload['message'];
    }
    if (isset($payload['data']['message'])) {
        return (string) $payload['data']['message'];
    }
    return '';
}

$failed = false;

$wppc_test_manage_options = false;
$_GET = array('source' => 'main', 'filter_post_id' => '42');
$response = wppc_capture_json_response('wppc_ajax_get_data_table');
if ($response->status_code !== 403 || !empty($response->payload['success'])) {
    echo "FAIL: Main data AJAX should reject users without manage_options.\n";
    $failed = true;
}
$wppc_test_manage_options = true;

$wppc_test_nonce_valid = false;
$response = wppc_capture_json_response('wppc_ajax_get_data_table');
if ($response->status_code !== 403 || !empty($response->payload['success'])) {
    echo "FAIL: Main data AJAX should reject an invalid nonce.\n";
    $failed = true;
}
$wppc_test_nonce_valid = true;

$_GET = array(
    'source' => 'invalid',
    'table' => 'product',
    'filter_post_id' => '42',
);
$response = wppc_capture_json_response('wppc_ajax_get_data_table');
if (!empty($response->payload['success'])) {
    echo "FAIL: Data AJAX should reject a source outside the fixed allowlist.\n";
    $failed = true;
}

$_GET = array(
    'source' => 'main',
    'filter_post_id' => '42',
    'filter_meta_key' => '',
    'filter_meta_value' => '',
    'paged' => '1',
);
$response = wppc_capture_json_response('wppc_ajax_get_data_table');
if (
    empty($response->payload['success'])
    || strpos($response->payload['html'] ?? '', 'name="source" value="main"') === false
    || strpos($response->payload['html'] ?? '', 'bulk_delete') !== false
) {
    echo "FAIL: Main data AJAX should render filtered main-source results without bulk actions.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => '0',
    'post_id' => '42',
    'meta_key' => 'price',
    'meta_value' => '250',
);
$response = wppc_capture_json_response('wppc_ajax_save_record');
if (empty($response->payload['success']) || count($wppc_test_metadata_calls['add']) !== 1) {
    echo "FAIL: Main save AJAX should add one row through WordPress metadata persistence.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => '0',
    'post_id' => '42abc',
    'meta_key' => 'price',
    'meta_value' => '251',
);
$response = wppc_capture_json_response('wppc_ajax_save_record');
if (!empty($response->payload['success']) || count($wppc_test_metadata_calls['add']) !== 1) {
    echo "FAIL: Main save AJAX should reject malformed post IDs before persistence.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => false,
    'post_id' => '42',
    'meta_key' => 'price',
    'meta_value' => '252',
);
$response = wppc_capture_json_response('wppc_ajax_save_record');
if (!empty($response->payload['success']) || count($wppc_test_metadata_calls['add']) !== 1) {
    echo "FAIL: Main save AJAX should reject boolean meta IDs before persistence.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => '9',
    'post_id' => '999',
    'meta_key' => 'sale_price',
    'meta_value' => '275',
);
$response = wppc_capture_json_response('wppc_ajax_save_record');
if (empty($response->payload['success']) || count($wppc_test_metadata_calls['update']) !== 1) {
    echo "FAIL: Main update AJAX should ignore a tampered post_id and derive it from meta_id.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => '9abc',
    'post_id' => '42',
    'meta_key' => 'sale_price',
    'meta_value' => '276',
);
$response = wppc_capture_json_response('wppc_ajax_save_record');
if (!empty($response->payload['success']) || count($wppc_test_metadata_calls['update']) !== 1) {
    echo "FAIL: Main update AJAX should reject malformed meta IDs before persistence.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => '10',
    'post_id' => '42',
    'meta_key' => 'settings',
    'meta_value' => 'changed',
);
$response = wppc_capture_json_response('wppc_ajax_save_record');
if (!empty($response->payload['success']) || count($wppc_test_metadata_calls['update']) !== 1) {
    echo "FAIL: Main update AJAX should reject serialized rows before persistence.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => '9',
);
$response = wppc_capture_json_response('wppc_ajax_delete_record');
if (empty($response->payload['success']) || count($wppc_test_metadata_calls['delete']) !== 1) {
    echo "FAIL: Main delete AJAX should delete one authorized row by meta_id.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => '',
    'meta_id' => '9abc',
);
$response = wppc_capture_json_response('wppc_ajax_delete_record');
if (!empty($response->payload['success']) || count($wppc_test_metadata_calls['delete']) !== 1) {
    echo "FAIL: Main delete AJAX should reject malformed meta IDs before persistence.\n";
    $failed = true;
}

$wpdb->write_queries = array();
$_POST = array(
    'source' => 'main',
    'table' => 'product',
    'bulk_ids' => array('9'),
);
$response = wppc_capture_json_response('wppc_ajax_bulk_delete');
if (!empty($response->payload['success']) || strpos(wppc_response_message($response->payload), 'ไม่รองรับ') === false || !empty($wpdb->write_queries)) {
    echo "FAIL: Main source must reject bulk delete at the AJAX enforcement point.\n";
    $failed = true;
}

$_POST = array(
    'source' => 'main',
    'table' => 'product',
);
$response = wppc_capture_json_response('wppc_ajax_truncate_table');
if (!empty($response->payload['success']) || strpos(wppc_response_message($response->payload), 'ไม่รองรับ') === false || !empty($wpdb->write_queries)) {
    echo "FAIL: Main source must reject truncate at the AJAX enforcement point.\n";
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "PASS: Main wp_postmeta AJAX entry points enforce source, authorization, and individual CRUD boundaries.\n";
exit(0);
