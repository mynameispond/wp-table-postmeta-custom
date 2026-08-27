<?php
// Render-level regression tests for the main wp_postmeta admin source.

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('WPPC_TABLE_REGISTRY_OPTION')) {
    define('WPPC_TABLE_REGISTRY_OPTION', 'wppc_table_registry');
}

$wppc_test_registered_slugs = array();

function absint($value)
{
    return abs((int) $value);
}

function wp_unslash($value)
{
    return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
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
    global $wppc_test_registered_slugs;

    return $name === WPPC_TABLE_REGISTRY_OPTION ? $wppc_test_registered_slugs : $default;
}

function current_user_can($capability, ...$args)
{
    if ($capability === 'manage_options') {
        return true;
    }

    return $capability === 'edit_post' && isset($args[0]) && (int) $args[0] === 42;
}

function get_post($post_id)
{
    return (int) $post_id === 42 ? (object) array('ID' => 42, 'post_type' => 'post') : null;
}

function is_serialized($value)
{
    return is_string($value) && preg_match('/^(?:a|O|C|s|b|i|d):.*[;}]+$/s', trim($value)) === 1;
}

function esc_html__($text, $domain = '')
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
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

function esc_textarea($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
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

function submit_button($text)
{
    echo '<input type="submit" class="button button-primary" value="' . esc_attr($text) . '">';
}

function number_format_i18n($number)
{
    return number_format((int) $number);
}

function wp_die($message)
{
    throw new RuntimeException((string) $message);
}

class MockWpdbMainAdminView
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';

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
        return 1;
    }

    public function get_results($query, $output_type = null)
    {
        return array(
            array(
                'meta_id' => 9,
                'post_id' => 42,
                'meta_key' => 'price',
                'meta_value' => '199',
            ),
        );
    }

    public function get_row($query, $output_type = null)
    {
        return array(
            'meta_id' => 9,
            'post_id' => 42,
            'meta_key' => 'price',
            'meta_value' => '199',
        );
    }
}

$wpdb = new MockWpdbMainAdminView();

require_once __DIR__ . '/../includes/db-helpers.php';
require_once __DIR__ . '/../includes/admin-views.php';

$table_html = wppc_render_data_manager_table_html('', '42', '', '', 1, 'main');

$failed = false;
if (strpos($table_html, 'name="source" value="main"') === false) {
    echo "FAIL: Main table results should retain the main source in forms and links.\n";
    $failed = true;
}
if (strpos($table_html, 'bulk_delete') !== false || strpos($table_html, 'truncate_table') !== false) {
    echo "FAIL: Main table results must not render bulk delete or truncate actions.\n";
    $failed = true;
}
if (strpos($table_html, 'wppc-row-cb') !== false || strpos($table_html, 'wppc-select-all') !== false) {
    echo "FAIL: Main table results must not render bulk-selection checkboxes.\n";
    $failed = true;
}
if (strpos($table_html, 'data-id="9"') === false) {
    echo "FAIL: Main scalar rows should expose individual edit/delete controls by meta_id.\n";
    $failed = true;
}

$_REQUEST = array(
    'page' => 'wppc-data-manager',
    'source' => 'main',
);
$_GET = array(
    'source' => 'main',
    'filter_post_id' => '42',
    'edit_id' => '9',
);

ob_start();
wppc_render_data_manager_page();
$page_html = ob_get_clean();

if (strpos($page_html, 'wp_postmeta หลัก') === false) {
    echo "FAIL: Data Manager should render a dedicated wp_postmeta main tab.\n";
    $failed = true;
}
if (strpos($page_html, 'data-source="main"') === false) {
    echo "FAIL: Data Manager should expose the selected source to its AJAX container.\n";
    $failed = true;
}
if (!preg_match('/name="post_id"[^>]*readonly/', $page_html)) {
    echo "FAIL: Editing a main-table row should render post_id as read-only.\n";
    $failed = true;
}
if (strpos($page_html, 'ยังไม่มีตาราง custom') !== false) {
    echo "FAIL: The main source should remain usable when no custom tables exist.\n";
    $failed = true;
}

$wppc_test_registered_slugs = array('product');

ob_start();
wppc_render_data_manager_page();
$page_with_custom_slug_html = ob_get_clean();

if (strpos($page_with_custom_slug_html, 'name="table" value=""') === false
    || strpos($page_with_custom_slug_html, 'data-table=""') === false
) {
    echo "FAIL: Main-source forms and AJAX state should not carry a custom-table slug.\n";
    $failed = true;
}
if (strpos($page_with_custom_slug_html, 'name="table" value="product"') !== false
    || strpos($page_with_custom_slug_html, 'data-table="product"') !== false
) {
    echo "FAIL: A registered custom slug leaked into the selected main source.\n";
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "PASS: Main wp_postmeta admin views expose only individual safe actions.\n";
exit(0);
