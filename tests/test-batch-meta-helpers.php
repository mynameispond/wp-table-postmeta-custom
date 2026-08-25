<?php
// Test script for Batch Meta Helper Functions

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('WPPC_TABLE_REGISTRY_OPTION')) {
    define('WPPC_TABLE_REGISTRY_OPTION', 'wppc_table_registry');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Mock WordPress functions and classes
class MockWpdbBatch {
    public $prefix = 'wp_';
    public $queries = array();
    public $table_data = array(); // [table_name][post_id][meta_key] = meta_value

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

    public $meta_id_map = array(); // meta_id => [table, post_id, key]
    public $next_id = 1;

    public function get_var($query) {
        $this->queries[] = $query;
        if (strpos($query, 'SHOW TABLES LIKE') !== false) {
            $cleaned = str_replace('\\_', '_', $query);
            if (strpos($cleaned, 'wp_postmeta_product') !== false) {
                return 'wp_postmeta_product';
            }
            return null;
        }
        if (strpos($query, 'SELECT meta_id FROM') !== false) {
            preg_match("/FROM `([^`]+)` WHERE post_id = '(\d+)' AND meta_key = '([^']+)'/", $query, $m);
            if (!empty($m)) {
                $tbl = $m[1];
                $pid = (int)$m[2];
                $k = $m[3];
                if (isset($this->table_data[$tbl][$pid][$k])) {
                    $id = $this->next_id++;
                    $this->meta_id_map[$id] = array($tbl, $pid, $k);
                    return $id;
                }
            }
            return null;
        }
        return null;
    }

    public function get_results($query, $output = 'OBJECT') {
        $this->queries[] = $query;
        $rows = array();
        if (strpos($query, 'FROM `wp_postmeta_product`') !== false) {
            preg_match("/post_id = '(\d+)'/", $query, $m);
            $pid = !empty($m) ? (int)$m[1] : 0;
            
            // Check if there is an IN clause
            $in_keys = null;
            if (preg_match("/meta_key IN \(([^)]+)\)/", $query, $km)) {
                $raw_keys = explode(',', $km[1]);
                $in_keys = array_map(function($item) {
                    return trim($item, " '\"");
                }, $raw_keys);
            }

            if (isset($this->table_data['wp_postmeta_product'][$pid])) {
                foreach ($this->table_data['wp_postmeta_product'][$pid] as $k => $v) {
                    if ($in_keys === null || in_array($k, $in_keys, true)) {
                        $rows[] = array(
                            'meta_key' => $k,
                            'meta_value' => $v,
                        );
                    }
                }
            }
        }
        return $rows;
    }

    public function insert($table, $data, $format = null) {
        $this->queries[] = "INSERT INTO {$table}";
        $this->table_data[$table][$data['post_id']][$data['meta_key']] = $data['meta_value'];
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->queries[] = "UPDATE {$table}";
        if (isset($where['meta_id'], $this->meta_id_map[$where['meta_id']])) {
            list($tbl, $pid, $k) = $this->meta_id_map[$where['meta_id']];
            $this->table_data[$tbl][$pid][$k] = $data['meta_value'];
            return 1;
        }
        return 1;
    }

    public function delete($table, $where, $format = null) {
        $this->queries[] = "DELETE FROM {$table}";
        if (isset($where['post_id'], $where['meta_key'])) {
            unset($this->table_data[$table][$where['post_id']][$where['meta_key']]);
            return 1;
        }
        return 1;
    }

    public function query($query) {
        $this->queries[] = $query;
        if (strpos($query, 'DELETE FROM') !== false && preg_match("/WHERE post_id = '(\d+)' AND meta_key IN \(([^)]+)\)/", $query, $m)) {
            $pid = (int)$m[1];
            $raw_keys = explode(',', $m[2]);
            $keys = array_map(function($item) { return trim($item, " '\""); }, $raw_keys);
            $deleted = 0;
            foreach ($keys as $k) {
                if (isset($this->table_data['wp_postmeta_product'][$pid][$k])) {
                    unset($this->table_data['wp_postmeta_product'][$pid][$k]);
                    $deleted++;
                }
            }
            return $deleted;
        }
        return true;
    }
}

global $wpdb, $mock_options;
$wpdb = new MockWpdbBatch();
$mock_options = array(
    WPPC_TABLE_REGISTRY_OPTION => array('product'),
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

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0) {
        return json_encode($data, $options);
    }
}

