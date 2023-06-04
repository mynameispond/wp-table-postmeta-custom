<?php
/*
Plugin Name: WP Table Postmeta Custom
Plugin URI: https://github.com/mynameispond/wp-table-postmeta-custom
Description: New Table Postmeta and new function
Version: 0.0.1
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
