<?php
/**
 * Plugin Name: WP Table Postmeta Custom
 * Description: จัดการหลายตาราง postmeta แบบกำหนด slug ได้ พร้อมรองรับ meta_query_custom ใน WP_Query
 * Version: 0.2.1
 * Author: Peera
 * Text Domain: wp-table-postmeta-custom
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPPC_VERSION', '0.2.1');
define('WPPC_DEFAULT_SLUG', 'wppc');
define('WPPC_TABLE_REGISTRY_OPTION', 'wppc_table_registry');
define('WPPC_SCHEMA_OPTION', 'wppc_schema_registry');
define('WPPC_SYNC_STATE_OPTION', 'wppc_sync_state');

/**
 * ตรวจสอบ slug ให้ปลอดภัยกับชื่อ table/function
 */
function wppc_normalize_slug($slug)
{
    $slug = strtolower(trim((string) $slug));
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
        return '';
    }

    return $slug;
}

function wppc_is_valid_slug($slug)
{
    return wppc_normalize_slug($slug) !== '';
}

function wppc_normalize_meta_key($meta_key)
{
    $meta_key = sanitize_text_field(wp_unslash((string) $meta_key));
    $meta_key = trim($meta_key);
    if ($meta_key === '') {
        return '';
    }

    if (strlen($meta_key) > 255) {
        $meta_key = substr($meta_key, 0, 255);
    }

    return $meta_key;
}

function wppc_get_registered_slugs()
{
    $stored = get_option(WPPC_TABLE_REGISTRY_OPTION, array(WPPC_DEFAULT_SLUG));
    if (!is_array($stored)) {
        $stored = array(WPPC_DEFAULT_SLUG);
    }

    $normalized = array();
    foreach ($stored as $slug) {
        $slug = wppc_normalize_slug($slug);
        if ($slug !== '') {
            $normalized[] = $slug;
        }
    }

    if (!in_array(WPPC_DEFAULT_SLUG, $normalized, true)) {
        $normalized[] = WPPC_DEFAULT_SLUG;
    }

    $normalized = array_values(array_unique($normalized));
    return $normalized;
}

function wppc_save_registered_slugs($slugs)
{
    $normalized = array();
    foreach ((array) $slugs as $slug) {
        $slug = wppc_normalize_slug($slug);
        if ($slug !== '') {
            $normalized[] = $slug;
        }
    }

    if (!in_array(WPPC_DEFAULT_SLUG, $normalized, true)) {
        $normalized[] = WPPC_DEFAULT_SLUG;
    }

    $normalized = array_values(array_unique($normalized));
    update_option(WPPC_TABLE_REGISTRY_OPTION, $normalized, false);

    return $normalized;
}

function wppc_get_table_name($slug)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        $slug = WPPC_DEFAULT_SLUG;
    }

    return $wpdb->prefix . 'postmeta_' . $slug;
}

function wppc_get_dynamic_function_names($slug)
{
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return array();
    }

    return array(
        'get_post_meta_' . $slug,
        'update_post_meta_' . $slug,
        'delete_post_meta_' . $slug,
    );
}

function wppc_slug_has_dynamic_function_collision($slug)
{
    foreach (wppc_get_dynamic_function_names($slug) as $function_name) {
        if (function_exists($function_name)) {
            return true;
        }
    }

    return false;
}

function wppc_validate_slug_for_table_create($slug)
{
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
    }

    $registered_slugs = wppc_get_registered_slugs();
    $is_registered = in_array($slug, $registered_slugs, true);
    if (!$is_registered && wppc_slug_has_dynamic_function_collision($slug)) {
        return new WP_Error('function_name_collision', 'รหัสตารางนี้ชนกับชื่อฟังก์ชันที่มีอยู่แล้ว กรุณาใช้รหัสอื่น');
    }

    $table_name = wppc_get_table_name($slug);
    if (strlen($table_name) > 64) {
        return new WP_Error('table_name_too_long', 'ชื่อตารางจริงยาวเกินข้อจำกัดของ MySQL กรุณาใช้รหัสตารางที่สั้นลง');
    }

    return true;
}

function wppc_escape_identifier($identifier)
{
    return '`' . str_replace('`', '``', (string) $identifier) . '`';
}

function wppc_create_meta_table($slug)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
    }

    $validation = wppc_validate_slug_for_table_create($slug);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $table_name = wppc_get_table_name($slug);
    $charset_collate = $wpdb->get_charset_collate();
    $escaped_table = wppc_escape_identifier($table_name);
    $sql = "CREATE TABLE {$escaped_table} (
        meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        meta_key varchar(255) DEFAULT NULL,
        meta_value longtext,
        PRIMARY KEY  (meta_id),
        KEY post_id (post_id),
        KEY meta_key (meta_key(191))
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    if (!wppc_table_exists($slug)) {
        $message = 'ไม่สามารถสร้างตารางได้';
        if (!empty($wpdb->last_error)) {
            $message .= ': ' . $wpdb->last_error;
        }
        return new WP_Error('table_create_failed', $message);
    }

    $slugs = wppc_get_registered_slugs();
    if (!in_array($slug, $slugs, true)) {
        $slugs[] = $slug;
        wppc_save_registered_slugs($slugs);
    }

    return true;
}

function wppc_drop_meta_table($slug)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '' || $slug === WPPC_DEFAULT_SLUG) {
        return new WP_Error('cannot_drop_table', 'ไม่สามารถลบตารางเริ่มต้นได้');
    }

    $table_name = wppc_get_table_name($slug);
    $drop_result = $wpdb->query('DROP TABLE IF EXISTS ' . wppc_escape_identifier($table_name));
    if ($drop_result === false) {
        $message = 'ไม่สามารถลบตารางได้';
        if (!empty($wpdb->last_error)) {
            $message .= ': ' . $wpdb->last_error;
        }
        return new WP_Error('table_drop_failed', $message);
    }

    $slugs = array_values(array_filter(wppc_get_registered_slugs(), function ($registered_slug) use ($slug) {
        return $registered_slug !== $slug;
    }));
    wppc_save_registered_slugs($slugs);

    $schemas = wppc_get_schema_registry();
    if (isset($schemas[$slug])) {
        unset($schemas[$slug]);
        update_option(WPPC_SCHEMA_OPTION, $schemas, false);
    }

    $sync_states = get_option(WPPC_SYNC_STATE_OPTION, array());
    if (is_array($sync_states) && isset($sync_states[$slug])) {
        unset($sync_states[$slug]);
        update_option(WPPC_SYNC_STATE_OPTION, $sync_states, false);
    }

    return true;
}

function wppc_table_exists($slug)
{
    global $wpdb;
    $table_name = wppc_get_table_name($slug);
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)));
    return $exists === $table_name;
}

function wppc_maybe_ensure_default_table()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!wppc_table_exists(WPPC_DEFAULT_SLUG)) {
        wppc_create_meta_table(WPPC_DEFAULT_SLUG);
    }
}

function wppc_activate_plugin()
{
    wppc_create_meta_table(WPPC_DEFAULT_SLUG);
    wppc_save_registered_slugs(wppc_get_registered_slugs());
}
register_activation_hook(__FILE__, 'wppc_activate_plugin');
add_action('init', 'wppc_maybe_ensure_default_table');

function wppc_get_schema_registry()
{
    $schemas = get_option(WPPC_SCHEMA_OPTION, array());
    if (!is_array($schemas)) {
        return array();
    }

    return $schemas;
}

function wppc_get_table_schema($slug)
{
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return array();
    }

    $schemas = wppc_get_schema_registry();
    $schema = isset($schemas[$slug]) && is_array($schemas[$slug]) ? $schemas[$slug] : array();

    return $schema;
}

function wppc_save_table_schema($slug, $schema)
{
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
    }

    $normalized_schema = array();
    foreach ((array) $schema as $meta_key => $rule) {
        $meta_key = wppc_normalize_meta_key($meta_key);
        if ($meta_key === '') {
            continue;
        }

        $type = isset($rule['type']) ? wppc_normalize_schema_type($rule['type']) : 'text';
        $required = !empty($rule['required']) ? 1 : 0;
        $normalized_schema[$meta_key] = array(
            'type' => $type,
            'required' => $required,
        );
    }

    $schemas = wppc_get_schema_registry();
    $schemas[$slug] = $normalized_schema;
    update_option(WPPC_SCHEMA_OPTION, $schemas, false);

    return true;
}

function wppc_normalize_schema_type($type)
{
    $allowed = array('text', 'number', 'boolean', 'json', 'date', 'datetime');
    $type = strtolower(trim((string) $type));
    if (!in_array($type, $allowed, true)) {
        return 'text';
    }

    return $type;
}

function wppc_validate_meta_value_by_type($type, $value)
{
    switch ($type) {
        case 'number':
            return is_numeric($value);
        case 'boolean':
            return $value === '0' || $value === '1' || $value === 0 || $value === 1 || is_bool($value);
        case 'json':
            if (is_array($value) || is_object($value)) {
                return true;
            }
            if (!is_string($value) || $value === '') {
                return false;
            }
            json_decode($value);
            return json_last_error() === JSON_ERROR_NONE;
        case 'date':
            if (!is_string($value)) {
                return false;
            }
            $date = DateTime::createFromFormat('Y-m-d', $value);
            return $date && $date->format('Y-m-d') === $value;
        case 'datetime':
            if (!is_string($value)) {
                return false;
            }
            $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);
            return $date && $date->format('Y-m-d H:i:s') === $value;
        case 'text':
        default:
            return true;
    }
}

