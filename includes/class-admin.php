<?php

namespace MCS\CodeModules;

use Throwable;

defined('ABSPATH') || exit;

final class Admin {
    private const PAGE = 'modular-code-studio';
    private const JOB = 'mcs_rebuild_job';

    public static function menu(): void {
        add_options_page(__('Modular Code Studio Settings', 'modular-code-studio'), __('Modular Code Studio', 'modular-code-studio'), 'manage_options', self::PAGE, [self::class, 'screen']);
    }

    public static function submit(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot manage these settings.', 'modular-code-studio'), '', ['response' => 403]);
        }
        check_admin_referer('mcs_settings');
        $action = sanitize_key($_POST['mcs_operation'] ?? 'save');
        try {
            switch ($action) {
                case 'rebuild':
                    self::queue();
                    $message = __('Asset regeneration queued.', 'modular-code-studio');
                    break;
                case 'cleanup':
                    $message = sprintf(__('Removed %d expired asset files.', 'modular-code-studio'), Storage::cleanup());
                    break;
                default:
                    $old = Settings::all();
                    $new = Settings::update(wp_unslash((array) ($_POST['mcs'] ?? [])));
                    if ($new['bundling'] && $new['automatic'] && array_intersect(['cssBase', 'hash', 'postTypes', 'editorScope', 'bundling', 'enabled'], array_keys(array_diff_assoc(
                        array_replace($new, ['postTypes' => implode(',', $new['postTypes'])]),
                        array_replace($old, ['postTypes' => implode(',', $old['postTypes'])])
                    )))) {
                        self::queue();
                    }
                    $message = __('Settings saved.', 'modular-code-studio');
            }
            self::notice($message, false);
        } catch (Throwable $error) {
            self::notice($error->getMessage(), true);
        }
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
        exit;
    }

    private static function notice(string $message, bool $error): void {
        set_transient('mcs_admin_notice_' . get_current_user_id(), [$message, $error], MINUTE_IN_SECONDS);
    }

    public static function queue(): void {
        if (!Settings::get('bundling')) {
            throw new \RuntimeException('Enable asset bundling before regenerating files.');
        }
        foreach ((array) get_option('widget_block', []) as $number => $widget) {
            if (is_int($number) && $number > 0) {
                Assets::build('widget-' . $number, 'auto', false);
            }
        }
        $token = wp_generate_uuid4();
        update_option(self::JOB, ['token' => $token, 'status' => 'queued', 'processed' => 0, 'failed' => 0], false);
        if (wp_schedule_single_event(time(), Plugin::REBUILD, [$token, 0]) === false) {
            throw new \RuntimeException('Could not schedule asset regeneration.');
        }
    }

    public static function batch(string $token, int $after): void {
        global $wpdb;
        $job = get_option(self::JOB, []);
        if (($job['token'] ?? '') !== $token) {
            return;
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'revision' AND
             (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) ORDER BY ID ASC LIMIT 25",
            $after, '%' . $wpdb->esc_like('wp:mcs/') . '%', '%' . $wpdb->esc_like('<!-- wp:block') . '%', '%' . $wpdb->esc_like('wp:core/block') . '%'
        ));
        foreach ($rows as $row) {
            $after = (int) $row;
            $post = get_post($after);
            if ($post && (Settings::supports($post->post_type) || Storage::read($after))) {
                $result = Assets::build($after);
                $job['processed']++;
                $job['failed'] += is_wp_error($result) ? 1 : 0;
            }
        }
        if ((get_option(self::JOB, [])['token'] ?? '') !== $token) {
            return;
        }
        $job['status'] = count($rows) < 25 ? 'finished' : 'running';
        if ($job['status'] === 'running' && !wp_schedule_single_event(time() + 5, Plugin::REBUILD, [$token, $after])) {
            $job['status'] = 'schedule failed';
        }
        update_option(self::JOB, $job, false);
    }

    private static function row(string $key, string $label, $options = null): void {
        $value = Settings::get($key);
        $name = 'mcs[' . $key . ']';
        echo '<tr><th scope="row"><label for="mcs-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        if (is_bool($value)) {
            printf('<input id="mcs-%s" name="%s" type="checkbox" value="1" %s>', esc_attr($key), esc_attr($name), checked($value, true, false));
        } elseif ($key === 'postTypes') {
            foreach (Settings::types() as $type => $object) {
                printf('<label><input name="%s[]" type="checkbox" value="%s" %s> %s</label><br>', esc_attr($name), esc_attr($type), checked(in_array($type, $value, true), true, false), esc_html($object->label));
            }
        } elseif (is_array($options)) {
            printf('<select id="mcs-%s" name="%s">', esc_attr($key), esc_attr($name));
            foreach ($options as $id => $text) {
                printf('<option value="%s" %s>%s</option>', esc_attr($id), selected($id, $value, false), esc_html($text));
            }
            echo '</select>';
        } elseif (in_array($key, ['previewCss', 'previewJs'], true)) {
            printf('<textarea id="mcs-%s" name="%s" rows="4" class="large-text code">%s</textarea>', esc_attr($key), esc_attr($name), esc_textarea($value));
            echo '<p class="description">' . esc_html__('One absolute HTTP or HTTPS URL per line.', 'modular-code-studio') . '</p>';
        } else {
            printf('<input id="mcs-%s" name="%s" type="%s" value="%s" class="%s">', esc_attr($key), esc_attr($name), is_int($value) ? 'number' : 'url', esc_attr($value), is_int($value) ? 'small-text' : 'regular-text');
        }
        echo '</td></tr>';
    }

    public static function screen(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = get_transient('mcs_admin_notice_' . get_current_user_id());
        $info = Integrations::info();
        $theme = wp_get_theme();
        $job = get_option(self::JOB, []);
        echo '<div class="wrap"><h1>Modular Code Studio Settings</h1>';
        if ($notice) {
            delete_transient('mcs_admin_notice_' . get_current_user_id());
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', $notice[1] ? 'notice-error' : 'notice-success', esc_html($notice[0]));
        }
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
        wp_nonce_field('mcs_settings');
        echo '<input type="hidden" name="action" value="mcs_settings">';
        $sections = [
            'General' => ['enabled' => 'Enable plugin', 'editorScope' => ['Block editor availability', ['all' => 'All block editors', 'selected' => 'Selected post types']],
                'postTypes' => 'Post types when selected mode is enabled', 'shortcodes' => 'Enable shortcodes', 'bundling' => 'Enable page bundles'],
            'Editor' => ['theme' => ['Editor theme', ['dark' => 'Dark', 'light' => 'Light']], 'height' => 'Editor height (px)', 'fontSize' => 'Font size (px)', 'tabSize' => 'Tab size',
                'wrap' => 'Word wrap', 'minimap' => 'Code overview', 'indent' => 'Automatic indentation', 'closeBrackets' => 'Auto-close brackets and tags', 'format' => 'Format on demand'],
            'Preview' => ['preview' => 'Enable live preview', 'previewWidth' => ['Default viewport', [1440 => 'Desktop (1440 px)', 768 => 'Tablet (768 px)', 390 => 'Mobile (390 px)']],
                'previewMode' => ['Default preview mode', ['module' => 'Focus module', 'page' => 'Full page']], 'debounce' => 'Update delay (ms)', 'previewCss' => 'Additional CSS URLs', 'previewJs' => 'Additional JavaScript URLs'],
            'Asset Management' => ['automatic' => 'Build automatically on save', 'cleanup' => 'Clean up old assets automatically', 'retention' => 'Keep old files (days)', 'hash' => ['Hash algorithm', ['sha256' => 'SHA-256', 'sha384' => 'SHA-384']]],
            'Advanced' => ['cssBase' => 'CSS resource base URL', 'cssPriority' => 'CSS enqueue priority'],
        ];
        foreach ($sections as $title => $rows) {
            echo '<h2>' . esc_html($title) . '</h2><table class="form-table" role="presentation">';
            foreach ($rows as $key => $field) {
                self::row($key, is_array($field) ? $field[0] : $field, is_array($field) ? $field[1] : null);
            }
            echo '</table>';
        }
        submit_button(__('Save settings', 'modular-code-studio'), 'primary', 'mcs_operation', false);
        echo '</form><hr><h2>Asset actions</h2><form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
        wp_nonce_field('mcs_settings');
        echo '<input type="hidden" name="action" value="mcs_settings">';
        echo '<button class="button" name="mcs_operation" value="rebuild">Rebuild all assets</button> ';
        echo '<button class="button" name="mcs_operation" value="cleanup">Clear old assets</button></form>';
        if ($job) {
            printf('<p>Rebuild: %s. Processed %d, failed %d.</p>', esc_html($job['status']), (int) $job['processed'], (int) $job['failed']);
        }
        $cleanup_error = get_option('mcs_cleanup_error', '');
        if ($cleanup_error) {
            echo '<p>' . esc_html($cleanup_error) . '</p>';
        }
        echo '<h2>WPCodeBox Integration</h2><p>' . esc_html(self::extension_status($info['wpcodebox'])) . '</p>';
        echo '<p>Preview uses the normal frontend snippet conditions. Inspect loaded styles and scripts in the preview diagnostics. The Functionality Plugin mode is not detected through a public API.</p>';
        echo '<h2>LiteSpeed Integration</h2><p>' . esc_html(self::extension_status($info['litespeed'])) . '</p>';
        echo '<p>Page-specific exclusions, purge requests, and the UCSS selector list are available in the editor sidebar. Verify frontend cache hits in the response headers.</p>';
        echo '<h2>System Information</h2><dl>';
        foreach ([
            'WordPress' => get_bloginfo('version'), 'PHP' => PHP_VERSION,
            'Theme' => $theme->get('Name') . ' ' . $theme->get('Version'),
            'Parent theme' => $theme->parent() ? $theme->parent()->get('Name') . ' ' . $theme->parent()->get('Version') : 'None',
            'Server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown', 'HTTPS' => is_ssl() ? 'Yes' : 'No',
            'External object cache' => wp_using_ext_object_cache() ? 'Yes' : 'No',
            'Asset directory' => Storage::base()['dir'], 'Uploads writable' => is_writable(wp_get_upload_dir()['basedir']) ? 'Yes' : 'No',
        ] as $key => $value) {
            printf('<dt><strong>%s</strong></dt><dd>%s</dd>', esc_html($key), esc_html($value));
        }
        echo '</dl></div>';
    }

    private static function extension_status(array $extension): string {
        if (!$extension['installed']) {
            return 'Not detected';
        }
        return $extension['version'] . ($extension['active'] ? ' · Active' : ' · Inactive');
    }
}
