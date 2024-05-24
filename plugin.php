<?php
/*
Plugin Name:	JSON Tables
Plugin URI:		
Description:	This plugin allows a scheduled cron job to download a JSON from a directory and update the database with the new data. Then allowing a shortcode to embed the table.
Version:		1.0.6
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
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_plugin']);
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
        ?>
        <div class="wrap">
            <h2>JSON Tables Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('json_tables_settings');
                do_settings_sections('json_tables_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
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

    public function activate_plugin() {
        $this->create_custom_post_type();
        flush_rewrite_rules();
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
                }
            } else{
                $access_key = get_option('json_tables_s3_access_key');
                $secret_key = get_option('json_tables_s3_secret_key');
                $bucket_name = get_option('json_tables_s3_bucket_name');
                $region = get_option('json_tables_s3_region');

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