function wppc_validate_meta_with_schema($slug, $meta_key, $meta_value)
{
    $schema = wppc_get_table_schema($slug);
    if (empty($schema)) {
        return true;
    }

    if (!isset($schema[$meta_key])) {
        return true;
    }

    $rule = $schema[$meta_key];
    $type = isset($rule['type']) ? wppc_normalize_schema_type($rule['type']) : 'text';
    $required = !empty($rule['required']);
    $is_empty = $meta_value === null || (is_string($meta_value) && trim($meta_value) === '');

    if ($required && $is_empty) {
        return new WP_Error('required_meta_missing', 'ค่าบังคับห้ามว่าง');
    }

    if (!$required && $is_empty) {
        return true;
    }

    if (!wppc_validate_meta_value_by_type($type, $meta_value)) {
        return new WP_Error('invalid_meta_type', 'รูปแบบข้อมูลไม่ตรงตาม schema');
    }

    return true;
}

function wppc_serialize_meta_value_for_store($meta_value)
{
    return maybe_serialize($meta_value);
}

function wppc_unserialize_meta_value_from_store($meta_value)
{
    return maybe_unserialize($meta_value);
}

function wppc_get_schema_type_for_meta_key($slug, $meta_key)
{
    $meta_key = wppc_normalize_meta_key($meta_key);
    if ($meta_key === '') {
        return '';
    }

    $schema = wppc_get_table_schema($slug);
    if (!isset($schema[$meta_key]) || !is_array($schema[$meta_key])) {
        return '';
    }

    return isset($schema[$meta_key]['type']) ? wppc_normalize_schema_type($schema[$meta_key]['type']) : 'text';
}

function wppc_json_encode_meta_value_for_store($meta_value)
{
    if (is_string($meta_value)) {
        $trimmed = trim($meta_value);
        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $trimmed;
        }

        $encoded = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
        return $encoded === false ? $trimmed : $encoded;
    }

    $encoded = wp_json_encode($meta_value, JSON_UNESCAPED_UNICODE);
    return $encoded === false ? '' : $encoded;
}

function wppc_prepare_meta_value_for_store($slug, $meta_key, $meta_value)
{
    if (wppc_get_schema_type_for_meta_key($slug, $meta_key) === 'json') {
        return wppc_json_encode_meta_value_for_store($meta_value);
    }

    return wppc_serialize_meta_value_for_store($meta_value);
}

function wppc_get_meta_value_from_store($slug, $meta_key, $stored_value)
{
    $value = wppc_unserialize_meta_value_from_store($stored_value);
    if (wppc_get_schema_type_for_meta_key($slug, $meta_key) !== 'json' || !is_string($value)) {
        return $value;
    }

    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function wppc_get_post_meta($table_slug, $post_id, $meta_key, $from_main = false)
{
    global $wpdb;
    $table_slug = wppc_normalize_slug($table_slug);
    $post_id = absint($post_id);
    $meta_key = wppc_normalize_meta_key($meta_key);

    if ($post_id <= 0 || $meta_key === '' || $table_slug === '') {
        return '';
    }

    $table_name = wppc_get_table_name($table_slug);
    $table_sql = wppc_escape_identifier($table_name);
    if (!wppc_table_exists($table_slug)) {
        return $from_main ? get_post_meta($post_id, $meta_key, true) : '';
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$table_sql} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $post_id,
            $meta_key
        ),
        ARRAY_A
    );

    if (empty($row) || !array_key_exists('meta_value', $row)) {
        if ($from_main) {
            return get_post_meta($post_id, $meta_key, true);
        }
        return '';
    }

    $meta_value = $row['meta_value'];
    return wppc_get_meta_value_from_store($table_slug, $meta_key, $meta_value);
}

function wppc_update_post_meta($table_slug, $post_id, $meta_key, $meta_value)
{
    global $wpdb;
    $table_slug = wppc_normalize_slug($table_slug);
    $post_id = absint($post_id);
    $meta_key = wppc_normalize_meta_key($meta_key);

    if ($table_slug === '' || $post_id <= 0 || $meta_key === '') {
        return false;
    }

    $validation = wppc_validate_meta_with_schema($table_slug, $meta_key, $meta_value);
    if (is_wp_error($validation)) {
        return $validation;
    }

    if (!wppc_table_exists($table_slug)) {
        $create_result = wppc_create_meta_table($table_slug);
        if (is_wp_error($create_result)) {
            return $create_result;
        }
    }

    $table_name = wppc_get_table_name($table_slug);
    $table_sql = wppc_escape_identifier($table_name);
    $stored_value = wppc_prepare_meta_value_for_store($table_slug, $meta_key, $meta_value);

    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_id FROM {$table_sql} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $post_id,
            $meta_key
        )
    );

    if ($existing_id) {
        $updated = $wpdb->update(
            $table_name,
            array('meta_value' => $stored_value),
            array('meta_id' => absint($existing_id)),
            array('%s'),
            array('%d')
        );
        return $updated !== false;
    }

    return (bool) $wpdb->insert(
        $table_name,
        array(
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'meta_value' => $stored_value,
        ),
        array('%d', '%s', '%s')
    );
}

function wppc_delete_post_meta($table_slug, $post_id, $meta_key, $meta_value = null)
{
    global $wpdb;
    $table_slug = wppc_normalize_slug($table_slug);
    $post_id = absint($post_id);
    $meta_key = wppc_normalize_meta_key($meta_key);

    if ($table_slug === '' || $post_id <= 0 || $meta_key === '') {
        return false;
    }

    if (!wppc_table_exists($table_slug)) {
        return false;
    }

    $table_name = wppc_get_table_name($table_slug);
    $table_sql = wppc_escape_identifier($table_name);
    if ($meta_value !== null) {
        return (bool) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_sql} WHERE post_id = %d AND meta_key = %s AND meta_value = %s",
                $post_id,
                $meta_key,
                wppc_prepare_meta_value_for_store($table_slug, $meta_key, $meta_value)
            )
        );
    }

    return (bool) $wpdb->delete(
        $table_name,
        array(
            'post_id' => $post_id,
            'meta_key' => $meta_key,
        ),
        array('%d', '%s')
    );
}

/**
 * Backward compatibility for default slug.
 */
function get_post_meta_custom($post_id, $meta_key, $from_main = false)
{
    return wppc_get_post_meta(WPPC_DEFAULT_SLUG, $post_id, $meta_key, $from_main);
}

function update_post_meta_custom($post_id, $meta_key, $meta_value)
{
    return wppc_update_post_meta(WPPC_DEFAULT_SLUG, $post_id, $meta_key, $meta_value);
}

function delete_post_meta_custom($post_id, $meta_key, $meta_value = null)
{
    return wppc_delete_post_meta(WPPC_DEFAULT_SLUG, $post_id, $meta_key, $meta_value);
}

/**
 * Dynamic function registration:
 * get_post_meta_<slug>, update_post_meta_<slug>, delete_post_meta_<slug>
 */
function wppc_register_dynamic_functions()
{
    $slugs = wppc_get_registered_slugs();
    foreach ($slugs as $slug) {
        $slug = wppc_normalize_slug($slug);
        if ($slug === '') {
            continue;
        }

        $get_fn = 'get_post_meta_' . $slug;
        if (!function_exists($get_fn)) {
            $slug_literal = var_export($slug, true);
            eval('function ' . $get_fn . '($post_id, $meta_key, $from_main = false) { return wppc_get_post_meta(' . $slug_literal . ', $post_id, $meta_key, $from_main); }');
        }

        $update_fn = 'update_post_meta_' . $slug;
        if (!function_exists($update_fn)) {
            $slug_literal = var_export($slug, true);
            eval('function ' . $update_fn . '($post_id, $meta_key, $meta_value) { return wppc_update_post_meta(' . $slug_literal . ', $post_id, $meta_key, $meta_value); }');
        }

        $delete_fn = 'delete_post_meta_' . $slug;
        if (!function_exists($delete_fn)) {
            $slug_literal = var_export($slug, true);
            eval('function ' . $delete_fn . '($post_id, $meta_key, $meta_value = null) { return wppc_delete_post_meta(' . $slug_literal . ', $post_id, $meta_key, $meta_value); }');
        }
    }
}
add_action('init', 'wppc_register_dynamic_functions', 20);

function wppc_get_allowed_meta_compare_ops()
{
    return array(
        '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE',
        'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS',
    );
}

function wppc_get_allowed_meta_cast_types()
{
    return array(
        'CHAR', 'BINARY', 'SIGNED', 'UNSIGNED', 'DECIMAL(20,6)',
        'DATE', 'DATETIME', 'TIME',
    );
}

