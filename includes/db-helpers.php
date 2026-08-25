<?php
defined('ABSPATH') || exit;

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

    /**
     * Fires after a custom postmeta table is created.
     *
     * @param string $slug       The table slug.
     * @param string $table_name The full table name in the database.
     */
    do_action('wppc_table_created', $slug, $table_name);

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

    /**
     * Fires after a custom postmeta table is dropped.
     *
     * @param string $slug       The table slug that was removed.
     * @param string $table_name The full table name that was dropped.
     */
    do_action('wppc_table_dropped', $slug, $table_name);

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

    /**
     * Filters the meta value before it is saved to the custom table.
     *
     * @param string $stored_value The serialized/prepared value to store.
     * @param string $table_slug   The table slug.
     * @param int    $post_id      The post ID.
     * @param string $meta_key     The meta key.
     * @param mixed  $meta_value   The original (raw) meta value.
     */
    $stored_value = apply_filters('wppc_pre_update_meta_value', $stored_value, $table_slug, $post_id, $meta_key, $meta_value);

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
        $success = $updated !== false;
    } else {
        $success = (bool) $wpdb->insert(
            $table_name,
            array(
                'post_id' => $post_id,
                'meta_key' => $meta_key,
                'meta_value' => $stored_value,
            ),
            array('%d', '%s', '%s')
        );
    }

    if ($success) {
        /**
         * Fires after a meta value is successfully inserted or updated.
         *
         * @param string $table_slug The table slug.
         * @param int    $post_id    The post ID.
         * @param string $meta_key   The meta key.
         * @param string $stored_value The value that was stored.
         */
        do_action('wppc_updated_post_meta', $table_slug, $post_id, $meta_key, $stored_value);
    }

    return $success;
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
        $result = (bool) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_sql} WHERE post_id = %d AND meta_key = %s AND meta_value = %s",
                $post_id,
                $meta_key,
                wppc_prepare_meta_value_for_store($meta_value)
            )
        );
    } else {
        $result = (bool) $wpdb->delete(
            $table_name,
            array(
                'post_id' => $post_id,
                'meta_key' => $meta_key,
            ),
            array('%d', '%s')
        );
    }

    if ($result) {
        /**
         * Fires after a meta row is successfully deleted.
         *
         * @param string $table_slug The table slug.
         * @param int    $post_id    The post ID.
         * @param string $meta_key   The meta key that was deleted.
         */
        do_action('wppc_deleted_post_meta', $table_slug, $post_id, $meta_key);
    }

    return $result;
}

/**
 * Automatically clean up custom postmeta records across all registered custom tables when a post is deleted.
 *
 * @param int $post_id The ID of the post being deleted.
 * @return int Total number of custom meta records deleted across all tables.
 */
