<?php
defined('ABSPATH') || exit;

function wppc_get_admin_pages()
{
    return array('wppc-table-types', 'wppc-data-manager', 'wppc-import-export');
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



function wppc_render_admin_view_tabs($active_page)
{
    $views = array(
        'wppc-table-types' => array(
            'label' => 'รายการประเภทตาราง',
            'description' => 'สร้างและลบตาราง postmeta ตาม slug',
        ),
        'wppc-data-manager' => array(
            'label' => 'จัดการข้อมูล',
            'description' => 'เพิ่ม แก้ไข ลบ และค้นหาข้อมูลในตาราง',
        ),
        'wppc-import-export' => array(
            'label' => 'นำเข้า/ส่งออก',
            'description' => 'นำเข้าและส่งออกข้อมูลในรูปแบบ CSV/JSON',
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


function wppc_render_table_types_table_html()
{
    $slugs = wppc_get_registered_slugs();
    ob_start();
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
    return ob_get_clean();
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
    echo '<div id="wppc-table-types-container">';
    echo wppc_render_table_types_table_html();
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
    $meta_value_filter = isset($_GET['filter_meta_value']) ? sanitize_text_field(wp_unslash($_GET['filter_meta_value'])) : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $edit_id = isset($_GET['edit_id']) ? absint($_GET['edit_id']) : 0;
    $edit_row = $edit_id > 0 ? wppc_get_record_by_id($slug, $edit_id) : null;

    wppc_render_admin_page_header(
        'จัดการข้อมูล',
        'เพิ่ม แก้ไข ลบ และค้นหาข้อมูลในแต่ละตาราง',
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
        echo '<a class="button wppc-cancel-edit" href="' . esc_url(wppc_admin_url('wppc-data-manager', array('table' => $slug))) . '">ยกเลิกแก้ไข</a>';
    }
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<div id="wppc-data-table-container">';
    echo wppc_render_data_manager_table_html($slug, $post_id_filter, $meta_key_filter, $meta_value_filter, $paged);
    echo '</div>';

    wppc_render_admin_page_footer();
}

function wppc_render_import_export_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'wp-table-postmeta-custom'));
    }

    $slug = wppc_get_admin_active_slug();

    wppc_render_admin_page_header(
        'นำเข้า/ส่งออกข้อมูล',
        'นำเข้าและส่งออกข้อมูลระหว่างตารางย่อยกับตาราง wp_postmeta หลัก ในรูปแบบ CSV หรือ JSON',
        'wppc-import-export'
    );
    wppc_render_slug_tabs($slug, 'wppc-import-export');

    if ($slug === '') {
        echo '<div class="wppc-card"><h2>ยังไม่มีตาราง custom</h2><p>ให้สร้าง slug ที่หน้า รายการประเภทตาราง ก่อนเริ่มนำเข้าหรือส่งออกข้อมูล</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(wppc_admin_url('wppc-table-types')) . '">ไปสร้างตาราง</a></p></div>';
        wppc_render_admin_page_footer();
        return;
    }

    echo '<div class="wppc-card">';
    echo '<h2>ส่งออก/นำเข้าข้อมูล</h2>';
    echo '<div class="wppc-impexp-container">';

    // Column 1: Export Controls
    echo '<div class="wppc-impexp-column">';
    echo '<h3>ส่งออกข้อมูล (Export)</h3>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-import-export')) . '" class="wppc-sync-form-group">';
    wp_nonce_field('wppc_export_data');
    echo '<input type="hidden" name="page" value="wppc-import-export">';
    echo '<input type="hidden" name="wppc_action" value="export_data">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';

    echo '<div class="wppc-form-row">';
    echo '<label>ตารางต้นทาง:</label>';
    echo '<select name="export_source"><option value="custom">ตารางย่อยปัจจุบัน (wp_postmeta_' . esc_attr($slug) . ')</option><option value="main">ตาราง wp_postmeta หลัก</option></select>';
    echo '</div>';

    echo '<div class="wppc-form-row" style="margin-top: 8px;">';
    echo '<label>รูปแบบไฟล์:</label>';
    echo '<select name="format"><option value="csv">CSV</option><option value="json">JSON</option></select>';
    echo '</div>';

    echo '<div class="wppc-form-row" style="margin-top: 8px;">';
    echo '<label>คีย์ข้อมูล:</label>';
    echo '<input type="text" name="export_keys" placeholder="เช่น price, stock (ว่างเพื่อส่งออกทั้งหมด)" style="width:220px;"> ';
    echo '</div>';

    echo '<div class="wppc-form-row" style="margin-top: 12px;">';
    echo '<button type="submit" class="button button-primary">ส่งออกไฟล์</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>'; // wppc-impexp-column (Export)

    // Column 2: Import Controls
    echo '<div class="wppc-impexp-column">';
    echo '<h3>นำเข้าข้อมูล (Import)</h3>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-import-export')) . '" enctype="multipart/form-data" class="wppc-sync-form-group">';
    wp_nonce_field('wppc_import_data');
    echo '<input type="hidden" name="page" value="wppc-import-export">';
    echo '<input type="hidden" name="wppc_action" value="import_data">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';

    echo '<div class="wppc-form-row">';
    echo '<label>ตารางปลายทาง:</label>';
    echo '<select name="import_target"><option value="custom">ตารางย่อยปัจจุบัน (wp_postmeta_' . esc_attr($slug) . ')</option><option value="main">ตาราง wp_postmeta หลัก</option></select>';
    echo '</div>';

    echo '<div class="wppc-form-row" style="margin-top: 8px;">';
    echo '<label>รูปแบบไฟล์:</label>';
    echo '<select name="import_format"><option value="csv">CSV</option><option value="json">JSON</option></select>';
    echo '</div>';

    echo '<div class="wppc-form-row" style="margin-top: 8px;">';
    echo '<label>เลือกไฟล์:</label>';
    echo '<input type="file" name="import_file" accept=".json,.csv" required style="max-width:220px;">';
    echo '</div>';

    echo '<div class="wppc-form-row" style="margin-top: 12px;">';
    echo '<button type="submit" class="button button-primary">นำเข้าไฟล์</button>';
    echo '</div>';
    echo '</form>';
    echo '<p class="description" style="margin-top:10px;">ขนาดไฟล์สูงสุด 10MB คอลัมน์ต้องมี `post_id`, `meta_key`, `meta_value`</p>';
    echo '</div>'; // wppc-impexp-column (Import)

    echo '</div>'; // wppc-impexp-container
    echo '</div>'; // wppc-card

    wppc_render_admin_page_footer();
}