function wppc_build_single_meta_clause_sql($table_name, $clause, $alias_prefix, $index)
{
    global $wpdb;
    $alias = $alias_prefix . absint($index);
    $posts_table = $wpdb->posts;
    $table_sql = wppc_escape_identifier($table_name);

    if (!is_array($clause)) {
        return '';
    }

    $meta_key = isset($clause['key']) ? wppc_normalize_meta_key($clause['key']) : '';
    if ($meta_key === '') {
        return '';
    }

    $compare = isset($clause['compare']) ? strtoupper(trim((string) $clause['compare'])) : '=';
    if (!in_array($compare, wppc_get_allowed_meta_compare_ops(), true)) {
        $compare = '=';
    }

    $cast_type = isset($clause['type']) ? strtoupper(trim((string) $clause['type'])) : 'CHAR';
    if ($cast_type === 'NUMERIC') {
        $cast_type = 'SIGNED';
    }
    if (!in_array($cast_type, wppc_get_allowed_meta_cast_types(), true)) {
        $cast_type = 'CHAR';
    }

    $value_expr = $cast_type === 'CHAR' ? "{$alias}.meta_value" : "CAST({$alias}.meta_value AS {$cast_type})";
    $sub_where_parts = array(
        $wpdb->prepare("{$alias}.post_id = {$posts_table}.ID AND {$alias}.meta_key = %s", $meta_key),
    );

    if ($compare === 'EXISTS') {
        return "EXISTS (SELECT 1 FROM {$table_sql} {$alias} WHERE " . implode(' AND ', $sub_where_parts) . ')';
    }

    if ($compare === 'NOT EXISTS') {
        return "NOT EXISTS (SELECT 1 FROM {$table_sql} {$alias} WHERE " . implode(' AND ', $sub_where_parts) . ')';
    }

    if (!array_key_exists('value', $clause)) {
        return '';
    }

    $value = $clause['value'];
    $condition_sql = '';

    switch ($compare) {
        case 'IN':
        case 'NOT IN':
            if (!is_array($value) || empty($value)) {
                return $compare === 'IN' ? '0=1' : '1=1';
            }
            $placeholders = implode(',', array_fill(0, count($value), '%s'));
            $prepared = $wpdb->prepare($placeholders, array_map('strval', $value));
            $condition_sql = "{$value_expr} {$compare} ({$prepared})";
            break;
        case 'BETWEEN':
        case 'NOT BETWEEN':
            if (!is_array($value) || count($value) < 2) {
                return $compare === 'BETWEEN' ? '0=1' : '1=1';
            }
            $first = (string) array_values($value)[0];
            $second = (string) array_values($value)[1];
            $condition_sql = $wpdb->prepare("{$value_expr} {$compare} %s AND %s", $first, $second);
            break;
        case 'LIKE':
        case 'NOT LIKE':
            $condition_sql = $wpdb->prepare("{$value_expr} {$compare} %s", '%' . $wpdb->esc_like((string) $value) . '%');
            break;
        default:
            $condition_sql = $wpdb->prepare("{$value_expr} {$compare} %s", (string) $value);
            break;
    }

    if ($condition_sql === '') {
        return '';
    }

    $sub_where_parts[] = $condition_sql;
    return "EXISTS (SELECT 1 FROM {$table_sql} {$alias} WHERE " . implode(' AND ', $sub_where_parts) . ')';
}

function wppc_build_meta_query_custom_sql($table_name, $meta_query, $alias_prefix = 'wppc_mq_', &$counter = 0)
{
    if (empty($meta_query) || !is_array($meta_query)) {
        return '';
    }

    $is_single_clause = array_key_exists('key', $meta_query);
    if ($is_single_clause) {
        $counter++;
        return wppc_build_single_meta_clause_sql($table_name, $meta_query, $alias_prefix, $counter);
    }

    $relation = isset($meta_query['relation']) ? strtoupper(trim((string) $meta_query['relation'])) : 'AND';
    if ($relation !== 'OR') {
        $relation = 'AND';
    }

    $parts = array();
    foreach ($meta_query as $key => $clause) {
        if ($key === 'relation') {
            continue;
        }
        if (!is_array($clause)) {
            continue;
        }

        $sql = wppc_build_meta_query_custom_sql($table_name, $clause, $alias_prefix, $counter);
        if ($sql !== '') {
            $parts[] = '(' . $sql . ')';
        }
    }

    if (empty($parts)) {
        return '';
    }

    return implode(" {$relation} ", $parts);
}

function wppc_filter_posts_where_for_meta_query_custom($where, $query)
{
    if (!($query instanceof WP_Query)) {
        return $where;
    }

    $meta_query_custom = $query->get('meta_query_custom');
    if (empty($meta_query_custom) || !is_array($meta_query_custom)) {
        return $where;
    }

    $table_slug = $query->get('meta_query_custom_table');
    if (!is_string($table_slug) || $table_slug === '') {
        $table_slug = WPPC_DEFAULT_SLUG;
    }
    $table_slug = wppc_normalize_slug($table_slug);
    if ($table_slug === '') {
        return $where;
    }

    if (!in_array($table_slug, wppc_get_registered_slugs(), true)) {
        return $where;
    }

    if (!wppc_table_exists($table_slug)) {
        return $where;
    }

    $table_name = wppc_get_table_name($table_slug);
    $counter = 0;
    $sql = wppc_build_meta_query_custom_sql($table_name, $meta_query_custom, 'wppc_mq_', $counter);
    if ($sql === '') {
        return $where;
    }

    $where .= " AND ({$sql})";
    return $where;
}
add_filter('posts_where', 'wppc_filter_posts_where_for_meta_query_custom', 10, 2);

function wppc_get_admin_pages()
{
    return array('wppc-overview', 'wppc-table-types', 'wppc-data-manager');
}

function wppc_is_wppc_admin_page($page = '')
{
    if ($page === '') {
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
    }
    return in_array($page, wppc_get_admin_pages(), true);
}

function wppc_admin_url($page, $args = array())
{
    return add_query_arg(array_merge(array('page' => $page), $args), admin_url('tools.php'));
}

function wppc_admin_redirect_with_notice($page, $notice_type, $message, $args = array())
{
    $notice_type = $notice_type === 'error' ? 'error' : 'success';
    $url = wppc_admin_url($page, array_merge($args, array(
        'wppc_notice' => $notice_type,
        'wppc_message' => rawurlencode($message),
    )));
    wp_safe_redirect($url);
    exit;
}

function wppc_render_admin_notice()
{
    if (!wppc_is_wppc_admin_page()) {
        return;
    }
    if (!isset($_GET['wppc_notice'], $_GET['wppc_message'])) {
        return;
    }

    $notice = sanitize_key(wp_unslash($_GET['wppc_notice']));
    $message = sanitize_text_field(rawurldecode(wp_unslash($_GET['wppc_message'])));
    if ($message === '') {
        return;
    }

    $class = $notice === 'error' ? 'notice notice-error' : 'notice notice-success is-dismissible';
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
}
add_action('admin_notices', 'wppc_render_admin_notice');

function wppc_get_admin_active_slug()
{
    $slugs = wppc_get_registered_slugs();
    $requested = isset($_REQUEST['table']) ? wppc_normalize_slug(wp_unslash($_REQUEST['table'])) : '';
    if ($requested !== '' && in_array($requested, $slugs, true)) {
        return $requested;
    }
    return isset($slugs[0]) ? $slugs[0] : WPPC_DEFAULT_SLUG;
}

function wppc_clamp_batch_size($batch_size)
{
    $batch_size = absint($batch_size);
    if ($batch_size < 10) {
        return 10;
    }
    if ($batch_size > 1000) {
        return 1000;
    }
    return $batch_size;
}

function wppc_get_schema_type_labels()
{
    return array(
        'text' => 'ข้อความ',
        'number' => 'ตัวเลข',
        'boolean' => 'บูลีน (0/1)',
        'json' => 'JSON',
        'date' => 'วันที่ (YYYY-MM-DD)',
        'datetime' => 'วันเวลา (YYYY-MM-DD HH:MM:SS)',
    );
}

function wppc_enqueue_admin_assets()
{
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $allowed_pages = array('wppc-overview', 'wppc-table-types', 'wppc-data-manager');
    if (!in_array($page, $allowed_pages, true)) {
        return;
    }

    wp_enqueue_style(
        'wppc-admin-css',
        plugin_dir_url(__FILE__) . 'assets/wppc-admin.css',
        array(),
        WPPC_VERSION
    );
}
add_action('admin_enqueue_scripts', 'wppc_enqueue_admin_assets');

function wppc_render_admin_view_tabs($active_page)
{
    $views = array(
        'wppc-overview' => array(
            'label' => 'ภาพรวม',
            'description' => 'สรุปสถานะและตัวเลขหลักของปลั๊กอิน',
        ),
        'wppc-table-types' => array(
            'label' => 'รายการประเภทตาราง',
            'description' => 'สร้างและลบตาราง postmeta ตาม slug',
        ),
        'wppc-data-manager' => array(
            'label' => 'จัดการข้อมูลตาราง',
            'description' => 'เพิ่ม แก้ไข ลบ และจัดการข้อมูลขั้นสูง',
        ),
    );

    echo '<nav class="wppc-main-nav" aria-label="WP Postmeta Custom Main Navigation">';
    foreach ($views as $view_key => $view) {
        $class = $active_page === $view_key ? 'wppc-main-nav-item is-active' : 'wppc-main-nav-item';
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url(wppc_admin_url($view_key)) . '">';
        echo '<span class="wppc-main-nav-title">' . esc_html($view['label']) . '</span>';
        echo '<span class="wppc-main-nav-desc">' . esc_html($view['description']) . '</span>';
        echo '</a>';
    }
    echo '</nav>';
}

function wppc_render_admin_page_header($title, $description, $active_page)
{
    echo '<div class="wrap wppc-admin-wrap">';
    echo '<div class="wppc-admin-head">';
    echo '<div>';
    echo '<h1>' . esc_html($title) . '</h1>';
    echo '<p class="wppc-admin-subtitle">' . esc_html($description) . '</p>';
    echo '</div>';
    echo '</div>';
    wppc_render_admin_view_tabs($active_page);
}

function wppc_render_admin_page_footer()
{
    echo '</div>';
}

