<?php
/*
Plugin Name:	JSON Tables
Plugin URI:		
Description:	This plugin allows a scheduled cron job to download a JSON from a directory and update the database with the new data. Then allowing a shortcode to embed the table.
Version:		1.0.7
Author:			E2E Studios
Author URI:		https://e2estudios.com
License:		GPL-2.0+
License URI:	http://www.gnu.org/licenses/gpl-2.0.txt

This plugin is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

This plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with This plugin. If not, see {URI to Plugin License}.
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly


require 'plugin-update-checker/plugin-update-checker.php';
require 'aws-sdk-php/aws-autoloader.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://github.com/e2es/json-tables',
    __FILE__,
    'json-tables'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');

class JsonTables {
    public function __construct() {
        add_action('init', [$this, 'create_custom_post_type']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_post_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_sync_json_data', [$this, 'sync_json_data']);
        add_shortcode('json-table', [$this, 'render_json_table_shortcode']);
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action('json_tables_sync_hook', [$this, 'perform_cron_job']);
        add_action('admin_post_json_tables_clear_log', [$this, 'handle_clear_log']);
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_plugin']);

        // Create log table on update for existing installs
        if (get_option('json_tables_db_version') !== '1.0.7') {
            $this->create_log_table();
            update_option('json_tables_db_version', '1.0.7');
        }
    }

    public function create_custom_post_type() {
        register_post_type('json_table', [
            'labels' => [
                'name' => 'JSON Tables',
                'singular_name' => 'JSON Table'
            ],
            'public' => true,
            'has_archive' => true,
            'supports' => ['title']
        ]);
    }

    public function add_admin_menu() {
        add_options_page('JSON Tables Settings', 'JSON Tables', 'manage_options', 'json_tables_settings', [$this, 'create_admin_page']);
        add_action('admin_init', [$this, 'register_json_tables_settings']);
    }

    public function register_json_tables_settings() {
        register_setting('json_tables_settings', 'json_tables_cron_interval', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 5  // Default interval set to 5 minutes
        ]);



        add_settings_section(
            'json_tables_main_section',
            'Main Settings',
            null,
            'json_tables_settings'
        );

        add_settings_field(
            'json_tables_s3_access_key_field',
            'S3 Access Key',
            [$this, 'json_tables_s3_access_key_field_html'],
            'json_tables_settings',
            'json_tables_main_section'
        );

        // Add field for Secret Key
        add_settings_field(
            'json_tables_s3_secret_key_field',
            'S3 Secret Key',
            [$this, 'json_tables_s3_secret_key_field_html'],
            'json_tables_settings',
            'json_tables_main_section'
        );

        // Add field for Bucket Name
        add_settings_field(
            'json_tables_s3_bucket_name_field',
            'S3 Bucket Name',
            [$this, 'json_tables_s3_bucket_name_field_html'],
            'json_tables_settings',
            'json_tables_main_section'
        );

        // Add field for Region
        add_settings_field(
            'json_tables_s3_region_field',
            'S3 Region',
            [$this, 'json_tables_s3_region_field_html'],
            'json_tables_settings',
            'json_tables_main_section'
        );

        add_settings_field(
            'json_tables_cron_interval_field',
            'Cron Job Interval (minutes)',
            [$this, 'json_tables_cron_interval_field_html'],
            'json_tables_settings',
            'json_tables_main_section'
        );
    }

    public function json_tables_cron_interval_field_html() {
        $interval = get_option('json_tables_cron_interval', 5);
        echo '<input type="number" id="json_tables_cron_interval" name="json_tables_cron_interval" value="' . esc_attr($interval) . '" min="1">';
    }

    public function json_tables_s3_access_key_field_html() {
        $access_key = get_option('json_tables_s3_access_key');
        echo '<input type="text" name="json_tables_s3_access_key" value="' . esc_attr($access_key) . '" />';
    }

    public function json_tables_s3_secret_key_field_html() {
        $secret_key = get_option('json_tables_s3_secret_key');
        echo '<input type="password" name="json_tables_s3_secret_key" value="' . esc_attr($secret_key) . '" />';
    }

    public function json_tables_s3_bucket_name_field_html() {
        $bucket_name = get_option('json_tables_s3_bucket_name');
        echo '<input type="text" name="json_tables_s3_bucket_name" value="' . esc_attr($bucket_name) . '" />';
    }

    public function json_tables_s3_region_field_html() {
        $region = get_option('json_tables_s3_region');
        echo '<input type="text" name="json_tables_s3_region" value="' . esc_attr($region) . '" />';
    }

    public function create_admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1>JSON Tables</h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=json_tables_settings&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=json_tables_settings&tab=request_log" class="nav-tab <?php echo $active_tab === 'request_log' ? 'nav-tab-active' : ''; ?>">Request Log</a>
            </h2>
            <?php
            if ($active_tab === 'request_log') {
                $this->render_request_log();
            } else {
                ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('json_tables_settings');
                    do_settings_sections('json_tables_settings');
                    submit_button();
                    ?>
                </form>
                <?php
            }
            ?>
        </div>
        <?php
    }

    public function render_request_log() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'json_tables_log';

        $per_page = 50;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_items / $per_page);

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        $clear_url = wp_nonce_url(admin_url('admin-post.php?action=json_tables_clear_log'), 'json_tables_clear_log');
        ?>
        <form method="post" action="<?php echo esc_url($clear_url); ?>" style="margin: 12px 0;">
            <input type="submit" class="button" value="Clear Log" onclick="return confirm('Are you sure you want to clear the entire log?');" />
        </form>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:150px;">Date</th>
                    <th style="width:200px;">Table Name</th>
                    <th>URL</th>
                    <th style="width:90px;">Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="5">No log entries found.</td></tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) :
                        $post_title = get_the_title($log->post_id);
                        if (!$post_title) $post_title = '#' . $log->post_id;
                        $badge_color = $log->status === 'success' ? '#00a32a' : '#d63638';
                    ?>
                        <tr>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td><?php echo esc_html($post_title); ?></td>
                            <td style="word-break:break-all;"><?php echo esc_html($log->url); ?></td>
                            <td><span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:<?php echo $badge_color; ?>;"><?php echo esc_html($log->status); ?></span></td>
                            <td><?php echo esc_html($log->message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total_items; ?> items</span>
                    <?php
                    $page_links = paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ]);
                    echo $page_links;
                    ?>
                </div>
            </div>
        <?php endif;
    }

    public function handle_clear_log() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('json_tables_clear_log');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}json_tables_log");

        wp_safe_redirect(admin_url('options-general.php?page=json_tables_settings&tab=request_log'));
        exit;
    }

    public function add_meta_boxes() {
        add_meta_box('json_table_data', 'JSON Table Data', [$this, 'json_table_data_callback'], 'json_table', 'normal', 'high');
    }

    public function json_table_data_callback($post) {
        wp_nonce_field(basename(__FILE__), 'json_tables_nonce');
        ?>
        <p>
            <label for="json_data">Table Container Class</label>
            <input type="text" id="table_container_class" name="table_container_class" class="widefat" value="<?php echo esc_textarea(get_post_meta($post->ID, 'table_container_class', true)); ?>"/>
        </p>
        <p>
            <label for="json_url">JSON URL:</label>
            <input type="url" id="json_url" name="json_url" value="<?php echo esc_attr(get_post_meta($post->ID, 'json_url', true)); ?>" class="widefat">
        </p>
        <p>
            <label for="json_data">JSON Data:</label>
            <textarea id="json_data" name="json_data" class="widefat" rows="10"><?php echo esc_textarea(get_post_meta($post->ID, 'json_data', true)); ?></textarea>
        </p>
        <p>
            <label for="last_imported_date">Last Imported Date:</label>
            <input type="text" id="last_imported_date" name="last_imported_date" value="<?php echo esc_attr(get_post_meta($post->ID, 'last_imported_date', true)); ?>" class="widefat" readonly>
        </p>
        <p>
            <button type="button" class="button" onclick="syncJsonData('<?php echo admin_url('admin-ajax.php'); ?>', <?php echo $post->ID; ?>);">Sync Now</button>
        </p>
        <script>
            function syncJsonData(ajaxurl, postId) {
                var data = {
                    'action': 'sync_json_data',
                    'post_id': postId
                };
                jQuery.post(ajaxurl, data, function(response) {
                    alert('Data synced successfully!');
                    location.reload();
                });
            }
        </script>
        <?php
    }

    public function enqueue_scripts($hook) {
        if ('post.php' === $hook || 'post-new.php' === $hook) {
            wp_enqueue_script('jquery');
        }
    }

    public function save_post_data($post_id) {
        if (!isset($_POST['json_tables_nonce']) || !wp_verify_nonce($_POST['json_tables_nonce'], basename(__FILE__))) {
            return $post_id;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        if (isset($_POST['table_container_class'])) {
            update_post_meta($post_id, 'table_container_class', sanitize_text_field($_POST['table_container_class']));
        }
        if (isset($_POST['json_url'])) {
            update_post_meta($post_id, 'json_url', esc_url_raw($_POST['json_url']));
        }
        if (isset($_POST['json_data'])) {
            update_post_meta($post_id, 'json_data', sanitize_textarea_field($_POST['json_data']));
        }
        if (isset($_POST['last_imported_date'])) {
            update_post_meta($post_id, 'last_imported_date', sanitize_text_field($_POST['last_imported_date']));
        }
    }

    public function add_cron_interval($schedules) {
        $interval = get_option('json_tables_cron_interval', 5); // Fetch the interval from the settings
        $schedules['json_tables_interval'] = [
            'interval' => $interval * 60, // Convert minutes to seconds
            'display'  => sprintf(__('Every %d Minutes'), $interval)
        ];
        return $schedules;
    }

    public function create_log_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'json_tables_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            url text NOT NULL,
            status varchar(20) NOT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function activate_plugin() {
        $this->create_custom_post_type();
        flush_rewrite_rules();
        $this->create_log_table();
        if (!wp_next_scheduled('json_tables_sync_hook')) {
            wp_schedule_event(time(), 'json_tables_interval', 'json_tables_sync_hook');
        }
    }

    public function deactivate_plugin() {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('json_tables_sync_hook');
    }

    // Ensure that the plugin re-adjusts the cron job timing based on updated settings
    public function adjust_cron_job_timing() {
        $next_scheduled = wp_next_scheduled('json_tables_sync_hook');
        wp_unschedule_event($next_scheduled, 'json_tables_sync_hook');
        wp_schedule_event(time(), 'json_tables_interval', 'json_tables_sync_hook');
    }

    public function perform_cron_job() {
        $args = [
            'post_type' => 'json_table',
            'posts_per_page' => -1
        ];
        $posts = get_posts($args);
        foreach ($posts as $post) {
            $this->sync_json_data($post->ID);
        }
    }

    private function log_request($post_id, $url, $status, $message = '') {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'json_tables_log',
            [
                'post_id'    => $post_id,
                'url'        => $url,
                'status'     => $status,
                'message'    => $message,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    public function sync_json_data($post_id) {
        //$post_id = $_POST['post_id'] ?? 0;
        if (!$post_id) return;

        $json_url = get_post_meta($post_id, 'json_url', true);
        if ($json_url) {

            if (!get_option('json_tables_s3_access_key')) {

                $response = wp_remote_get($json_url);
                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    update_post_meta($post_id, 'json_data', $body);
                    update_post_meta($post_id, 'last_imported_date', current_time('mysql'));
                    $this->log_request($post_id, $json_url, 'success');
                } else {
                    $this->log_request($post_id, $json_url, 'failed', $response->get_error_message());
                }
            } else {
                $access_key = get_option('json_tables_s3_access_key');
                $secret_key = get_option('json_tables_s3_secret_key');
                $bucket_name = get_option('json_tables_s3_bucket_name');
                $region = get_option('json_tables_s3_region');

                try {
                    // Use retrieved credentials for S3 operations
                    $s3 = new Aws\S3\S3Client([
                        'version' => 'latest',
                        'region' => $region,
                        'credentials' => [
                            'key' => $access_key,
                            'secret' => $secret_key,
                        ],
                    ]);

                    // Assume $json_url contains S3 object URL
                    $response = $s3->getObject([
                        'Bucket' => $bucket_name,
                        'Key' => $json_url,
                    ]);

                    if ($response['Body']) {
                        $body = $response['Body']->getContents();
                        update_post_meta($post_id, 'json_data', $body);
                        update_post_meta($post_id, 'last_imported_date', current_time('mysql'));
                        $this->log_request($post_id, $json_url, 'success');
                    }
                } catch (Exception $e) {
                    $this->log_request($post_id, $json_url, 'failed', $e->getMessage());
                }
            }
        }
    }


    public function render_json_table_shortcode($atts) {
        $atts = shortcode_atts(['id' => ''], $atts);
        $post_id = $atts['id'];

        $html_output = $this->render_json_table($post_id);
        return $html_output;
    }

    private function render_json_table($post_id) {
        $json_data = get_post_meta($post_id, 'json_data', true);
        $container_class = get_post_meta($post_id, 'table_container_class',true);
        $table_data = json_decode($json_data, true);
        $output = '<div class="'. $container_class .'"><table class="' . htmlspecialchars($table_data['class']) . '">';
        $output .= '<caption class="' . htmlspecialchars($table_data['title_class'] ?? '') . '">' . htmlspecialchars($table_data['title']) . '</caption>';

        foreach ($table_data['table'] as $row) {
            $output .= '<tr>';
            foreach ($row as $cell) {
                $output .= '<td rowspan="' . $cell['rowspan'] . '" colspan="' . $cell['colspan'] . '" class="' . $cell['class'] . '">';
                $output .= $cell['content']; // Assuming safe content
                $output .= '</td>';
            }
            $output .= '</tr>';
        }
        $output .= '</table></div>';
        return $output;
    }
}

new JsonTables();

