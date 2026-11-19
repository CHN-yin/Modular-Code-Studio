<?php

namespace MCS\CodeModules;

use WP_Error;
use WP_REST_Request;

defined('ABSPATH') || exit;

final class Plugin {
    public const CLEANUP = 'mcs_cleanup_assets';
    public const REBUILD = 'mcs_rebuild_batch';

    public static function boot(): void {
        add_action('init', [self::class, 'init']);
        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_post_mcs_settings', [Admin::class, 'submit']);
        add_action(self::CLEANUP, [self::class, 'cleanup']);
        add_action(self::REBUILD, [Admin::class, 'batch'], 10, 2);
        // Keep the authorization boundary in place while the feature is disabled.
        add_filter('wp_insert_post_data', [Modules::class, 'on_save'], PHP_INT_MAX, 3);
        add_filter('pre_update_option_widget_block', [Sources::class, 'sign_widgets'], PHP_INT_MAX, 2);
        add_action('update_option_widget_block', [Sources::class, 'save_widgets'], 20, 2);
        add_action('add_option_widget_block', static function ($name, $widgets): void {
            Sources::save_widgets([], $widgets);
        }, 20, 2);
        Integrations::register();
    }

    public static function activate(): void {
        if (!wp_next_scheduled(self::CLEANUP)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP);
        }
    }

    public static function deactivate(): void {
        wp_unschedule_hook(self::CLEANUP);
        wp_unschedule_hook(self::REBUILD);
    }

    public static function cleanup(): void {
        if (Settings::get('cleanup')) {
            try {
                Storage::cleanup();
            } catch (\Throwable $exception) {
                update_option('mcs_cleanup_error', sanitize_text_field($exception->getMessage()), false);
                return;
            }
            delete_option('mcs_cleanup_error');
        }
    }

    public static function init(): void {
        Settings::upgrade();
        if (!Settings::get('enabled')) {
            return;
        }
        add_filter('block_categories_all', [self::class, 'categories']);
        register_block_type(DIR . 'build/code-module', ['render_callback' => [Modules::class, 'render']]);
        add_filter('allowed_block_types_all', [self::class, 'allowed'], PHP_INT_MAX, 2);
        add_action('enqueue_block_editor_assets', [self::class, 'editor_config']);
        add_action('wp_after_insert_post', [Assets::class, 'on_save'], 20, 4);
        add_action('deleted_post', [Assets::class, 'on_delete'], 20, 2);
        add_filter('the_content', [Modules::class, 'begin_content'], -1);
        add_filter('the_content', [Modules::class, 'end_content'], PHP_INT_MAX);
        add_action('wp_enqueue_scripts', [Assets::class, 'enqueue_css'], Settings::get('cssPriority'));
        add_action('wp_footer', [Assets::class, 'enqueue_js'], 1);
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('widget_block_content', [Sources::class, 'begin_widget'], -1, 3);
        add_filter('widget_block_content', [Sources::class, 'end_widget'], PHP_INT_MAX);
        Preview::register();
    }

    public static function categories(array $categories): array {
        $categories[] = ['slug' => 'mcs-studio', 'title' => __('Modular Code Studio', 'modular-code-studio')];
        return $categories;
    }

    public static function allowed($allowed, $context) {
        $can = Settings::can_edit_context($context);
        if ($can || $allowed === false) {
            return $allowed;
        }
        $names = $allowed === true ? array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered()) : $allowed;
        return array_values(array_diff($names, [Modules::BLOCK]));
    }

    public static function editor_config(): void {
        $screen = get_current_screen();
        $post = $screen && $screen->base === 'post' ? ($GLOBALS['post'] ?? null) : null;
        $settings = Settings::all();
        $config = array_intersect_key($settings, array_flip([
            'theme', 'height', 'fontSize', 'tabSize', 'wrap', 'minimap', 'indent',
            'closeBrackets', 'format', 'preview', 'previewWidth', 'debounce', 'previewMode', 'shortcodes', 'bundling',
        ]));
        $config['canCode'] = Settings::can_edit_context((object) ['post' => $post]);
        $config['supported'] = $post ? Settings::supports($post->post_type) : true;
        $config['postAssets'] = $post && $config['supported'];
        $config['contentBlock'] = $post && !is_post_type_viewable($post->post_type);
        // Shared templates and widgets need a host page for frontend preview.
        $config['preview'] = $config['preview'] && $post && is_post_type_viewable($post->post_type);
        $config['nonce'] = wp_create_nonce('wp_rest');
        wp_add_inline_script('mcs-code-module-editor-script', 'window.MCS = window.MCS || {}; window.MCS.editor = ' . wp_json_encode($config, JSON_HEX_TAG) . ';', 'before');
        wp_set_script_translations('mcs-code-module-editor-script', 'modular-code-studio', DIR . 'languages');
        if ($config['canCode'] && $config['postAssets']) {
            $asset = require DIR . 'build/asset-sidebar/index.asset.php';
            wp_enqueue_script('mcs-asset-sidebar', plugins_url('build/asset-sidebar/index.js', FILE),
                array_merge($asset['dependencies'], ['mcs-code-module-editor-script']), $asset['version'], true);
        }
    }

    public static function routes(): void {
        $permission = static function (WP_REST_Request $request) {
            $id = (int) $request['id'];
            $post = get_post($id);
            if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest') || !Settings::can_code($id)
                || !$post || !Settings::supports($post->post_type)) {
                return new WP_Error('mcs_forbidden', __('You need permission to edit this post and use unfiltered HTML.', 'modular-code-studio'), ['status' => 403]);
            }
            return true;
        };
        $routes = [
            'status' => ['GET', static fn(WP_REST_Request $r) => Assets::info((int) $r['id'])],
            'preview' => ['POST', [Preview::class, 'create']],
            'rebuild' => ['POST', static function (WP_REST_Request $r) {
                $kind = $r->get_param('kind') ?? 'all';
                if (!Settings::get('bundling') || !in_array($kind, ['all', 'css', 'js'], true)) {
                    return new WP_Error('mcs_rebuild_unavailable', __('Select an enabled bundle type.', 'modular-code-studio'), ['status' => 400]);
                }
                $id = (int) $r['id'];
                $result = Assets::build($id, $kind);
                return is_wp_error($result) ? $result : Assets::info($id);
            }],
            'litespeed' => ['POST', static fn(WP_REST_Request $r) => Integrations::save_page_options((int) $r['id'], (array) $r->get_json_params())],
        ];
        foreach ($routes as $name => [$method, $callback]) {
            register_rest_route('mcs/v1', '/posts/(?P<id>[1-9][0-9]*)/' . $name, [
                'methods' => $method, 'callback' => $callback, 'permission_callback' => $permission,
            ]);
        }
    }
}