function wppc_get_table_row_count($slug)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return 0;
    }
    if (!wppc_table_exists($slug)) {
        return 0;
    }
    $table_name = wppc_get_table_name($slug);
    $table_sql = wppc_escape_identifier($table_name);
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_sql}");
}

function wppc_get_table_records($slug, $search = '', $paged = 1, $per_page = 20)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return array('rows' => array(), 'total' => 0);
    }
    $table_name = wppc_get_table_name($slug);
    $table_sql = wppc_escape_identifier($table_name);
    if (!wppc_table_exists($slug)) {
        return array('rows' => array(), 'total' => 0);
    }

    $paged = max(1, absint($paged));
    $per_page = max(1, absint($per_page));
    $offset = ($paged - 1) * $per_page;
    $search = trim((string) $search);

    if ($search === '') {
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_sql}");
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value FROM {$table_sql} ORDER BY meta_id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        return array('rows' => $rows, 'total' => $total);
    }

    $like = '%' . $wpdb->esc_like($search) . '%';
    if (is_numeric($search)) {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_sql} WHERE post_id = %d OR meta_key LIKE %s OR meta_value LIKE %s",
                absint($search),
                $like,
                $like
            )
        );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value
                 FROM {$table_sql}
                 WHERE post_id = %d OR meta_key LIKE %s OR meta_value LIKE %s
                 ORDER BY meta_id DESC
                 LIMIT %d OFFSET %d",
                absint($search),
                $like,
                $like,
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        return array('rows' => $rows, 'total' => $total);
    }

    $total = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_sql} WHERE meta_key LIKE %s OR meta_value LIKE %s",
            $like,
            $like
        )
    );
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value
             FROM {$table_sql}
             WHERE meta_key LIKE %s OR meta_value LIKE %s
             ORDER BY meta_id DESC
             LIMIT %d OFFSET %d",
            $like,
            $like,
            $per_page,
            $offset
        ),
        ARRAY_A
    );
    return array('rows' => $rows, 'total' => $total);
}

function wppc_get_record_by_id($slug, $meta_id)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    $meta_id = absint($meta_id);
    if ($slug === '' || $meta_id <= 0 || !wppc_table_exists($slug)) {
        return null;
    }

    $table_name = wppc_get_table_name($slug);
    $table_sql = wppc_escape_identifier($table_name);
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$table_sql} WHERE meta_id = %d",
            $meta_id
        ),
        ARRAY_A
    );
}

function wppc_get_index_presets()
{
    return array(
        'idx_post_id_meta_key' => array(
            'label' => 'post_id + meta_key',
            'columns_sql' => '(post_id, meta_key(191))',
        ),
        'idx_meta_key_post_id' => array(
            'label' => 'meta_key + post_id',
            'columns_sql' => '(meta_key(191), post_id)',
        ),
        'idx_post_id_meta_key_value' => array(
            'label' => 'post_id + meta_key + meta_value',
            'columns_sql' => '(post_id, meta_key(191), meta_value(191))',
        ),
    );
}

function wppc_get_table_indexes($slug)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return array();
    }
    if (!wppc_table_exists($slug)) {
        return array();
    }

    $table_name = wppc_get_table_name($slug);
    $table_sql = wppc_escape_identifier($table_name);
    $rows = $wpdb->get_results("SHOW INDEX FROM {$table_sql}", ARRAY_A);
    $indexes = array();
    foreach ((array) $rows as $row) {
        if (empty($row['Key_name'])) {
            continue;
        }
        $key_name = (string) $row['Key_name'];
        if (!isset($indexes[$key_name])) {
            $indexes[$key_name] = array(
                'name' => $key_name,
                'non_unique' => isset($row['Non_unique']) ? (int) $row['Non_unique'] : 1,
                'columns' => array(),
            );
        }
        if (!empty($row['Column_name'])) {
            $indexes[$key_name]['columns'][] = (string) $row['Column_name'];
        }
    }
    return $indexes;
}

function wppc_add_index_by_preset($slug, $preset_key)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
    }
    if (!wppc_table_exists($slug)) {
        return new WP_Error('missing_table', 'ไม่พบตารางที่เลือก');
    }

    $presets = wppc_get_index_presets();
    if (!isset($presets[$preset_key])) {
        return new WP_Error('invalid_index', 'รูปแบบดัชนีไม่ถูกต้อง');
    }

    $indexes = wppc_get_table_indexes($slug);
    if (isset($indexes[$preset_key])) {
        return new WP_Error('index_exists', 'ดัชนีนี้มีอยู่แล้ว');
    }

    $table_name = wppc_get_table_name($slug);
    $sql = 'ALTER TABLE ' . wppc_escape_identifier($table_name) .
        ' ADD INDEX ' . wppc_escape_identifier($preset_key) .
        ' ' . $presets[$preset_key]['columns_sql'];
    $result = $wpdb->query($sql);
    if ($result === false) {
        return new WP_Error('index_create_failed', 'ไม่สามารถเพิ่มดัชนีได้');
    }

    return true;
}

function wppc_drop_index_by_name($slug, $index_name)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
    }
    if (!wppc_table_exists($slug)) {
        return new WP_Error('missing_table', 'ไม่พบตารางที่เลือก');
    }

    $index_name = sanitize_key($index_name);
    if ($index_name === '' || strtoupper($index_name) === 'PRIMARY') {
        return new WP_Error('invalid_index', 'ดัชนีไม่ถูกต้อง');
    }

    $indexes = wppc_get_table_indexes($slug);
    if (!isset($indexes[$index_name])) {
        return new WP_Error('index_not_found', 'ไม่พบดัชนีที่เลือก');
    }

    $table_name = wppc_get_table_name($slug);
    $sql = 'ALTER TABLE ' . wppc_escape_identifier($table_name) .
        ' DROP INDEX ' . wppc_escape_identifier($index_name);
    $result = $wpdb->query($sql);
    if ($result === false) {
        return new WP_Error('index_drop_failed', 'ไม่สามารถลบดัชนีได้');
    }

    return true;
}

function wppc_import_rows_from_json_file($tmp_path)
{
    $raw = file_get_contents($tmp_path);
    if ($raw === false || trim($raw) === '') {
        return new WP_Error('empty_file', 'ไฟล์ว่างหรืออ่านไฟล์ไม่ได้');
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return new WP_Error('invalid_json', 'ไฟล์ JSON ไม่ถูกต้อง');
    }

    return $decoded;
}

