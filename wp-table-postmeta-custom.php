<?php
/*
Plugin Name: WP Table Postmeta Custom
Plugin URI: https://github.com/mynameispond/wp-table-postmeta-custom
Description: New Table Postmeta and new function
Version: 0.2.0
Author: mynameispond
Author URI: https://github.com/mynameispond
*/

function wppc_plugin_activate()
{
    $wppc_plugin_activate = get_option('wppc_plugin_activate');
    if (empty($wppc_plugin_activate)) {
        add_option('wppc_plugin_activate', '0.0.1');
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE `{$wpdb->prefix}postmeta_wppc` (
            `meta_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `post_id` BIGINT(20) UNSIGNED NOT NULL,
            `meta_key` VARCHAR(255) NOT NULL,
            `meta_value` LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`meta_id`),
            KEY `post_id` (`post_id`),
            KEY `meta_key` (`meta_key`(191))
        ) ENGINE=MyISAM {$charset_collate};
        ";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
register_activation_hook(__FILE__, 'wppc_plugin_activate');

function get_post_meta_custom($post_id, $meta_key, $from_main = false)
{
    global $wpdb;
    $meta_value = $wpdb->get_var("SELECT meta_value FROM {$wpdb->prefix}postmeta_wppc WHERE post_id='$post_id' AND meta_key='$meta_key'");
    if ($from_main === true) {
        if (empty($meta_value)) {
            $meta_value = $wpdb->get_var("SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id='$post_id' AND meta_key='$meta_key'");
        }
    }
    return $meta_value;
}

function update_post_meta_custom($post_id, $meta_key, $meta_value)
{
    global $wpdb;
    $meta_id = $wpdb->get_var("SELECT meta_id FROM {$wpdb->prefix}postmeta_wppc WHERE post_id='$post_id' AND meta_key='$meta_key'");
    if (empty($meta_id)) {
        $wpdb->insert(
            "{$wpdb->prefix}postmeta_wppc",
            array(
                'post_id' => $post_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            )
        );
    } else {
        $wpdb->update(
            "{$wpdb->prefix}postmeta_wppc",
            array(
                'meta_value' => $meta_value
            ),
            array('meta_id' => $meta_id)
        );
    }
}

function delete_post_meta_custom($post_id, $meta_key)
{
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta_wppc WHERE post_id = '$post_id' AND meta_key='$meta_key'");
}

if (is_admin()) {

    add_action('admin_menu', 'wppc_add_plugin_page');
}
function wppc_add_plugin_page()
{
    add_options_page(
        'Postmeta Custom',
        'Postmeta Custom',
        'manage_options',
        'wppc-plugin-page',
        'wppc_plugin_page'
    );
}

function wppc_plugin_page()
{
    $post_id = isset($_GET['post_id']) ? addslashes(strip_tags($_GET['post_id'])) : '';
    $meta_key = isset($_GET['meta_key']) ? addslashes(strip_tags($_GET['meta_key'])) : '';
?>
    <style>
        .settings_page_wppc-plugin-page #wpcontent {
            padding-left: 0;
        }

        .wppc-header {
            text-align: center;
            margin: 0 0 1rem;
            background: #fff;
            border-bottom: 1px solid #dcdcde;
        }

        .wppc-title-section {
            display: flex;
            align-items: center;
            justify-content: center;
            clear: both;
            padding-top: 8px;
        }

        .wppc-tabs-wrapper {
            display: -ms-inline-grid;
            -ms-grid-columns: 1fr;
            vertical-align: top;
            display: inline-grid;
            grid-template-columns: 1fr;
        }

        .wppc-tab {
            display: block;
            text-decoration: none;
            color: inherit;
            padding: 0.5rem 1rem 1rem;
            margin: 0 1rem;
            transition: box-shadow .5s ease-in-out;
        }

        .wppc-tab.active {
            box-shadow: inset 0 -3px #3582c4;
            font-weight: 600;
        }

        .wppc-body {
            max-width: 1000px;
            margin: 0 auto;
        }

        .wppc-paginate {
            margin-top: 15px;
            text-align: center;
        }

        .wppc-paginate>* {
            padding: 7px 10px;
            display: inline-block;
            border: 1px solid #cacaca;
            text-decoration: none;
        }

        .wppc-paginate>.current {
            background-color: #cacaca;
        }
    </style>
    <div class="wppc-header">
        <div class="wppc-title-section">
            <h1>Postmeta Custom</h1>
        </div>
        <nav class="wppc-tabs-wrapper hide-if-no-js" aria-label="Secondary menu">
            <a href="<?php echo site_url('/'); ?>wp-admin/options-general.php?page=wppc-plugin-page" class="wppc-tab active" aria-current="true">Search</a>
        </nav>
    </div>
    <div class="wppc-body">
        <form method="get" action="<?php echo site_url('/'); ?>wp-admin/options-general.php">
            <input type="hidden" name="page" value="wppc-plugin-page">
            <input type="text" name="post_id" value="<?php echo $post_id; ?>" style="width:200px" placeholder="Post ID">
            <input type="text" name="meta_key" value="<?php echo $meta_key; ?>" style="width:200px" placeholder="KEY">
            <button type="submit" class="button">Search</button>
        </form>
        <br>
        <table border="1" cellpadding="1" class="wp-list-table widefat striped table-view-list">
            <thead>
                <tr>
                    <th width="70px">ID</th>
                    <th width="70px">POST ID</th>
                    <th>KEY</th>
                    <th>VALUE</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $pagenum = isset($_GET['pagenum']) ? absint($_GET['pagenum']) : 1;
                $limit = 20;
                $offset = ($pagenum - 1) * $limit;

                global $wpdb;
                $strSql = "SELECT * FROM {$wpdb->prefix}postmeta_wppc WHERE 1=1 ";
                if (!empty($post_id)) {
                    $strSql .= " AND post_id='{$post_id}'";
                }
                if (!empty($meta_key)) {
                    $strSql .= " AND meta_key='{$meta_key}'";
                }

                $total = $wpdb->get_var(str_replace('*', 'COUNT(`meta_id`)', $strSql));
                $num_of_pages = ceil($total / $limit);

                $strSql .= " ORDER BY meta_id DESC LIMIT $offset, $limit";

                $rs = $wpdb->get_results($strSql);
                if (!empty($rs)) {
                    foreach ($rs as $data) {
                ?>
                        <tr>
                            <td><?php echo $data->meta_id; ?></td>
                            <td><?php echo $data->post_id; ?></td>
                            <td><?php echo $data->meta_key; ?></td>
                            <td><?php echo $data->meta_value; ?></td>
                        </tr>
                <?php
                    }
                }
                ?>
            </tbody>
        </table>
        <?php
        if ($num_of_pages > 1) {
            $page_links = paginate_links(array(
                'base' => add_query_arg('pagenum', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $num_of_pages,
                'current' => $pagenum
            ));

            if ($page_links) {
                echo '<div class="wppc-paginate">' . $page_links . '</div>';
            }
        }
        ?>
    </div>
<?php
}
