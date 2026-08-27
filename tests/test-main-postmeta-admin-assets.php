<?php
// Asset/localization behavior for the main wp_postmeta Data Manager source.

define('ABSPATH', __DIR__ . '/../');

$wppc_test_enqueued_versions = array();
$wppc_test_localized_data = array();

function plugin_dir_path($file)
{
    return dirname($file) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url($file)
{
    return 'https://example.test/wp-content/plugins/wp-table-postmeta-custom/';
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    return true;
}

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function wp_unslash($value)
{
    return $value;
}

function get_option($name, $default = false)
{
    return $name === 'wppc_table_registry' ? array() : $default;
}

function wp_enqueue_style($handle, $source, $dependencies, $version)
{
    global $wppc_test_enqueued_versions;
    $wppc_test_enqueued_versions[$handle] = $version;
}

function wp_enqueue_script($handle, $source, $dependencies, $version, $in_footer)
{
    global $wppc_test_enqueued_versions;
    $wppc_test_enqueued_versions[$handle] = $version;
}

function wp_localize_script($handle, $object_name, $data)
{
    global $wppc_test_localized_data;
    $wppc_test_localized_data = $data;
}

function admin_url($path = '')
{
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function wp_create_nonce($action)
{
    return 'nonce-' . $action;
}

$_GET = array(
    'page' => 'wppc-data-manager',
    'source' => 'main',
);
$_REQUEST = $_GET;

require_once __DIR__ . '/../wp-table-postmeta-custom.php';

wppc_enqueue_admin_assets();

$failed = false;
if (($wppc_test_localized_data['active_source'] ?? '') !== 'main') {
    echo "FAIL: Data Manager assets should localize active_source=main.\n";
    $failed = true;
}
if (
    ($wppc_test_enqueued_versions['wppc-admin-css'] ?? '') !== '1.2.0'
    || ($wppc_test_enqueued_versions['wppc-admin-js'] ?? '') !== '1.2.0'
) {
    echo "FAIL: Changed admin assets should use version 1.2.0 for cache invalidation.\n";
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "PASS: Main-source admin assets localize source state and use the feature version.\n";
exit(0);