function wppc_import_rows_from_csv_file($tmp_path)
{
    $handle = fopen($tmp_path, 'r');
    if (!$handle) {
        return new WP_Error('open_failed', 'ไม่สามารถเปิดไฟล์ CSV ได้');
    }

    $rows = array();
    $header = fgetcsv($handle);
    if (!is_array($header) || empty($header)) {
        fclose($handle);
        return new WP_Error('invalid_csv_header', 'ไม่พบหัวคอลัมน์ในไฟล์ CSV');
    }

    $normalized_header = array_map(function ($column) {
        return strtolower(trim((string) $column));
    }, $header);

    while (($data = fgetcsv($handle)) !== false) {
        $row = array();
        foreach ($normalized_header as $index => $column_name) {
            $row[$column_name] = isset($data[$index]) ? $data[$index] : '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function wppc_parse_import_meta_value($row)
{
    $meta_value = array_key_exists('meta_value', $row) ? $row['meta_value'] : '';
    $storage = '';
    if (isset($row['meta_value_storage'])) {
        $storage = strtolower(trim((string) $row['meta_value_storage']));
    } elseif (isset($row['meta_value_type'])) {
        $storage = strtolower(trim((string) $row['meta_value_type']));
    }

    if ($storage === 'serialized') {
        $stored_value = (string) $meta_value;
        return array(
            'value' => wppc_unserialize_meta_value_from_store($stored_value),
            'stored_value' => $stored_value,
            'raw_storage' => true,
        );
    }

    if ($storage === 'json') {
        $decoded = json_decode((string) $meta_value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json_value', 'ค่า meta_value แบบ JSON ไม่ถูกต้อง');
        }

        return array(
            'value' => $decoded,
            'stored_value' => null,
            'raw_storage' => false,
        );
    }

    if ($storage === '' && isset($row['meta_id']) && is_string($meta_value) && is_serialized($meta_value)) {
        return array(
            'value' => wppc_unserialize_meta_value_from_store($meta_value),
            'stored_value' => $meta_value,
            'raw_storage' => true,
        );
    }

    return array(
        'value' => $meta_value,
        'stored_value' => null,
        'raw_storage' => false,
    );
}

function wppc_import_rows_into_table($slug, $rows)
{
    global $wpdb;
    $table_name = wppc_get_table_name($slug);
    $inserted = 0;
    $skipped = 0;

    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }

        $post_id = isset($row['post_id']) ? absint($row['post_id']) : 0;
        $meta_key = isset($row['meta_key']) ? wppc_normalize_meta_key($row['meta_key']) : '';
        $parsed_meta_value = wppc_parse_import_meta_value($row);
        if ($post_id <= 0 || $meta_key === '') {
            $skipped++;
            continue;
        }
        if (is_wp_error($parsed_meta_value)) {
            $skipped++;
            continue;
        }

        $meta_value = $parsed_meta_value['value'];

        $validation = wppc_validate_meta_with_schema($slug, $meta_key, $meta_value);
        if (is_wp_error($validation)) {
            $skipped++;
            continue;
        }

        $stored_value = !empty($parsed_meta_value['raw_storage']) && wppc_get_schema_type_for_meta_key($slug, $meta_key) !== 'json'
            ? $parsed_meta_value['stored_value']
            : wppc_prepare_meta_value_for_store($slug, $meta_key, $meta_value);

        $result = $wpdb->insert(
            $table_name,
            array(
                'post_id' => $post_id,
                'meta_key' => $meta_key,
                'meta_value' => $stored_value,
            ),
            array('%d', '%s', '%s')
        );
        if ($result) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    return array('inserted' => $inserted, 'skipped' => $skipped);
}

function wppc_prepare_json_export_row($slug, $row)
{
    $meta_key = isset($row['meta_key']) ? (string) $row['meta_key'] : '';
    if (array_key_exists('meta_value', $row)) {
        $row['meta_value'] = wppc_get_meta_value_from_store($slug, $meta_key, $row['meta_value']);
    }
    $row['meta_value_storage'] = 'logical';

    return $row;
}

function wppc_prepare_csv_export_row($row)
{
    $stored_value = isset($row['meta_value']) ? (string) $row['meta_value'] : '';
    $row['meta_value'] = $stored_value;
    $row['meta_value_storage'] = is_serialized($stored_value) ? 'serialized' : 'plain';

    return $row;
}

function wppc_stream_export_table_data($slug, $format)
{
    global $wpdb;
    if (!wppc_table_exists($slug)) {
        wp_die(esc_html__('ไม่พบตารางที่เลือก', 'wp-table-postmeta-custom'));
    }

    $table_name = wppc_get_table_name($slug);
    $table_sql = wppc_escape_identifier($table_name);
    $rows = $wpdb->get_results(
        "SELECT meta_id, post_id, meta_key, meta_value FROM {$table_sql} ORDER BY meta_id ASC",
        ARRAY_A
    );
    $filename = 'wppc-' . $slug . '-' . gmdate('Ymd-His');

    if ($format === 'json') {
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        $export_rows = array_map(function ($row) use ($slug) {
            return wppc_prepare_json_export_row($slug, $row);
        }, $rows);
        $encoded = wp_json_encode($export_rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo $encoded === false ? '[]' : $encoded;
        exit;
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('meta_id', 'post_id', 'meta_key', 'meta_value', 'meta_value_storage'));
    foreach ($rows as $row) {
        $row = wppc_prepare_csv_export_row($row);
        fputcsv($output, array(
            isset($row['meta_id']) ? $row['meta_id'] : '',
            isset($row['post_id']) ? $row['post_id'] : '',
            isset($row['meta_key']) ? $row['meta_key'] : '',
            isset($row['meta_value']) ? $row['meta_value'] : '',
            isset($row['meta_value_storage']) ? $row['meta_value_storage'] : 'plain',
        ));
    }
    fclose($output);
    exit;
}

function wppc_get_all_sync_states()
{
    $states = get_option(WPPC_SYNC_STATE_OPTION, array());
    return is_array($states) ? $states : array();
}

function wppc_get_sync_state($slug)
{
    $default = array(
        'running' => false,
        'direction' => 'from_main',
        'cursor' => 0,
        'batch_size' => 200,
        'copied' => 0,
        'skipped' => 0,
        'updated_at' => '',
        'last_message' => '',
    );

    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return $default;
    }

    $states = wppc_get_all_sync_states();
    if (!isset($states[$slug]) || !is_array($states[$slug])) {
        return $default;
    }

    return array_merge($default, $states[$slug]);
}

function wppc_set_sync_state($slug, $state)
{
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return;
    }

    $states = wppc_get_all_sync_states();
    $states[$slug] = array_merge(wppc_get_sync_state($slug), (array) $state);
    $states[$slug]['updated_at'] = current_time('mysql');
    update_option(WPPC_SYNC_STATE_OPTION, $states, false);
}

function wppc_reset_sync_state($slug)
{
    wppc_set_sync_state($slug, array(
        'running' => false,
        'direction' => 'from_main',
        'cursor' => 0,
        'batch_size' => 200,
        'copied' => 0,
        'skipped' => 0,
        'last_message' => '',
    ));
}

function wppc_upsert_meta_row($table_name, $post_id, $meta_key, $meta_value)
{
    global $wpdb;
    $table_sql = wppc_escape_identifier($table_name);
    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_id FROM {$table_sql} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $post_id,
            $meta_key
        )
    );

    if ($existing_id) {
        $updated = $wpdb->update(
            $table_name,
            array('meta_value' => $meta_value),
            array('meta_id' => absint($existing_id)),
            array('%s'),
            array('%d')
        );
        return $updated !== false;
    }

    return (bool) $wpdb->insert(
        $table_name,
        array(
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'meta_value' => $meta_value,
        ),
        array('%d', '%s', '%s')
    );
}

function wppc_run_sync_batch($slug)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
    }
    if (!wppc_table_exists($slug)) {
        return new WP_Error('missing_table', 'ไม่พบตารางที่เลือก');
    }

    $state = wppc_get_sync_state($slug);
    if (empty($state['running'])) {
        return new WP_Error('sync_not_running', 'สถานะซิงก์ไม่ได้อยู่ในโหมดทำงาน');
    }

    $direction = $state['direction'] === 'to_main' ? 'to_main' : 'from_main';
    $cursor = absint($state['cursor']);
    $batch_size = wppc_clamp_batch_size($state['batch_size']);
    $copied = absint($state['copied']);
    $skipped = absint($state['skipped']);

    if ($direction === 'from_main') {
        $source_table = $wpdb->postmeta;
        $target_table = wppc_get_table_name($slug);
    } else {
        $source_table = wppc_get_table_name($slug);
        $target_table = $wpdb->postmeta;
    }
    $source_table_sql = wppc_escape_identifier($source_table);

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$source_table_sql} WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
            $cursor,
            $batch_size
        ),
        ARRAY_A
    );

    if (empty($rows)) {
        wppc_set_sync_state($slug, array(
            'running' => false,
            'last_message' => 'ซิงก์เสร็จสิ้นแล้ว',
        ));
        return array('copied' => 0, 'skipped' => 0, 'done' => true);
    }

    $batch_copied = 0;
    $batch_skipped = 0;
    $last_id = $cursor;
    foreach ($rows as $row) {
        $last_id = absint($row['meta_id']);
        $post_id = absint($row['post_id']);
        $meta_key = wppc_normalize_meta_key($row['meta_key']);
        $meta_value = isset($row['meta_value']) ? $row['meta_value'] : '';
        if ($post_id <= 0 || $meta_key === '') {
            $batch_skipped++;
            continue;
        }

        $target_value = $meta_value;
        if ($direction === 'from_main') {
            $logical_meta_value = wppc_unserialize_meta_value_from_store($meta_value);
            $validation = wppc_validate_meta_with_schema($slug, $meta_key, $logical_meta_value);
            if (is_wp_error($validation)) {
                $batch_skipped++;
                continue;
            }
            if (wppc_get_schema_type_for_meta_key($slug, $meta_key) === 'json') {
                $target_value = wppc_prepare_meta_value_for_store($slug, $meta_key, $logical_meta_value);
            }
        }

        $result = wppc_upsert_meta_row($target_table, $post_id, $meta_key, $target_value);
        if ($result) {
            $batch_copied++;
        } else {
            $batch_skipped++;
        }
    }

    $copied += $batch_copied;
    $skipped += $batch_skipped;
    $done = count($rows) < $batch_size;
    wppc_set_sync_state($slug, array(
        'cursor' => $last_id,
        'copied' => $copied,
        'skipped' => $skipped,
        'running' => !$done,
        'last_message' => $done ? 'ซิงก์เสร็จสิ้นแล้ว' : 'ซิงก์ต่อได้อีก',
    ));

    return array(
        'copied' => $batch_copied,
        'skipped' => $batch_skipped,
        'done' => $done,
    );
}

