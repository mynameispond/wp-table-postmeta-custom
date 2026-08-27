<?php
// Regression tests for the main wp_postmeta data-manager source.

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('absint')) {
    function absint($value)
    {
        return abs((int) $value);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return stripslashes((string) $value);
    }
}

if (!function_exists('wp_slash')) {
    function wp_slash($value)
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return addslashes((string) $value);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        return trim((string) $value);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($value)
    {
        return $value instanceof WP_Error;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === 'N;') {
            return true;
        }

        return preg_match('/^(?:a|O|C|s|b|i|d):.*[;}]+$/s', $value) === 1;
    }
}

$wppc_test_metadata_calls = array(
    'add' => array(),
    'update' => array(),
    'delete' => array(),
);

if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        $post_id = (int) $post_id;
        if (!in_array($post_id, array(42, 43), true)) {
            return null;
        }

        return (object) array(
            'ID' => $post_id,
            'post_type' => 'post',
        );
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        return $capability === 'edit_post' && isset($args[0]) && (int) $args[0] === 42;
    }
}

if (!function_exists('add_metadata')) {
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
}

if (!function_exists('update_metadata_by_mid')) {
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
}

if (!function_exists('delete_metadata_by_mid')) {
    function delete_metadata_by_mid($meta_type, $meta_id)
    {
        global $wppc_test_metadata_calls;
        $wppc_test_metadata_calls['delete'][] = array(
            'meta_type' => $meta_type,
            'meta_id' => (int) $meta_id,
        );
        return true;
    }
}

class MockWpdbMainDataManager
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $queries = array();
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
        11 => array(
            'meta_id' => 11,
            'post_id' => 43,
            'meta_key' => 'private_note',
            'meta_value' => 'restricted',
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
        $this->queries[] = $query;
        return 2;
    }

    public function get_results($query, $output_type = null)
    {
        $this->queries[] = $query;
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
        $this->queries[] = $query;
        if (!preg_match('/WHERE meta_id = (\d+)/', $query, $matches)) {
            return null;
        }

        $meta_id = (int) $matches[1];
        return isset($this->records[$meta_id]) ? $this->records[$meta_id] : null;
    }
}

$wpdb = new MockWpdbMainDataManager();

require_once __DIR__ . '/../includes/db-helpers.php';

$result = wppc_get_table_records('', '42', 'price', '199', 1, 20, 'main');

$passed = true;
if ($result['total'] !== 2) {
    echo "FAIL: Main-source search should return the wp_postmeta count.\n";
    $passed = false;
}

if (count($result['rows']) !== 1 || (int) $result['rows'][0]['meta_id'] !== 9) {
    echo "FAIL: Main-source search should return rows from wp_postmeta.\n";
    $passed = false;
}

$query_log = implode("\n", $wpdb->queries);
if (strpos($query_log, '`wp_postmeta`') === false) {
    echo "FAIL: Main-source search should query the server-selected wp_postmeta table.\n";
    $passed = false;
}

if (!$passed) {
    exit(1);
}

echo "PASS: Main-source search reads filtered records from wp_postmeta.\n";

$required_functions = array(
    'wppc_get_record_by_id',
    'wppc_add_main_postmeta_record',
    'wppc_update_main_postmeta_record',
    'wppc_delete_main_postmeta_record',
    'wppc_is_data_source_action_allowed',
);
foreach ($required_functions as $required_function) {
    if (!function_exists($required_function)) {
        echo "FAIL: Missing main data-manager behavior: {$required_function}.\n";
        exit(1);
    }
}

$wpdb->queries = array();
$skipped_result = wppc_get_table_records('', '', '', '', 1, 20, 'main');
if (empty($skipped_result['query_skipped']) || !empty($wpdb->queries)) {
    echo "FAIL: An unfiltered main-table request should not scan wp_postmeta.\n";
    exit(1);
}
echo "PASS: Unfiltered main-table requests do not scan wp_postmeta.\n";

$record = wppc_get_record_by_id('', 9, 'main');
if (!$record || (int) $record['post_id'] !== 42) {
    echo "FAIL: Main-source record lookup should resolve rows by meta_id.\n";
    exit(1);
}
echo "PASS: Main-source record lookup resolves a row by meta_id.\n";