/**
 * Render the HTML for the data manager table, including the search filter,
 * the bulk actions form, pagination, and hidden delete forms.
 *
 * @param string $slug
 * @param string $post_id_filter
 * @param string $meta_key_filter
 * @param string $meta_value_filter
 * @param int $paged
 * @return string
 */
function wppc_render_data_manager_table_html($slug, $post_id_filter, $meta_key_filter, $meta_value_filter, $paged)
{
    $per_page = 20;
    $records = wppc_get_table_records($slug, $post_id_filter, $meta_key_filter, $meta_value_filter, $paged, $per_page);
    $total_pages = max(1, (int) ceil($records['total'] / $per_page));

    ob_start();
    echo '<div class="wppc-card">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
    echo '<h2 style="margin:0;">ข้อมูลในตาราง</h2>';
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" style="margin:0;">';
    wp_nonce_field('wppc_truncate_table');
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="truncate_table">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'ยืนยันการล้างข้อมูลทั้งหมดในตาราง ' . esc_js($slug) . '? การกระทำนี้ไม่สามารถย้อนกลับได้\');">ล้างข้อมูลทั้งตาราง</button>';
    echo '</form>';
    echo '</div>';

    echo '<form method="get" action="' . esc_url(admin_url('tools.php')) . '" class="wppc-inline-actions" style="margin-bottom:10px;">';
    echo '<input type="hidden" name="page" value="wppc-data-manager"><input type="hidden" name="table" value="' . esc_attr($slug) . '">';
    echo '<input type="number" name="filter_post_id" min="1" value="' . esc_attr($post_id_filter) . '" placeholder="post_id" style="width:120px;"> ';
    echo '<input type="search" name="filter_meta_key" value="' . esc_attr($meta_key_filter) . '" placeholder="meta_key"> ';
    echo '<input type="search" name="filter_meta_value" value="' . esc_attr($meta_value_filter) . '" placeholder="meta_value"> ';
    echo '<button type="submit" class="button">ค้นหา</button> ';
    if ($post_id_filter !== '' || $meta_key_filter !== '' || $meta_value_filter !== '') {
        echo '<a class="button wppc-clear-search" href="' . esc_url(wppc_admin_url('wppc-data-manager', array('table' => $slug))) . '">ล้างคำค้น</a>';
    }
    echo '</form>';

    // Bulk delete form wrapping the table
    echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" id="wppc-bulk-form">';
    wp_nonce_field('wppc_bulk_delete');
    echo '<input type="hidden" name="page" value="wppc-data-manager">';
    echo '<input type="hidden" name="wppc_action" value="bulk_delete">';
    echo '<input type="hidden" name="table" value="' . esc_attr($slug) . '">';

    if (!empty($records['rows'])) {
        echo '<div class="wppc-inline-actions" style="margin-bottom:8px;">';
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'ยืนยันการลบข้อมูลที่เลือก?\');">ลบที่เลือก</button> ';
        echo '<label style="cursor:pointer;"><input type="checkbox" id="wppc-select-all"> เลือกทั้งหมด</label>';
        echo '</div>';
    }

    echo '<table class="widefat striped"><thead><tr><th style="width:40px;"><input type="checkbox" id="wppc-select-all-top"></th><th style="width:70px;">ID</th><th style="width:110px;">รหัสโพสต์</th><th style="width:220px;">คีย์ข้อมูล</th><th>ค่าข้อมูล</th><th style="width:160px;">การทำงาน</th></tr></thead><tbody>';
    if (empty($records['rows'])) {
        echo '<tr><td colspan="6">ไม่พบข้อมูล</td></tr>';
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
                'filter_meta_value' => $meta_value_filter,
                'edit_id' => $row['meta_id'],
            ));
            echo '<tr>';
            echo '<td><input type="checkbox" name="bulk_ids[]" value="' . esc_attr($row['meta_id']) . '" class="wppc-row-cb"></td>';
            echo '<td>' . esc_html($row['meta_id']) . '</td>';
            echo '<td>' . esc_html($row['post_id']) . '</td>';
            echo '<td><code>' . esc_html($row['meta_key']) . '</code></td>';
            echo '<td><code>' . esc_html($row_value) . '</code></td>';
            echo '<td><a class="button button-small wppc-edit-record" href="' . esc_url($edit_url) . '" data-id="' . esc_attr($row['meta_id']) . '">แก้ไข</a> ';
            echo '<button type="button" class="button button-small button-link-delete" onclick="if(confirm(\'ยืนยันการลบข้อมูลนี้?\')) { var f=document.getElementById(\'wppc-del-' . esc_attr($row['meta_id']) . '\'); f.submit(); }">ลบ</button></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</form>';

    // Individual delete forms (hidden, outside the bulk form to avoid nesting)
    if (!empty($records['rows'])) {
        foreach ($records['rows'] as $row) {
            echo '<form method="post" action="' . esc_url(wppc_admin_url('wppc-data-manager')) . '" id="wppc-del-' . esc_attr($row['meta_id']) . '" style="display:none;">';
            wp_nonce_field('wppc_delete_record');
            echo '<input type="hidden" name="page" value="wppc-data-manager"><input type="hidden" name="wppc_action" value="delete_record"><input type="hidden" name="table" value="' . esc_attr($slug) . '"><input type="hidden" name="meta_id" value="' . esc_attr($row['meta_id']) . '">';
            echo '</form>';
        }
    }

    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0;">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $page_url = wppc_admin_url('wppc-data-manager', array(
                'table' => $slug,
                'filter_post_id' => $post_id_filter,
                'filter_meta_key' => $meta_key_filter,
                'filter_meta_value' => $meta_value_filter,
                'paged' => $i,
            ));
            $class_attr = $i === $paged ? ' class="current"' : '';
            $style = $i === $paged ? ' style="font-weight:700;text-decoration:underline;"' : '';
            echo '<a' . $class_attr . $style . ' href="' . esc_url($page_url) . '">' . esc_html($i) . '</a> ';
        }
        echo '</div></div>';
    }

    echo '</div>'; // wppc-card
    return ob_get_clean();
}