function wppc_handle_admin_actions()
{
    global $wpdb;
    if (!is_admin() || !current_user_can('manage_options') || !wppc_is_wppc_admin_page()) {
        return;
    }

    $action = isset($_REQUEST['wppc_action']) ? sanitize_key(wp_unslash($_REQUEST['wppc_action'])) : '';
    if ($action === '') {
        return;
    }

    $slug = wppc_get_admin_active_slug();
    $slugs = wppc_get_registered_slugs();

    switch ($action) {
        case 'create_table':
            check_admin_referer('wppc_create_table');
            $new_slug = isset($_POST['new_table_slug']) ? wppc_normalize_slug(wp_unslash($_POST['new_table_slug'])) : '';
            if ($new_slug === '') {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'รหัสตารางไม่ถูกต้อง');
            }
            if (in_array($new_slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'รหัสตารางนี้มีอยู่แล้ว');
            }
            $result = wppc_create_meta_table($new_slug);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', $result->get_error_message());
            }
            wppc_admin_redirect_with_notice('wppc-table-types', 'success', 'สร้างตารางเรียบร้อย', array('table' => $new_slug));
            break;

        case 'delete_table':
            check_admin_referer('wppc_delete_table');
            $delete_slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : '';
            if ($delete_slug === '' || $delete_slug === WPPC_DEFAULT_SLUG) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'ไม่สามารถลบตารางเริ่มต้นได้');
            }
            if (!in_array($delete_slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'ไม่พบตารางที่เลือก');
            }
            $result = wppc_drop_meta_table($delete_slug);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', $result->get_error_message());
            }
            wppc_admin_redirect_with_notice('wppc-table-types', 'success', 'ลบตารางเรียบร้อย');
            break;

        case 'save_record':
            check_admin_referer('wppc_save_record');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }

            $meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $meta_key = isset($_POST['meta_key']) ? wppc_normalize_meta_key(wp_unslash($_POST['meta_key'])) : '';
            $meta_value = isset($_POST['meta_value']) ? wp_unslash($_POST['meta_value']) : '';
            $meta_value_was_structured = !empty($_POST['meta_value_was_structured']);
            if ($post_id <= 0 || $meta_key === '') {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'กรุณากรอก post_id และ meta_key ให้ครบ', array('table' => $slug));
            }

            if ($meta_value_was_structured && is_string($meta_value)) {
                $decoded_meta_value = json_decode($meta_value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ค่า meta_value ต้องเป็น JSON ที่ถูกต้อง', array('table' => $slug, 'edit_id' => $meta_id));
                }
                $meta_value = $decoded_meta_value;
            }

            $validation = wppc_validate_meta_with_schema($slug, $meta_key, $meta_value);
            if (is_wp_error($validation)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $validation->get_error_message(), array('table' => $slug));
            }

            if (!wppc_table_exists($slug)) {
                $created = wppc_create_meta_table($slug);
                if (is_wp_error($created)) {
                    wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $created->get_error_message(), array('table' => $slug));
                }
            }

            $table_name = wppc_get_table_name($slug);
            $stored_value = wppc_prepare_meta_value_for_store($slug, $meta_key, $meta_value);
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
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'บันทึกข้อมูลไม่สำเร็จ', array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'บันทึกข้อมูลเรียบร้อย', array('table' => $slug));
            break;

        case 'delete_record':
            check_admin_referer('wppc_delete_record');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            $meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
            if ($meta_id <= 0 || !in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ข้อมูลสำหรับลบไม่ถูกต้อง', array('table' => $slug));
            }
            $table_name = wppc_get_table_name($slug);
            $deleted = $wpdb->delete($table_name, array('meta_id' => $meta_id), array('%d'));
            if ($deleted === false) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ลบข้อมูลไม่สำเร็จ', array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'ลบข้อมูลเรียบร้อย', array('table' => $slug));
            break;

        case 'save_schema':
            check_admin_referer('wppc_save_schema');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $meta_key = isset($_POST['schema_meta_key']) ? wppc_normalize_meta_key(wp_unslash($_POST['schema_meta_key'])) : '';
            $schema_type = isset($_POST['schema_type']) ? wppc_normalize_schema_type(wp_unslash($_POST['schema_type'])) : 'text';
            $required = !empty($_POST['schema_required']) ? 1 : 0;
            if ($meta_key === '') {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'กรุณากรอก meta_key ของ schema', array('table' => $slug));
            }
            $schema = wppc_get_table_schema($slug);
            $schema[$meta_key] = array('type' => $schema_type, 'required' => $required);
            $saved = wppc_save_table_schema($slug, $schema);
            if (is_wp_error($saved)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $saved->get_error_message(), array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'บันทึก schema เรียบร้อย', array('table' => $slug));
            break;

        case 'delete_schema':
            check_admin_referer('wppc_delete_schema');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $meta_key = isset($_POST['schema_meta_key']) ? wppc_normalize_meta_key(wp_unslash($_POST['schema_meta_key'])) : '';
            if ($meta_key === '') {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'meta_key ของ schema ไม่ถูกต้อง', array('table' => $slug));
            }
            $schema = wppc_get_table_schema($slug);
            if (isset($schema[$meta_key])) {
                unset($schema[$meta_key]);
                wppc_save_table_schema($slug, $schema);
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'ลบ schema เรียบร้อย', array('table' => $slug));
            break;

        case 'add_index':
            check_admin_referer('wppc_add_index');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $preset = isset($_POST['index_preset']) ? sanitize_key(wp_unslash($_POST['index_preset'])) : '';
            $result = wppc_add_index_by_preset($slug, $preset);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $result->get_error_message(), array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'เพิ่มดัชนีเรียบร้อย', array('table' => $slug));
            break;

        case 'drop_index':
            check_admin_referer('wppc_drop_index');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $index_name = isset($_POST['index_name']) ? sanitize_key(wp_unslash($_POST['index_name'])) : '';
            $result = wppc_drop_index_by_name($slug, $index_name);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $result->get_error_message(), array('table' => $slug));
            }
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'ลบดัชนีเรียบร้อย', array('table' => $slug));
            break;

        case 'import_data':
            check_admin_referer('wppc_import_data');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            if (!wppc_table_exists($slug)) {
                $created = wppc_create_meta_table($slug);
                if (is_wp_error($created)) {
                    wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $created->get_error_message(), array('table' => $slug));
                }
            }
            $format = isset($_POST['import_format']) ? sanitize_key(wp_unslash($_POST['import_format'])) : '';
            if (!in_array($format, array('json', 'csv'), true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'รูปแบบไฟล์นำเข้าไม่ถูกต้อง', array('table' => $slug));
            }
            if (empty($_FILES['import_file']) || !isset($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไฟล์อัปโหลดไม่ถูกต้อง', array('table' => $slug));
            }
            $file = $_FILES['import_file'];
            if (!empty($file['error'])) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'อัปโหลดไฟล์ไม่สำเร็จ', array('table' => $slug));
            }
            if (!empty($file['size']) && $file['size'] > 10 * 1024 * 1024) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไฟล์ใหญ่เกิน 10MB', array('table' => $slug));
            }

            $rows = $format === 'json'
                ? wppc_import_rows_from_json_file($file['tmp_name'])
                : wppc_import_rows_from_csv_file($file['tmp_name']);
            if (is_wp_error($rows)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $rows->get_error_message(), array('table' => $slug));
            }

            $result = wppc_import_rows_into_table($slug, $rows);
            $message = sprintf('นำเข้าข้อมูลเสร็จแล้ว: สำเร็จ %d รายการ, ข้าม %d รายการ', $result['inserted'], $result['skipped']);
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', $message, array('table' => $slug));
            break;

        case 'export_data':
            $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : '';
            if (!in_array($format, array('json', 'csv'), true)) {
                wp_die(esc_html__('รูปแบบไฟล์ส่งออกไม่ถูกต้อง', 'wp-table-postmeta-custom'));
            }
            check_admin_referer('wppc_export_data_' . $format);
            $slug = isset($_GET['table']) ? wppc_normalize_slug(wp_unslash($_GET['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wp_die(esc_html__('ไม่พบตารางที่เลือก', 'wp-table-postmeta-custom'));
            }
            wppc_stream_export_table_data($slug, $format);
            break;

        case 'sync_start':
            check_admin_referer('wppc_sync_start');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $direction = isset($_POST['sync_direction']) && wp_unslash($_POST['sync_direction']) === 'to_main' ? 'to_main' : 'from_main';
            $batch_size = isset($_POST['sync_batch_size']) ? wppc_clamp_batch_size($_POST['sync_batch_size']) : 200;
            wppc_set_sync_state($slug, array(
                'running' => true,
                'direction' => $direction,
                'cursor' => 0,
                'batch_size' => $batch_size,
                'copied' => 0,
                'skipped' => 0,
                'last_message' => 'เริ่มซิงก์แล้ว',
            ));
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'เริ่มซิงก์เรียบร้อย', array('table' => $slug));
            break;

        case 'sync_run_batch':
            check_admin_referer('wppc_sync_run_batch');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            $result = wppc_run_sync_batch($slug);
            if (is_wp_error($result)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $result->get_error_message(), array('table' => $slug));
            }
            $message = sprintf(
                'ซิงก์ 1 รอบ: คัดลอก %d, ข้าม %d%s',
                $result['copied'],
                $result['skipped'],
                !empty($result['done']) ? ' (เสร็จสิ้น)' : ''
            );
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', $message, array('table' => $slug));
            break;

        case 'sync_reset':
            check_admin_referer('wppc_sync_reset');
            $slug = isset($_POST['table']) ? wppc_normalize_slug(wp_unslash($_POST['table'])) : $slug;
            if (!in_array($slug, $slugs, true)) {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'ไม่พบตารางที่เลือก');
            }
            wppc_reset_sync_state($slug);
            wppc_admin_redirect_with_notice('wppc-data-manager', 'success', 'รีเซ็ตสถานะซิงก์เรียบร้อย', array('table' => $slug));
            break;
    }
}
add_action('admin_init', 'wppc_handle_admin_actions');

function wppc_register_admin_menu()
{
    add_submenu_page(
        'tools.php',
        'WP Postmeta Custom',
        'WP Postmeta Custom',
        'manage_options',
        'wppc-overview',
        'wppc_render_overview_page'
    );

    add_submenu_page(
        null,
        'รายการประเภทตาราง',
        '',
        'manage_options',
        'wppc-table-types',
        'wppc_render_table_types_page'
    );

    add_submenu_page(
        null,
        'จัดการข้อมูลตาราง',
        '',
        'manage_options',
        'wppc-data-manager',
        'wppc_render_data_manager_page'
    );
}
add_action('admin_menu', 'wppc_register_admin_menu', 99);

function wppc_move_menu_to_tools_bottom()
{
    global $submenu;
    if (!isset($submenu['tools.php']) || !is_array($submenu['tools.php'])) {
        return;
    }

    $target_slug = 'wppc-overview';
    $target_item = null;
    foreach ($submenu['tools.php'] as $index => $item) {
        if (isset($item[2]) && $item[2] === $target_slug) {
            $target_item = $item;
            unset($submenu['tools.php'][$index]);
            break;
        }
    }

    if ($target_item !== null) {
        $submenu['tools.php'][] = $target_item;
        $submenu['tools.php'] = array_values($submenu['tools.php']);
    }
}
add_action('admin_menu', 'wppc_move_menu_to_tools_bottom', 99999);