function wppc_cleanup_custom_meta_on_delete_post($post_id)
{
    global $wpdb;
    $post_id = absint($post_id);
    if ($post_id <= 0) {
        return 0;
    }

    $slugs = wppc_get_registered_slugs();
    $total_deleted = 0;

    foreach ($slugs as $slug) {
        if (!wppc_table_exists($slug)) {
            continue;
        }

        $table_name = wppc_get_table_name($slug);
        $deleted = $wpdb->delete(
            $table_name,
            array('post_id' => $post_id),
            array('%d')
        );

        if ($deleted !== false && $deleted > 0) {
            $total_deleted += (int) $deleted;
            /**
             * Fires after custom post meta records for a specific post and table are deleted.
             *
             * @param string $slug    The table slug.
             * @param int    $post_id The post ID.
             * @param int    $deleted Number of records deleted.
             */
            do_action('wppc_cleaned_up_table_post_meta', $slug, $post_id, (int) $deleted);
        }
    }

    /**
     * Fires after custom post meta cleanup finishes for all registered tables.
     *
     * @param int   $post_id       The post ID.
     * @param int   $total_deleted Total number of records deleted.
     * @param array $slugs         Registered slugs processed.
     */
    do_action('wppc_cleaned_up_post_custom_meta', $post_id, $total_deleted, $slugs);

    return $total_deleted;
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

function wppc_get_table_records($slug, $post_id_filter = '', $meta_key_filter = '', $meta_value_filter = '', $paged = 1, $per_page = 20)
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
    $meta_value_filter = trim((string) $meta_value_filter);
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

    if ($meta_value_filter !== '') {
        $where_parts[] = 'meta_value LIKE %s';
        $where_values[] = '%' . $wpdb->esc_like($meta_value_filter) . '%';
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

    // Strip UTF-8 BOM if present on the first column header
    if (isset($header[0]) && strpos($header[0], "\xEF\xBB\xBF") === 0) {
        $header[0] = substr($header[0], 3);
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

function wppc_import_rows_into_table($slug, $rows, $target_type = 'custom')
{
    global $wpdb;
    if ($target_type === 'main') {
        $table_name = $wpdb->postmeta;
    } else {
        $table_name = wppc_get_table_name($slug);
    }
    $inserted = 0;
    $skipped = 0;

    // เริ่มธุรกรรมสำหรับการนำเข้าปริมาณสูงแบบประหยัดพลังงาน
    $wpdb->query('START TRANSACTION');

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

    // คอมมิตคำสั่งทั้งหมดเข้าสู่ดิสก์
    $wpdb->query('COMMIT');

    return array('inserted' => $inserted, 'skipped' => $skipped);
}

function wppc_stream_export_table_data($slug, $format, $source_type = 'custom', $keys = array())
{
    global $wpdb;
    if ($source_type === 'main') {
        $table_name = $wpdb->postmeta;
    } else {
        if (!wppc_table_exists($slug)) {
            wp_die(esc_html__('ไม่พบตารางที่เลือก', 'wp-table-postmeta-custom'));
        }
        $table_name = wppc_get_table_name($slug);
    }

    $table_sql = wppc_escape_identifier($table_name);
    
    $where_sql = '';
    $where_values = array();
    if (!empty($keys) && is_array($keys)) {
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $where_sql = " WHERE meta_key IN ({$placeholders})";
        $where_values = $keys;
    }

    $filename = 'wppc-' . ($source_type === 'main' ? 'wp_postmeta' : $slug) . '-' . gmdate('Ymd-His');
    $batch_size = 5000;

    if ($format === 'json') {
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo '[';
        $first = true;
        $offset = 0;
        do {
            $chunk_sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$table_sql}{$where_sql} ORDER BY meta_id ASC LIMIT {$batch_size} OFFSET {$offset}";
            $rows = !empty($where_values)
                ? $wpdb->get_results($wpdb->prepare($chunk_sql, $where_values), ARRAY_A)
                : $wpdb->get_results($chunk_sql, ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!$first) {
                    echo ',';
                }
                echo wp_json_encode($row, JSON_UNESCAPED_UNICODE);
                $first = false;
            }
            $offset += $batch_size;
            unset($rows);
        } while (true);
        echo ']';
        exit;
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    // แนบ UTF-8 BOM เพื่อความเข้ากันได้กับ Excel ภาษาไทย
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array('meta_id', 'post_id', 'meta_key', 'meta_value'));

    $offset = 0;
    do {
        $chunk_sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$table_sql}{$where_sql} ORDER BY meta_id ASC LIMIT {$batch_size} OFFSET {$offset}";
        $rows = !empty($where_values)
            ? $wpdb->get_results($wpdb->prepare($chunk_sql, $where_values), ARRAY_A)
            : $wpdb->get_results($chunk_sql, ARRAY_A);

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            fputcsv($output, array(
                isset($row['meta_id']) ? $row['meta_id'] : '',
                isset($row['post_id']) ? $row['post_id'] : '',
                isset($row['meta_key']) ? wppc_escape_csv_cell($row['meta_key']) : '',
                isset($row['meta_value']) ? wppc_escape_csv_cell($row['meta_value']) : '',
            ));
        }
        $offset += $batch_size;
        unset($rows);
    } while (true);

    fclose($output);
    exit;
}

function wppc_escape_csv_cell($value)
{
    if ($value === null) {
        return '';
    }

    if (!is_scalar($value)) {
        return '';
    }

    $str = (string) $value;
    if ($str !== '' && in_array($str[0], array('=', '+', '-', '@', "\t", "\r", '|'), true)) {
        return "'" . $str;
    }

    return $str;
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
