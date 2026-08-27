<?php
// Non-AJAX admin-action regression tests for main wp_postmeta management.

if (isset($argv[1]) && strpos($argv[1], '--case=') === 0) {
    $case = substr($argv[1], strlen('--case='));

    define('ABSPATH', __DIR__ . '/../');
    define('ARRAY_A', 'ARRAY_A');

    class WP_Error
    {
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->message = $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }

    $wppc_test_redirect = '';
    $wppc_test_metadata_calls = array('add' => 0, 'update' => 0, 'delete' => 0);

    function plugin_dir_path($file)
    {
        return dirname($file) . DIRECTORY_SEPARATOR;
    }

    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }

    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }

    function is_admin()
    {
        return true;
    }

    function current_user_can($capability, ...$args)
    {
        if ($capability === 'manage_options') {
            return true;
        }
        return $capability === 'edit_post' && isset($args[0]) && (int) $args[0] === 42;
    }

    function check_admin_referer($action)
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
        return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
    }

    function wp_slash($value)
    {
        return is_array($value) ? array_map('wp_slash', $value) : addslashes((string) $value);
    }

    function absint($value)
    {
        return abs((int) $value);
    }

    function get_option($name, $default = false)
    {
        return $name === 'wppc_table_registry' ? array('product') : $default;
    }

    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }

    function add_query_arg($args, $url)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
    }

    function wp_safe_redirect($url)
    {
        global $wppc_test_redirect;
        $wppc_test_redirect = (string) $url;
        return true;
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
        $wppc_test_metadata_calls['add']++;
        return 77;
    }

    function update_metadata_by_mid($meta_type, $meta_id, $meta_value, $meta_key = false)
    {
        global $wppc_test_metadata_calls;
        $wppc_test_metadata_calls['update']++;
        return true;
    }

    function delete_metadata_by_mid($meta_type, $meta_id)
    {
        global $wppc_test_metadata_calls;
        $wppc_test_metadata_calls['delete']++;
        return true;
    }

    class MockWpdbMainAdminAction
    {
        public $prefix = 'wp_';
        public $postmeta = 'wp_postmeta';
        public $insert_count = 0;
        public $update_count = 0;
        public $delete_count = 0;
        public $query_count = 0;

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
            return strpos($query, 'SHOW TABLES LIKE') !== false ? 'wp_postmeta_product' : 1;
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

        public function insert($table, $data, $format = null)
        {
            $this->insert_count++;
            return 1;
        }

        public function update($table, $data, $where, $format = null, $where_format = null)
        {
            $this->update_count++;
            return 1;
        }

        public function delete($table, $where, $format = null)
        {
            $this->delete_count++;
            return 1;
        }

        public function query($query)
        {
            $this->query_count++;
            return 1;
        }
    }

    $wpdb = new MockWpdbMainAdminAction();

    $base_request = array(
        'page' => 'wppc-data-manager',
        'source' => 'main',
        'table' => 'product',
    );

    if ($case === 'add') {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'save_record',
            'meta_id' => '0',
            'post_id' => '42',
            'meta_key' => 'price',
            'meta_value' => '250',
        ));
    } elseif ($case === 'malformed_add') {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'save_record',
            'meta_id' => '0',
            'post_id' => '42abc',
            'meta_key' => 'price',
            'meta_value' => '250',
        ));
    } elseif ($case === 'update') {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'save_record',
            'meta_id' => '9',
            'post_id' => '999',
            'meta_key' => 'sale_price',
            'meta_value' => '275',
        ));
    } elseif ($case === 'malformed_update') {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'save_record',
            'meta_id' => '9abc',
            'post_id' => '42',
            'meta_key' => 'sale_price',
            'meta_value' => '275',
        ));
    } elseif ($case === 'delete') {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'delete_record',
            'meta_id' => '9',
        ));
    } elseif ($case === 'malformed_delete') {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'delete_record',
            'meta_id' => '9abc',
        ));
    } elseif ($case === 'bulk_delete') {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'bulk_delete',
            'bulk_ids' => array('9'),
        ));
    } else {
        $_POST = array_merge($base_request, array(
            'wppc_action' => 'truncate_table',
        ));
    }
    $_GET = array('source' => 'custom');
    $_REQUEST = array_merge($_POST, $_GET);

    register_shutdown_function(function () use ($case, $wpdb) {
        global $wppc_test_metadata_calls, $wppc_test_redirect;

        $success_redirect = strpos($wppc_test_redirect, 'wppc_notice=success') !== false;
        $error_redirect = strpos($wppc_test_redirect, 'wppc_notice=error') !== false;
        $direct_writes = $wpdb->insert_count + $wpdb->update_count + $wpdb->delete_count + $wpdb->query_count;

        $passed = false;
        if ($case === 'add') {
            $passed = $wppc_test_metadata_calls['add'] === 1 && $direct_writes === 0 && $success_redirect;
        } elseif ($case === 'malformed_add') {
            $passed = array_sum($wppc_test_metadata_calls) === 0 && $direct_writes === 0 && $error_redirect;
        } elseif ($case === 'update') {
            $passed = $wppc_test_metadata_calls['update'] === 1 && $direct_writes === 0 && $success_redirect;
        } elseif ($case === 'malformed_update') {
            $passed = array_sum($wppc_test_metadata_calls) === 0 && $direct_writes === 0 && $error_redirect;
        } elseif ($case === 'delete') {
            $passed = $wppc_test_metadata_calls['delete'] === 1 && $direct_writes === 0 && $success_redirect;
        } elseif ($case === 'malformed_delete') {
            $passed = array_sum($wppc_test_metadata_calls) === 0 && $direct_writes === 0 && $error_redirect;
        } else {
            $passed = array_sum($wppc_test_metadata_calls) === 0 && $direct_writes === 0 && $error_redirect;
        }

        echo ($passed ? 'PASS:' : 'FAIL:') . $case . PHP_EOL;
    });

    require_once __DIR__ . '/../wp-table-postmeta-custom.php';
    wppc_handle_admin_actions();
    exit;
}

$cases = array('add', 'malformed_add', 'update', 'malformed_update', 'delete', 'malformed_delete', 'bulk_delete', 'truncate_table');
$failed = false;

foreach ($cases as $case) {
    $command = array(PHP_BINARY, __FILE__, '--case=' . $case);
    $descriptor_spec = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $process = proc_open($command, $descriptor_spec, $pipes, __DIR__);
    if (!is_resource($process)) {
        echo "FAIL: Could not start admin-action test case {$case}.\n";
        $failed = true;
        continue;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    echo $stdout;
    if ($stderr !== '') {
        echo $stderr;
    }
    if (strpos($stdout, 'PASS:' . $case) === false) {
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo "All main postmeta non-AJAX admin action tests passed.\n";
exit(0);