function wppc_render_slug_tabs($current_slug, $page)
{
    $slugs = wppc_get_registered_slugs();
    echo '<h2 class="nav-tab-wrapper wppc-nav-tab-wrapper">';
    foreach ($slugs as $slug) {
        $active = $slug === $current_slug ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url(wppc_admin_url($page, array('table' => $slug))) . '">' . esc_html($slug) . '</a>';
    }
    echo '</h2>';
}

function wppc_render_overview_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'wp-table-postmeta-custom'));
    }

    $slugs = wppc_get_registered_slugs();
    $total_rows = 0;
    foreach ($slugs as $slug) {
        $total_rows += wppc_get_table_row_count($slug);
    }

    wppc_render_admin_page_header(
        'WP Postmeta Custom',
        'หน้าจัดการหลายตาราง postmeta พร้อมเครื่องมือ schema, index, import/export และ sync',
        'wppc-overview'
    );

    echo '<div class="wppc-card wppc-card-metrics">';
    echo '<h2>สรุปภาพรวม</h2>';
    echo '<div class="wppc-metric-grid">';
    echo '<div class="wppc-metric-item"><span class="wppc-metric-label">จำนวนประเภทตาราง</span><strong class="wppc-metric-value">' . esc_html(number_format_i18n(count($slugs))) . '</strong></div>';
    echo '<div class="wppc-metric-item"><span class="wppc-metric-label">จำนวนแถวข้อมูลทั้งหมด</span><strong class="wppc-metric-value">' . esc_html(number_format_i18n($total_rows)) . '</strong></div>';
    echo '<div class="wppc-metric-item"><span class="wppc-metric-label">ตารางเริ่มต้น</span><strong class="wppc-metric-value"><code>' . esc_html(WPPC_DEFAULT_SLUG) . '</code></strong></div>';
    echo '<div class="wppc-metric-item"><span class="wppc-metric-label">เวอร์ชันปลั๊กอิน</span><strong class="wppc-metric-value">' . esc_html(WPPC_VERSION) . '</strong></div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wppc-card">';
    echo '<h2>ทางลัด</h2>';
    echo '<p class="wppc-inline-actions">';
    echo '<a class="button button-primary" href="' . esc_url(wppc_admin_url('wppc-table-types')) . '">ไปหน้ารายการประเภทตาราง</a> ';
    echo '<a class="button" href="' . esc_url(wppc_admin_url('wppc-data-manager')) . '">ไปหน้าจัดการข้อมูลตาราง</a>';
    echo '</p>';
    echo '</div>';

    wppc_render_admin_page_footer();
}

function wppc_render_table_types_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'wp-table-postmeta-custom'));
    }

    $slugs = wppc_get_registered_slugs();
    wppc_render_admin_page_header(
        'รายการประเภทตาราง',
        'สร้างและลบตาราง postmeta เพิ่มเติม โดยใช้ slug เพื่อแยกกลุ่มข้อมูล',
        'wppc-table-types'
    );

    echo '<div class="wppc-card">';
    echo '<h2>สร้างตารางใหม่</h2>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-table-types')) . '">';
    wp_nonce_field('wppc_create_table');
    echo '<input type="hidden" name="page" value="wppc-table-types">';
    echo '<input type="hidden" name="wppc_action" value="create_table">';
    echo '<table class="form-table"><tbody>';
    echo '<tr>';
    echo '<th scope="row"><label for="new_table_slug">รหัสตาราง (slug)</label></th>';
    echo '<td>';
    echo '<input name="new_table_slug" id="new_table_slug" type="text" class="regular-text" required pattern="[a-z][a-z0-9_]*">';
    echo '<p class="description">ใช้ได้เฉพาะ a-z, 0-9, _ และต้องขึ้นต้นด้วยตัวอักษร</p>';
    echo '</td>';
    echo '</tr>';
    echo '</tbody></table>';
    submit_button('สร้างตาราง');
    echo '</form>';
    echo '</div>';

    echo '<div class="wppc-card">';
    echo '<h2>ตารางที่มีอยู่</h2>';
    echo '<div class="wppc-table-scroll">';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th style="width:200px;">รหัสตาราง (slug)</th><th>ชื่อตารางจริง</th><th style="width:140px;">จำนวนข้อมูล</th><th style="width:220px;">การทำงาน</th></tr></thead>';
    echo '<tbody>';
    foreach ($slugs as $slug) {
        $table_name = wppc_get_table_name($slug);
        $count = wppc_get_table_row_count($slug);
        echo '<tr>';
        echo '<td><strong>' . esc_html($slug) . '</strong></td>';
        echo '<td><code>' . esc_html($table_name) . '</code></td>';
        echo '<td>' . esc_html(number_format_i18n($count)) . '</td>';
        echo '<td>';
        echo '<div class="wppc-inline-actions">';
        echo '<a class="button button-small" href="' . esc_url(wppc_admin_url('wppc-data-manager', array('table' => $slug))) . '">จัดการข้อมูล</a> ';
        if ($slug !== WPPC_DEFAULT_SLUG) {
            echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-table-types')) . '" class="wppc-inline-form">';
            wp_nonce_field('wppc_delete_table');
            echo '<input type="hidden" name="page" value="wppc-table-types">';
            echo '<input type="hidden" name="wppc_action" value="delete_table">';
            echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
            echo '<button type="submit" class="button button-small button-link-delete" onclick="return confirm(\'ยืนยันการลบตารางและข้อมูลทั้งหมด?\');">ลบตาราง</button>';
            echo '</form>';
        } else {
            echo '<span class="wppc-faded">ตารางเริ่มต้น (ลบไม่ได้)</span>';
        }
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';

    wppc_render_admin_page_footer();
}

