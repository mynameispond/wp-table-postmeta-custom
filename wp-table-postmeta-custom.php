<?php
/**
 * Plugin Name: WP Table Postmeta Custom
 * Description: จัดการหลายตาราง postmeta แบบกำหนด slug ได้ พร้อมรองรับ meta_query_wppc-{table_slug} ใน WP_Query
 * Version: 1.0.0
 * Author: Peera
 * Text Domain: wp-table-postmeta-custom
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPPC_VERSION', '1.0.0');
define('WPPC_TABLE_REGISTRY_OPTION', 'wppc_table_registry');
define('WPPC_SYNC_STATE_OPTION', 'wppc_sync_state');

/**
 * ตรวจสอบ slug ให้ปลอดภัยกับชื่อตาราง
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

    if (strlen($meta_key) > 191) {
        $meta_key = substr($meta_key, 0, 191);
    }

    return $meta_key;
}

function wppc_get_registered_slugs()
{
    $stored = get_option(WPPC_TABLE_REGISTRY_OPTION, array());
    if (!is_array($stored)) {
        $stored = array();
    }

    $normalized = array();
    foreach ($stored as $slug) {
        $slug = wppc_normalize_slug($slug);
        if ($slug !== '') {
            $normalized[] = $slug;
        }
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

    $normalized = array_values(array_unique($normalized));
    update_option(WPPC_TABLE_REGISTRY_OPTION, $normalized, false);

    return $normalized;
}

function wppc_get_table_name($slug)
{
    global $wpdb;
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return '';
    }

    return $wpdb->prefix . 'postmeta_' . $slug;
}

function wppc_validate_slug_for_table_create($slug)
{
    $slug = wppc_normalize_slug($slug);
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
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
        meta_key varchar(191) NOT NULL DEFAULT '',
        meta_value longtext,
        PRIMARY KEY  (meta_id),
        UNIQUE KEY uniq_post_id_meta_key (post_id, meta_key),
        KEY meta_key (meta_key),
        KEY idx_meta_key_post_id (meta_key, post_id),
        KEY idx_post_id_meta_key_value (post_id, meta_key, meta_value(191))
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
    if ($slug === '') {
        return new WP_Error('invalid_slug', 'รหัสตารางไม่ถูกต้อง');
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
    if ($table_name === '') {
        return false;
    }
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)));
    return $exists === $table_name;
}

function wppc_prepare_meta_value_for_store($meta_value)
{
    if (is_array($meta_value) || is_object($meta_value)) {
        $encoded = wp_json_encode($meta_value, JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '' : $encoded;
    }

    if (is_bool($meta_value)) {
        return $meta_value ? '1' : '';
    }

    if ($meta_value === null) {
        return '';
    }

    return is_scalar($meta_value) ? (string) $meta_value : '';
}

function wppc_get_raw_main_post_meta($post_id, $meta_key)
{
    global $wpdb;
    $table_sql = wppc_escape_identifier($wpdb->postmeta);
    $value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$table_sql} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $post_id,
            $meta_key
        )
    );

    return $value === null ? '' : (string) $value;
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
        return $from_main ? wppc_get_raw_main_post_meta($post_id, $meta_key) : '';
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
            return wppc_get_raw_main_post_meta($post_id, $meta_key);
        }
        return '';
    }

    return (string) $row['meta_value'];
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

    if (!wppc_table_exists($table_slug)) {
        $create_result = wppc_create_meta_table($table_slug);
        if (is_wp_error($create_result)) {
            return $create_result;
        }
    }

    $table_name = wppc_get_table_name($table_slug);
    $table_sql = wppc_escape_identifier($table_name);
    $stored_value = wppc_prepare_meta_value_for_store($meta_value);

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
                wppc_prepare_meta_value_for_store($meta_value)
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

function wppc_build_meta_query_wppc_sql($table_name, $meta_query, $alias_prefix = 'wppc_mq_', &$counter = 0)
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

        $sql = wppc_build_meta_query_wppc_sql($table_name, $clause, $alias_prefix, $counter);
        if ($sql !== '') {
            $parts[] = '(' . $sql . ')';
        }
    }

    if (empty($parts)) {
        return '';
    }

    return implode(" {$relation} ", $parts);
}

function wppc_get_meta_query_wppc_by_table($query)
{
    $custom_queries = array();
    $prefix = 'meta_query_wppc-';
    $query_vars = isset($query->query_vars) && is_array($query->query_vars) ? $query->query_vars : array();

    foreach ($query_vars as $query_var => $meta_query_wppc) {
        if (!is_string($query_var) || strpos($query_var, $prefix) !== 0) {
            continue;
        }
        if (empty($meta_query_wppc) || !is_array($meta_query_wppc)) {
            continue;
        }

        $table_slug = wppc_normalize_slug(substr($query_var, strlen($prefix)));
        if ($table_slug === '') {
            continue;
        }

        $custom_queries[$table_slug] = $meta_query_wppc;
    }

    return $custom_queries;
}

function wppc_filter_posts_where_for_meta_query_wppc($where, $query)
{
    if (!($query instanceof WP_Query)) {
        return $where;
    }

    $custom_queries = wppc_get_meta_query_wppc_by_table($query);
    if (empty($custom_queries)) {
        return $where;
    }

    $counter = 0;
    foreach ($custom_queries as $table_slug => $meta_query_wppc) {
        if (!in_array($table_slug, wppc_get_registered_slugs(), true)) {
            continue;
        }

        if (!wppc_table_exists($table_slug)) {
            continue;
        }

        $table_name = wppc_get_table_name($table_slug);
        $sql = wppc_build_meta_query_wppc_sql($table_name, $meta_query_wppc, 'wppc_mq_', $counter);
        if ($sql === '') {
            continue;
        }

        $where .= " AND ({$sql})";
    }

    return $where;
}
add_filter('posts_where', 'wppc_filter_posts_where_for_meta_query_wppc', 10, 2);

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
    return isset($slugs[0]) ? $slugs[0] : '';
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

function wppc_get_table_records($slug, $post_id_filter = '', $meta_key_filter = '', $paged = 1, $per_page = 20)
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
    $post_id_filter = trim((string) $post_id_filter);
    $meta_key_filter = trim((string) $meta_key_filter);
    $where_parts = array();
    $where_values = array();

    if ($post_id_filter !== '') {
        $where_parts[] = 'post_id = %d';
        $where_values[] = absint($post_id_filter);
    }

    if ($meta_key_filter !== '') {
        $where_parts[] = 'meta_key LIKE %s';
        $where_values[] = '%' . $wpdb->esc_like($meta_key_filter) . '%';
    }

    $where_sql = empty($where_parts) ? '' : ' WHERE ' . implode(' AND ', $where_parts);
    $count_sql = "SELECT COUNT(*) FROM {$table_sql}{$where_sql}";
    $total = empty($where_values)
        ? (int) $wpdb->get_var($count_sql)
        : (int) $wpdb->get_var($wpdb->prepare($count_sql, $where_values));

    $rows_sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$table_sql}{$where_sql} ORDER BY meta_id DESC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results(
        $wpdb->prepare($rows_sql, array_merge($where_values, array($per_page, $offset))),
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
        'idx_meta_key_post_id' => array(
            'label' => 'meta_key + post_id',
            'columns_sql' => '(meta_key, post_id)',
        ),
        'idx_post_id_meta_key_value' => array(
            'label' => 'post_id + meta_key + meta_value',
            'columns_sql' => '(post_id, meta_key, meta_value(191))',
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
    if ($index_name === '' || strtoupper($index_name) === 'PRIMARY' || $index_name === 'uniq_post_id_meta_key') {
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
        $meta_value = array_key_exists('meta_value', $row) ? $row['meta_value'] : '';
        if ($post_id <= 0 || $meta_key === '') {
            $skipped++;
            continue;
        }

        $stored_value = wppc_prepare_meta_value_for_store($meta_value);

        $result = wppc_upsert_meta_row($table_name, $post_id, $meta_key, $stored_value);
        if ($result) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    return array('inserted' => $inserted, 'skipped' => $skipped);
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
        $encoded = wp_json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo $encoded === false ? '[]' : $encoded;
        exit;
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('meta_id', 'post_id', 'meta_key', 'meta_value'));
    foreach ($rows as $row) {
        fputcsv($output, array(
            isset($row['meta_id']) ? $row['meta_id'] : '',
            isset($row['post_id']) ? $row['post_id'] : '',
            isset($row['meta_key']) ? $row['meta_key'] : '',
            isset($row['meta_value']) ? $row['meta_value'] : '',
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

        $target_value = wppc_prepare_meta_value_for_store($meta_value);
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
            if ($delete_slug === '') {
                wppc_admin_redirect_with_notice('wppc-table-types', 'error', 'รหัสตารางไม่ถูกต้อง');
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
            if ($post_id <= 0 || $meta_key === '') {
                wppc_admin_redirect_with_notice('wppc-data-manager', 'error', 'กรุณากรอก post_id และ meta_key ให้ครบ', array('table' => $slug));
            }

            if (!wppc_table_exists($slug)) {
                $created = wppc_create_meta_table($slug);
                if (is_wp_error($created)) {
                    wppc_admin_redirect_with_notice('wppc-data-manager', 'error', $created->get_error_message(), array('table' => $slug));
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
    if (empty($slugs)) {
        echo '<p class="wppc-faded">ยังไม่มีตาราง custom</p>';
        return;
    }

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
        'หน้าจัดการหลายตาราง postmeta พร้อมเครื่องมือ index, import/export และ sync',
        'wppc-overview'
    );

    echo '<div class="wppc-card wppc-card-metrics">';
    echo '<h2>สรุปภาพรวม</h2>';
    echo '<div class="wppc-metric-grid">';
    echo '<div class="wppc-metric-item"><span class="wppc-metric-label">จำนวนประเภทตาราง</span><strong class="wppc-metric-value">' . esc_html(number_format_i18n(count($slugs))) . '</strong></div>';
    echo '<div class="wppc-metric-item"><span class="wppc-metric-label">จำนวนแถวข้อมูลทั้งหมด</span><strong class="wppc-metric-value">' . esc_html(number_format_i18n($total_rows)) . '</strong></div>';
    echo '<div class="wppc-metric-item"><span class="wppc-metric-label">เวอร์ชันปลั๊กอิน</span><strong class="wppc-metric-value">' . esc_html(WPPC_VERSION) . '</strong></div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wppc-card">';
    echo '<h2>ทางลัด</h2>';
    echo '<p class="wppc-inline-actions">';
    echo '<a class="button button-primary" href="' . esc_url(wppc_admin_url('wppc-table-types')) . '">ไปหน้ารายการประเภทตาราง</a> ';
    if (!empty($slugs)) {
        echo '<a class="button" href="' . esc_url(wppc_admin_url('wppc-data-manager')) . '">ไปหน้าจัดการข้อมูลตาราง</a>';
    }
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
    if (empty($slugs)) {
        echo '<tr><td colspan="4">ยังไม่มีตาราง custom ให้สร้าง slug ก่อนเริ่มจัดการข้อมูล</td></tr>';
    } else {
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
            echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-table-types')) . '" class="wppc-inline-form">';
            wp_nonce_field('wppc_delete_table');
            echo '<input type="hidden" name="page" value="wppc-table-types">';
            echo '<input type="hidden" name="wppc_action" value="delete_table">';
            echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
            echo '<button type="submit" class="button button-small button-link-delete" onclick="return confirm(\'ยืนยันการลบตารางและข้อมูลทั้งหมด?\');">ลบตาราง</button>';
            echo '</form>';
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }
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
    $post_id_filter = isset($_GET['filter_post_id']) ? sanitize_text_field(wp_unslash($_GET['filter_post_id'])) : '';
    $meta_key_filter = isset($_GET['filter_meta_key']) ? sanitize_text_field(wp_unslash($_GET['filter_meta_key'])) : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $per_page = 20;
    $records = wppc_get_table_records($slug, $post_id_filter, $meta_key_filter, $paged, $per_page);
    $total_pages = max(1, (int) ceil($records['total'] / $per_page));
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

    if ($slug === '') {
        echo '<div class="wppc-card"><h2>ยังไม่มีตาราง custom</h2><p>ให้สร้าง slug ที่หน้า รายการประเภทตาราง ก่อนเริ่มเพิ่มหรือค้นหาข้อมูล</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(wppc_admin_url('wppc-table-types')) . '">ไปสร้างตาราง</a></p></div>';
        wppc_render_admin_page_footer();
        return;
    }

    echo '<div class="wppc-grid">';
    echo '<div class="wppc-card">';
    echo '<h2>เพิ่ม/แก้ไขข้อมูล</h2>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '">';
    wp_nonce_field('wppc_save_record');
    $meta_value_for_edit = $edit_row ? (string) $edit_row['meta_value'] : '';
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="save_record">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<input type="hidden" name="meta_id" value="' . esc_attr($edit_row ? $edit_row['meta_id'] : 0) . '">';
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
    echo '</div>';

    echo '<div class="wppc-grid">';
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
    echo '<input type="number" name="filter_post_id" min="1" value="' . esc_attr($post_id_filter) . '" placeholder="post_id" style="width:120px;"> ';
    echo '<input type="search" name="filter_meta_key" value="' . esc_attr($meta_key_filter) . '" placeholder="meta_key"> ';
    echo '<button type="submit" class="button">ค้นหา</button> ';
    if ($post_id_filter !== '' || $meta_key_filter !== '') {
        echo '<a class="button" href="' . esc_url(wppc_admin_url('wppc-data-manager', array('table' => $slug))) . '">ล้างคำค้น</a>';
    }
    echo '</form>';

    echo '<table class="widefat striped"><thead><tr><th style="width:70px;">ID</th><th style="width:110px;">รหัสโพสต์</th><th style="width:220px;">คีย์ข้อมูล</th><th>ค่าข้อมูล</th><th style="width:160px;">การทำงาน</th></tr></thead><tbody>';
    if (empty($records['rows'])) {
        echo '<tr><td colspan="5">ไม่พบข้อมูล</td></tr>';
    } else {
        foreach ($records['rows'] as $row) {
            $row_value = (string) $row['meta_value'];
            if (strlen($row_value) > 160) {
                $row_value = substr($row_value, 0, 160) . '...';
            }
            $edit_url = wppc_admin_url('wppc-data-manager', array(
                'table' => $slug,
                'paged' => $paged,
                'filter_post_id' => $post_id_filter,
                'filter_meta_key' => $meta_key_filter,
                'edit_id' => $row['meta_id'],
            ));
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
            $page_url = wppc_admin_url('wppc-data-manager', array(
                'table' => $slug,
                'filter_post_id' => $post_id_filter,
                'filter_meta_key' => $meta_key_filter,
                'paged' => $i,
            ));
            $style = $i === $paged ? 'style="font-weight:700;text-decoration:underline;"' : '';
            echo '<a ' . $style . ' href="' . esc_url($page_url) . '">' . esc_html($i) . '</a> ';
        }
        echo '</div></div>';
    }

    echo '</div>';
    wppc_render_admin_page_footer();
}