require_once __DIR__ . '/../includes/db-helpers.php';

// Check functions existence
$required_functions = array(
    'wppc_get_post_custom',
    'wppc_get_post_meta_batch',
    'wppc_update_post_meta_batch',
    'wppc_delete_post_meta_batch',
);

foreach ($required_functions as $func) {
    if (!function_exists($func)) {
        echo "FAIL: Function {$func} does not exist!\n";
        exit(1);
    }
}

// Pre-fill test data
$wpdb->table_data['wp_postmeta_product'][10] = array(
    'price' => '299.00',
    'sku' => 'PROD-10',
    'color' => 'blue',
    'stock' => '50',
);

// Test 1: wppc_get_post_custom
$all_meta = wppc_get_post_custom('product', 10);
if ($all_meta !== array('price' => '299.00', 'sku' => 'PROD-10', 'color' => 'blue', 'stock' => '50')) {
    echo "FAIL: wppc_get_post_custom returned incorrect data: " . json_encode($all_meta) . "\n";
    exit(1);
}
echo "PASS: wppc_get_post_custom returns all meta for post\n";

// Test 2: wppc_get_post_custom invalid input
$empty_meta = wppc_get_post_custom('product', 0);
if ($empty_meta !== array()) {
    echo "FAIL: wppc_get_post_custom with post_id 0 should return empty array\n";
    exit(1);
}
echo "PASS: wppc_get_post_custom handles invalid post_id correctly\n";

// Test 3: wppc_get_post_meta_batch
$subset_meta = wppc_get_post_meta_batch('product', 10, array('price', 'color'));
if ($subset_meta !== array('price' => '299.00', 'color' => 'blue')) {
    echo "FAIL: wppc_get_post_meta_batch returned incorrect data: " . json_encode($subset_meta) . "\n";
    exit(1);
}
echo "PASS: wppc_get_post_meta_batch returns subset of meta keys\n";

// Test 4: wppc_update_post_meta_batch
$update_result = wppc_update_post_meta_batch('product', 10, array(
    'price' => '350.00',
    'brand' => 'Acme',
));
if (empty($update_result['updated']) || $update_result['updated'] !== 2) {
    echo "FAIL: wppc_update_post_meta_batch should report 2 updated keys: " . json_encode($update_result) . "\n";
    exit(1);
}
if ($wpdb->table_data['wp_postmeta_product'][10]['price'] !== '350.00' || $wpdb->table_data['wp_postmeta_product'][10]['brand'] !== 'Acme') {
    echo "FAIL: Data not stored properly in mock DB after update_post_meta_batch\n";
    exit(1);
}
echo "PASS: wppc_update_post_meta_batch inserts/updates multiple keys\n";

// Test 5: wppc_delete_post_meta_batch
$delete_count = wppc_delete_post_meta_batch('product', 10, array('price', 'sku'));
if ($delete_count !== 2) {
    echo "FAIL: wppc_delete_post_meta_batch should return 2 deleted rows, got {$delete_count}\n";
    exit(1);
}
if (isset($wpdb->table_data['wp_postmeta_product'][10]['price']) || isset($wpdb->table_data['wp_postmeta_product'][10]['sku'])) {
    echo "FAIL: Keys were not deleted from table data\n";
    exit(1);
}
if (!isset($wpdb->table_data['wp_postmeta_product'][10]['color'])) {
    echo "FAIL: Unrelated keys should remain untouched\n";
    exit(1);
}
echo "PASS: wppc_delete_post_meta_batch deletes specified keys in batch\n";

echo "\nAll batch meta helper tests passed!\n";
exit(0);