function wppc_render_data_manager_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'wp-table-postmeta-custom'));
    }

    $slug = wppc_get_admin_active_slug();
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $per_page = 20;
    $records = wppc_get_table_records($slug, $search, $paged, $per_page);
    $total_pages = max(1, (int) ceil($records['total'] / $per_page));
    $schema = wppc_get_table_schema($slug);
    $schema_type_labels = wppc_get_schema_type_labels();
    $indexes = wppc_get_table_indexes($slug);
    $sync_state = wppc_get_sync_state($slug);
    $edit_id = isset($_GET['edit_id']) ? absint($_GET['edit_id']) : 0;
    $edit_row = $edit_id > 0 ? wppc_get_record_by_id($slug, $edit_id) : null;

    $export_json_url = wp_nonce_url(
        wppc_admin_url('wppc-data-manager', array('table' => $slug, 'wppc_action' => 'export_data', 'format' => 'json')),
        'wppc_export_data_json'
    );
    $export_csv_url = wp_nonce_url(
        wppc_admin_url('wppc-data-manager', array('table' => $slug, 'wppc_action' => 'export_data', 'format' => 'csv')),
        'wppc_export_data_csv'
    );

    wppc_render_admin_page_header(
        'จัดการข้อมูลตาราง',
        'เพิ่ม แก้ไข ลบ ค้นหา และจัดการเครื่องมือขั้นสูงของข้อมูลในแต่ละตาราง',
        'wppc-data-manager'
    );
    wppc_render_slug_tabs($slug, 'wppc-data-manager');

    echo '<div class="wppc-grid">';
    echo '<div class="wppc-card">';
    echo '<h2>เพิ่ม/แก้ไขข้อมูล</h2>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '">';
    wp_nonce_field('wppc_save_record');
    $meta_value_for_edit = $edit_row ? maybe_unserialize($edit_row['meta_value']) : '';
    $meta_value_is_structured = is_array($meta_value_for_edit) || is_object($meta_value_for_edit);
    if ($meta_value_is_structured) {
        $meta_value_for_edit = wp_json_encode($meta_value_for_edit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="save_record">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<input type="hidden" name="meta_id" value="' . esc_attr($edit_row ? $edit_row['meta_id'] : 0) . '">';
    if ($meta_value_is_structured) {
        echo '<input type="hidden" name="meta_value_was_structured" value="1">';
    }
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="wppc_post_id">รหัสโพสต์ (post_id)</label></th><td><input id="wppc_post_id" name="post_id" type="number" min="1" required value="' . esc_attr($edit_row ? $edit_row['post_id'] : '') . '" class="regular-text"></td></tr>';
    echo '<tr><th><label for="wppc_meta_key">คีย์ข้อมูล (meta_key)</label></th><td><input id="wppc_meta_key" name="meta_key" type="text" required value="' . esc_attr($edit_row ? $edit_row['meta_key'] : '') . '" class="regular-text"></td></tr>';
    echo '<tr><th><label for="wppc_meta_value">ค่าข้อมูล (meta_value)</label></th><td><textarea id="wppc_meta_value" name="meta_value" rows="6" class="large-text">' . esc_textarea((string) $meta_value_for_edit) . '</textarea></td></tr>';
    echo '</tbody></table>';
    submit_button($edit_row ? 'อัปเดตข้อมูล' : 'เพิ่มข้อมูล');
    if ($edit_row) {
        echo '<a class="button" href="' . esc_url(wppc_admin_url('wppc-data-manager', array('table' => $slug))) . '">ยกเลิกแก้ไข</a>';
    }
    echo '</form>';
    echo '</div>';

    echo '<div class="wppc-card">';
    echo '<h2>กำหนดโครงสร้างข้อมูล (Schema) ต่อ meta_key</h2>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '">';
    wp_nonce_field('wppc_save_schema');
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="save_schema">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="schema_meta_key">คีย์ข้อมูล (meta_key)</label></th><td><input id="schema_meta_key" name="schema_meta_key" type="text" required class="regular-text"></td></tr>';
    echo '<tr><th><label for="schema_type">ชนิดข้อมูล</label></th><td><select id="schema_type" name="schema_type">';
    foreach ($schema_type_labels as $value => $label) {
        echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>บังคับกรอก</th><td><label><input type="checkbox" name="schema_required" value="1"> ต้องมีค่า</label></td></tr>';
    echo '</tbody></table>';
    submit_button('บันทึก Schema');
    echo '</form>';

    echo '<h3>รายการ Schema ปัจจุบัน</h3>';
    if (empty($schema)) {
        echo '<p>ยังไม่มี schema ในตารางนี้</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>คีย์ข้อมูล (meta_key)</th><th>ชนิดข้อมูล</th><th>บังคับ</th><th>ลบ</th></tr></thead><tbody>';
        foreach ($schema as $meta_key => $rule) {
            $type = isset($rule['type']) ? wppc_normalize_schema_type($rule['type']) : 'text';
            $required = !empty($rule['required']);
            echo '<tr>';
            echo '<td><code>' . esc_html($meta_key) . '</code></td>';
            echo '<td>' . esc_html(isset($schema_type_labels[$type]) ? $schema_type_labels[$type] : $type) . '</td>';
            echo '<td>' . ($required ? 'ใช่' : 'ไม่') . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" style="display:inline;">';
            wp_nonce_field('wppc_delete_schema');
            echo '<input type="hidden" name="page" value="wppc-data-manager">';
            echo '<input type="hidden" name="wppc_action" value="delete_schema">';
            echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
            echo '<input type="hidden" name="schema_meta_key" value="' . esc_attr($meta_key) . '">';
            echo '<button type="submit" class="button button-small button-link-delete" onclick="return confirm(\'ยืนยันการลบ schema?\');">ลบ</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="wppc-grid">';
    echo '<div class="wppc-card">';
    echo '<h2>จัดการดัชนี (Index) เพื่อเพิ่มความเร็วค้นหา</h2>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '">';
    wp_nonce_field('wppc_add_index');
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="add_index">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<select name="index_preset">';
    foreach (wppc_get_index_presets() as $preset_key => $preset) {
        echo '<option value="' . esc_attr($preset_key) . '">' . esc_html($preset['label']) . ' (' . esc_html($preset_key) . ')</option>';
    }
    echo '</select> <button type="submit" class="button">เพิ่มดัชนี</button>';
    echo '</form>';

    $has_drop_candidate = false;
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '">';
    wp_nonce_field('wppc_drop_index');
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="drop_index">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<select name="index_name">';
    foreach ($indexes as $index) {
        if ($index['name'] === 'PRIMARY') {
            continue;
        }
        $has_drop_candidate = true;
        echo '<option value="' . esc_attr($index['name']) . '">' . esc_html($index['name'] . ' (' . implode(', ', $index['columns']) . ')') . '</option>';
    }
    echo '</select> <button type="submit" class="button button-link-delete" ' . ($has_drop_candidate ? '' : 'disabled') . ' onclick="return confirm(\'ยืนยันการลบดัชนี?\');">ลบดัชนี</button>';
    echo '</form>';
    echo '</div>';

    echo '<div class="wppc-card">';
    echo '<h2>นำเข้า/ส่งออกข้อมูล</h2>';
    echo '<p><a class="button" href="' . esc_url($export_json_url) . '">ส่งออก JSON</a> <a class="button" href="' . esc_url($export_csv_url) . '">ส่งออก CSV</a></p>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" enctype="multipart/form-data">';
    wp_nonce_field('wppc_import_data');
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="import_data">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<p><label>รูปแบบไฟล์: <select name="import_format"><option value="json">JSON</option><option value="csv">CSV</option></select></label></p>';
    echo '<p><input type="file" name="import_file" accept=".json,.csv" required></p>';
    echo '<p><button type="submit" class="button button-primary">นำเข้าข้อมูล</button></p>';
    echo '</form>';
    echo '<p class="description">ขนาดไฟล์สูงสุด 10MB และต้องมีคอลัมน์ `post_id`, `meta_key`, `meta_value`</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wppc-card">';
    echo '<h2>ซิงก์ข้อมูลกับ wp_postmeta</h2>';
    echo '<p>สถานะล่าสุด: <strong>' . esc_html(!empty($sync_state['running']) ? 'กำลังทำงาน' : 'หยุดอยู่') . '</strong></p>';
    echo '<p>ทิศทาง: <code>' . esc_html($sync_state['direction']) . '</code>, cursor: <code>' . esc_html((string) $sync_state['cursor']) . '</code>, copied: <code>' . esc_html((string) $sync_state['copied']) . '</code>, skipped: <code>' . esc_html((string) $sync_state['skipped']) . '</code></p>';
    if (!empty($sync_state['last_message'])) {
        echo '<p>ข้อความล่าสุด: ' . esc_html($sync_state['last_message']) . '</p>';
    }
    echo '<div class="wppc-inline-actions">';

    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" class="wppc-inline-form">';
    wp_nonce_field('wppc_sync_start');
    echo '<input type="hidden" name="page" value="wppc-data-manager"><input type="hidden" name="wppc_action" value="sync_start"><input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<select name="sync_direction"><option value="from_main">จาก wp_postmeta ไปตารางนี้</option><option value="to_main">จากตารางนี้ไป wp_postmeta</option></select> ';
    echo '<input type="number" name="sync_batch_size" min="10" max="1000" value="' . esc_attr($sync_state['batch_size']) . '" style="width:96px;"> ';
    echo '<button type="submit" class="button button-primary">เริ่มซิงก์</button></form>';

    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" class="wppc-inline-form">';
    wp_nonce_field('wppc_sync_run_batch');
    echo '<input type="hidden" name="page" value="wppc-data-manager"><input type="hidden" name="wppc_action" value="sync_run_batch"><input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<button type="submit" class="button">รันซิงก์ 1 รอบ</button></form>';

    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" class="wppc-inline-form">';
    wp_nonce_field('wppc_sync_reset');
    echo '<input type="hidden" name="page" value="wppc-data-manager"><input type="hidden" name="wppc_action" value="sync_reset"><input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<button type="submit" class="button button-link-delete">รีเซ็ตสถานะซิงก์</button></form>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wppc-card">';
    echo '<h2>ข้อมูลในตาราง</h2>';
    echo '<form method="get" action="' . esc_url(admin_url('tools.php')) . '" class="wppc-inline-actions" style="margin-bottom:10px;">';
    echo '<input type="hidden" name="page" value="wppc-data-manager"><input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="ค้นหา post_id/meta_key/meta_value"> ';
    echo '<button type="submit" class="button">ค้นหา</button> ';
    if ($search !== '') {
        echo '<a class="button" href="' . esc_url(wppc_admin_url('wppc-data-manager', array('table' => $slug))) . '">ล้างคำค้น</a>';
    }
    echo '</form>';

    echo '<table class="widefat striped"><thead><tr><th style="width:70px;">ID</th><th style="width:110px;">รหัสโพสต์</th><th style="width:220px;">คีย์ข้อมูล</th><th>ค่าข้อมูล</th><th style="width:160px;">การทำงาน</th></tr></thead><tbody>';
    if (empty($records['rows'])) {
        echo '<tr><td colspan="5">ไม่พบข้อมูล</td></tr>';
    } else {
        foreach ($records['rows'] as $row) {
            $row_value = maybe_unserialize($row['meta_value']);
            if (is_array($row_value) || is_object($row_value)) {
                $row_value = wp_json_encode($row_value, JSON_UNESCAPED_UNICODE);
            } else {
                $row_value = (string) $row_value;
            }
            if (strlen($row_value) > 160) {
                $row_value = substr($row_value, 0, 160) . '...';
            }
            $edit_url = wppc_admin_url('wppc-data-manager', array('table' => $slug, 'paged' => $paged, 's' => $search, 'edit_id' => $row['meta_id']));
            echo '<tr>';
            echo '<td>' . esc_html($row['meta_id']) . '</td>';
            echo '<td>' . esc_html($row['post_id']) . '</td>';
            echo '<td><code>' . esc_html($row['meta_key']) . '</code></td>';
            echo '<td><code>' . esc_html($row_value) . '</code></td>';
            echo '<td><a class="button button-small" href="' . esc_url($edit_url) . '">แก้ไข</a> ';
            echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" class="wppc-inline-form">';
            wp_nonce_field('wppc_delete_record');
            echo '<input type="hidden" name="page" value="wppc-data-manager"><input type="hidden" name="wppc_action" value="delete_record"><input type="hidden" name="table" value="' . esc_attr($slug) . '"><input type="hidden" name="meta_id" value="' . esc_attr($row['meta_id']) . '">';
            echo '<button type="submit" class="button button-small button-link-delete" onclick="return confirm(\'ยืนยันการลบข้อมูลนี้?\');">ลบ</button></form></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0;">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $page_url = wppc_admin_url('wppc-data-manager', array('table' => $slug, 's' => $search, 'paged' => $i));
            $style = $i === $paged ? 'style="font-weight:700;text-decoration:underline;"' : '';
            echo '<a ' . $style . ' href="' . esc_url($page_url) . '">' . esc_html($i) . '</a> ';
        }
        echo '</div></div>';
    }

    echo '</div>';
    wppc_render_admin_page_footer();
}