$add_result = wppc_add_main_postmeta_record(42, 'catalog_path', 'C:\\catalog');
if ($add_result !== 77) {
    echo "FAIL: Adding an authorized main postmeta row should return its meta_id.\n";
    exit(1);
}
$add_call = $wppc_test_metadata_calls['add'][0] ?? array();
if (
    ($add_call['meta_type'] ?? '') !== 'post'
    || ($add_call['post_id'] ?? 0) !== 42
    || ($add_call['meta_value'] ?? '') !== 'C:\\catalog'
    || !empty($add_call['unique'])
) {
    echo "FAIL: Main postmeta add should preserve literal slashes and allow duplicate keys.\n";
    exit(1);
}
echo "PASS: Main postmeta add preserves scalar values and duplicate-key semantics.\n";

$missing_post_result = wppc_add_main_postmeta_record(999, 'price', '100');
$unauthorized_result = wppc_add_main_postmeta_record(43, 'price', '100');
$serialized_add_result = wppc_add_main_postmeta_record(42, 'settings', 'a:1:{s:3:"foo";s:3:"bar";}');
$malformed_key_result = wppc_add_main_postmeta_record(42, array('price'), '100');
$malformed_post_id_result = wppc_add_main_postmeta_record('42abc', 'price', '100');
if (
    !is_wp_error($missing_post_result)
    || !is_wp_error($unauthorized_result)
    || !is_wp_error($serialized_add_result)
    || !is_wp_error($malformed_key_result)
    || !is_wp_error($malformed_post_id_result)
) {
    echo "FAIL: Main postmeta add should reject missing posts, unauthorized posts, serialized input, malformed keys, and malformed post IDs.\n";
    exit(1);
}
if (count($wppc_test_metadata_calls['add']) !== 1) {
    echo "FAIL: Rejected main postmeta additions must not reach WordPress persistence.\n";
    exit(1);
}
echo "PASS: Main postmeta add rejects invalid, unauthorized, serialized, and malformed input.\n";

$update_result = wppc_update_main_postmeta_record(9, 'sale_price', '250');
if ($update_result !== true) {
    echo "FAIL: Updating an authorized scalar main postmeta row should succeed.\n";
    exit(1);
}
$update_call = $wppc_test_metadata_calls['update'][0] ?? array();
if (
    ($update_call['meta_type'] ?? '') !== 'post'
    || ($update_call['meta_id'] ?? 0) !== 9
    || ($update_call['meta_key'] ?? '') !== 'sale_price'
    || ($update_call['meta_value'] ?? '') !== '250'
) {
    echo "FAIL: Main postmeta update should target only the requested meta_id and writable fields.\n";
    exit(1);
}
echo "PASS: Main postmeta update derives the locked post_id from meta_id.\n";

$malformed_update_result = wppc_update_main_postmeta_record('9abc', 'sale_price', '251');
if (!is_wp_error($malformed_update_result) || count($wppc_test_metadata_calls['update']) !== 1) {
    echo "FAIL: Main postmeta update should reject malformed meta IDs before persistence.\n";
    exit(1);
}

$serialized_update_result = wppc_update_main_postmeta_record(10, 'settings', 'changed');
$unauthorized_update_result = wppc_update_main_postmeta_record(11, 'private_note', 'changed');
if (!is_wp_error($serialized_update_result) || !is_wp_error($unauthorized_update_result)) {
    echo "FAIL: Main postmeta update should reject serialized and unauthorized rows.\n";
    exit(1);
}
if (count($wppc_test_metadata_calls['update']) !== 1) {
    echo "FAIL: Rejected main postmeta updates must not reach WordPress persistence.\n";
    exit(1);
}
echo "PASS: Main postmeta update rejects serialized and unauthorized rows.\n";

$delete_result = wppc_delete_main_postmeta_record(9);
$malformed_delete_result = wppc_delete_main_postmeta_record('9abc');
$unauthorized_delete_result = wppc_delete_main_postmeta_record(11);
if ($delete_result !== true || !is_wp_error($malformed_delete_result) || !is_wp_error($unauthorized_delete_result)) {
    echo "FAIL: Main postmeta delete should allow authorized rows and reject malformed or unauthorized rows.\n";
    exit(1);
}
if (count($wppc_test_metadata_calls['delete']) !== 1) {
    echo "FAIL: Rejected main postmeta deletes must not reach WordPress persistence.\n";
    exit(1);
}
echo "PASS: Main postmeta delete is limited to authorized individual rows.\n";

if (
    !wppc_is_data_source_action_allowed('main', 'save_record')
    || !wppc_is_data_source_action_allowed('main', 'delete_record')
    || wppc_is_data_source_action_allowed('main', 'bulk_delete')
    || wppc_is_data_source_action_allowed('main', 'truncate_table')
) {
    echo "FAIL: Main-source action policy should allow individual CRUD but block destructive bulk actions.\n";
    exit(1);
}
echo "PASS: Main-source action policy blocks bulk delete and truncate.\n";

exit(0);
