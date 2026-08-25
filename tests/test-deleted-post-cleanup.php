<?php
// Test script for post deletion cleanup

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('WPPC_TABLE_REGISTRY_OPTION')) {
    define('WPPC_TABLE_REGISTRY_OPTION', 'wppc_table_registry');
}

// Mock WordPress functions and classes
class MockWpdb {
    public $prefix = 'wp_';
    public $deleted_calls = array();
    public $existing_tables = array('wp_postmeta_product', 'wp_postmeta_orders');

    public function prepare($query, ...$args) {
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $arg) {
            $query = preg_replace('/%[sdf]/', "'" . addslashes((string)$arg) . "'", $query, 1);
        }
        return $query;
    }

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }

    public function get_var($query) {
        if (strpos($query, 'SHOW TABLES LIKE') !== false) {
            $cleaned_query = str_replace('\\_', '_', $query);
            foreach ($this->existing_tables as $table) {
                if (strpos($cleaned_query, $table) !== false) {
                    return $table;
                }
            }
            return null;
        }
        return null;
    }

    public function delete($table, $where, $where_format = null) {
        $this->deleted_calls[] = array(
            'table' => $table,
            'where' => $where,
        );
        return 1;
    }
}

global $wpdb, $mock_options;
$wpdb = new MockWpdb();
$mock_options = array(
    WPPC_TABLE_REGISTRY_OPTION => array('product', 'orders', 'nonexistent_table'),
);

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        return isset($mock_options[$option]) ? $mock_options[$option] : $default;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string)$str));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($str) {
        return is_string($str) ? stripslashes($str) : $str;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int)$maybeint);
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, ...$arg) {}
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$arg) { return $value; }
}

require_once __DIR__ . '/../includes/db-helpers.php';

if (!function_exists('wppc_cleanup_custom_meta_on_delete_post')) {
    echo "FAIL: Function wppc_cleanup_custom_meta_on_delete_post does not exist!\n";
    exit(1);
}

// Test case 1: post_id <= 0 should do nothing
$wpdb->deleted_calls = array();
$deleted_count = wppc_cleanup_custom_meta_on_delete_post(0);
if (!empty($wpdb->deleted_calls) || $deleted_count !== 0) {
    echo "FAIL: Cleaned up with invalid post_id 0\n";
    exit(1);
}
echo "PASS: Invalid post_id ignored\n";

// Test case 2: valid post_id deletes from all existing registered tables
$wpdb->deleted_calls = array();
$deleted_count = wppc_cleanup_custom_meta_on_delete_post(42);

$expected_tables = array('wp_postmeta_product', 'wp_postmeta_orders');
$actual_tables = array_map(function($call) { return $call['table']; }, $wpdb->deleted_calls);

if ($actual_tables !== $expected_tables) {
    echo "FAIL: Expected deletions in " . json_encode($expected_tables) . " but got " . json_encode($actual_tables) . "\n";
    exit(1);
}

foreach ($wpdb->deleted_calls as $call) {
    if ($call['where'] !== array('post_id' => 42)) {
        echo "FAIL: Expected where post_id => 42, got " . json_encode($call['where']) . "\n";
        exit(1);
    }
}

if ($deleted_count !== 2) {
    echo "FAIL: Expected 2 deleted records, got {$deleted_count}\n";
    exit(1);
}

echo "PASS: Cleanup performed on all registered existing tables for post_id 42\n";
echo "\nAll delete post cleanup tests passed!\n";
exit(0);
