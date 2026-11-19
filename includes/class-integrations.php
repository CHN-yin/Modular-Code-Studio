<?php

namespace MCS\CodeModules;

defined('ABSPATH') || exit;

final class Integrations {
    private const PURGE = '_mcs_litespeed_purge_requested';
    private static ?array $extensions = null;

    public static function register(): void {
        add_filter('litespeed_optimize_js_excludes', [self::class, 'exclude_optimization']);
        add_filter('litespeed_optm_js_defer_exc', [self::class, 'exclude_deferred']);
        add_filter('litespeed_optm_gm_js_exc', [self::class, 'exclude_guest']);
    }

    public static function page_options(int $id): array {
        return wp_parse_args(get_post_meta($id, Settings::PAGE_OPTIONS, true), [
            'optimize' => true, 'defer' => false, 'delay' => false, 'guest' => false,
        ]);
    }

    public static function save_page_options(int $id, array $input): array {
        $options = [];
        foreach (array_keys(self::page_options($id)) as $key) {
            $options[$key] = !empty($input[$key]);
        }
        update_post_meta($id, Settings::PAGE_OPTIONS, $options);
        self::purge($id);
        return $options;
    }

    private static function current_page(): int {
        return Settings::get('enabled') && is_singular(Settings::enabled_types()) ? get_queried_object_id() : 0;
    }

    private static function exclude(array $list, int $id): array {
        $js = Storage::bundle($id, 'js', Storage::read($id));
        if ($js) {
            $list[] = '/' . Storage::FOLDER . '/page-' . $id . '/' . $js['file'];
        }
        return array_values(array_unique($list));
    }

    public static function exclude_optimization(array $list): array {
        $id = self::current_page();
        return $id && !self::page_options($id)['optimize'] ? self::exclude($list, $id) : $list;
    }

    public static function exclude_deferred(array $list): array {
        $id = self::current_page();
        $options = $id ? self::page_options($id) : [];
        // LiteSpeed uses the same exclusion list for both regular Deferred and Delayed modes.
        return $id && (!$options['optimize'] || $options['defer'] || $options['delay']) ? self::exclude($list, $id) : $list;
    }

    public static function exclude_guest(array $list): array {
        $id = self::current_page();
        $options = $id ? self::page_options($id) : [];
        return $id && (!$options['optimize'] || $options['guest']) ? self::exclude($list, $id) : $list;
    }

    public static function purge(int $id): void {
        $url = get_permalink($id);
        if ($url && has_action('litespeed_purge_url') !== false) {
            do_action('litespeed_purge_url', $url);
            update_post_meta($id, self::PURGE, time());
        }
    }

    public static function bypass(): void {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        add_filter('litespeed_can_optm', '__return_false', PHP_INT_MAX);
        do_action('litespeed_control_set_nocache', 'Modular Code Studio authenticated preview');
    }

    public static function info(int $id = 0): array {
        if (self::$extensions === null) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            self::$extensions = [
                'wpcodebox' => ['installed' => false, 'active' => false, 'version' => null],
                'litespeed' => ['installed' => false, 'active' => false, 'version' => null],
            ];
            foreach (get_plugins() as $file => $plugin) {
                $name = strtolower(trim($plugin['Name']));
                $key = in_array($name, ['wpcodebox 2', 'wpcodebox2'], true) ? 'wpcodebox' : ($name === 'litespeed cache' ? 'litespeed' : '');
                if ($key) {
                    self::$extensions[$key] = ['installed' => true, 'active' => is_plugin_active($file), 'version' => $plugin['Version']];
                }
            }
        }
        $info = self::$extensions;
        $info['wpcodebox']['functionalityPlugin'] = null;
        $info['litespeed']['purgeHook'] = has_action('litespeed_purge_url') !== false;
        $info['litespeed']['cacheStatus'] = $info['litespeed']['active'] ? null : 'inactive';
        $last = $id ? get_post_meta($id, self::PURGE, true) : 0;
        $info['litespeed']['lastPurgeRequest'] = $last ? gmdate('c', (int) $last) : null;
        $info['litespeed']['options'] = $id ? self::page_options($id) : [];
        return $info;
    }
}
