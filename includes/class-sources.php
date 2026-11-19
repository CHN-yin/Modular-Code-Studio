<?php

namespace MCS\CodeModules;

defined('ABSPATH') || exit;

final class Sources {
    public const CONTENT_BLOCK_TYPE = 'ct_content_block';
    public const MODULE_META = '_mcs_module_keys';
    private static ?array $posts = null;
    private static array $counts = [];
    private static array $widgets = [];

    public static function begin_widget(string $content, array $instance, \WP_Widget_Block $widget): string {
        self::$widgets[] = 'widget-' . (int) $widget->number;
        return $content;
    }

    public static function end_widget(string $content): string {
        array_pop(self::$widgets);
        return $content;
    }

    public static function load(int|string $id): array {
        if (is_string($id) && preg_match('/^widget-([1-9][0-9]*)$/D', $id, $match)) {
            $widget = get_option('widget_block', [])[(int) $match[1]] ?? [];
            return [
                'content' => is_string($widget['content'] ?? null) ? $widget['content'] : '',
                'active' => !empty($widget['content']), 'shared' => true, 'base' => home_url('/'),
            ];
        }
        $post = get_post((int) $id);
        return [
            'content' => $post->post_content ?? '',
            'active' => $post && $post->post_status === 'publish' && $post->post_password === '' && Settings::supports($post->post_type),
            'shared' => $post && !is_post_type_viewable($post->post_type),
            'base' => $post && is_post_type_viewable($post->post_type) ? (get_permalink($post) ?: home_url('/')) : home_url('/'),
        ];
    }

    public static function prepare(int|string $id, array $modules, bool $shared): array {
        if ($shared) {
            foreach ($modules as &$module) {
                $module['key'] = self::key($id, $module['key']);
            }
            unset($module);
        }
        return $modules;
    }

    public static function key(int|string $id, string $key): string {
        return 'source-' . $id . '/' . $key;
    }

    public static function index(int $id, array $modules): void {
        $keys = [];
        foreach ($modules as $module) {
            $keys[Modules::sign($module)['signature']][] = $module['key'];
        }
        $keys ? update_post_meta($id, self::MODULE_META, $keys) : delete_post_meta($id, self::MODULE_META);
        self::$posts = null;
    }

    private static function posts(): array {
        if (self::$posts === null) {
            $types = array_values(array_filter(Settings::enabled_types(), static fn($type) => !is_post_type_viewable($type)));
            self::$posts = $types ? get_posts([
                'post_type' => $types, 'post_status' => 'publish', 'post_password' => '',
                'meta_key' => self::MODULE_META, 'numberposts' => -1,
                'orderby' => 'ID', 'order' => 'ASC', 'update_post_term_cache' => false,
            ]) : [];
        }
        return self::$posts;
    }

    public static function content_source(string $content): int {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || !str_contains($content, 'wp:')) {
            return 0;
        }
        $post = get_post();
        if ($post && $post->post_content === $content) {
            return (int) $post->ID;
        }
        foreach (self::posts() as $source) {
            if ($source->post_content === $content) {
                return (int) $source->ID;
            }
        }
        return 0;
    }

    public static function module(array $attrs, int $hint, ?\WP_Block $block): ?array {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || Preview::active()) {
            return null;
        }
        $signature = $attrs['signature'] ?? '';
        $widget_id = end(self::$widgets);
        if ($widget_id && ($source = self::match_widget($widget_id, $signature))) {
            return $source;
        }
        foreach (array_unique([$hint, (int) ($block->context['postId'] ?? 0), (int) get_the_ID()]) as $id) {
            if ($id && ($source = self::match_post($id, $signature))) {
                return $source;
            }
        }
        foreach (self::posts() as $post) {
            if ($source = self::match_post((int) $post->ID, $signature)) {
                return $source;
            }
        }
        foreach ((array) get_option('widget_block', []) as $number => $widget) {
            if (!is_int($number) || $number <= 0 || !is_array($widget)) {
                continue;
            }
            $id = 'widget-' . $number;
            if ($source = self::match_widget($id, $signature)) {
                return $source;
            }
        }
        return null;
    }

    private static function match_widget(string $id, string $signature): ?array {
        $source = self::load($id);
        foreach (self::prepare($id, Modules::enumerate($source['content'])['modules'], true) as $module) {
            if (hash_equals(Modules::sign($module)['signature'], $signature)) {
                return ['id' => $id, 'key' => $module['key']];
            }
        }
        return null;
    }

    private static function match_post(int $id, string $signature): ?array {
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish' || $post->post_password !== '' || !Settings::supports($post->post_type)) {
            return null;
        }
        $index = (array) get_post_meta($id, self::MODULE_META, true);
        $keys = $index[$signature] ?? [];
        if (!$keys) {
            return null;
        }
        $offset = self::$counts[$id][$signature] ?? 0;
        self::$counts[$id][$signature] = $offset + 1;
        return ['id' => $id, 'key' => $keys[$offset % count($keys)]];
    }

    public static function sign_widgets($widgets, $old) {
        if (!is_array($widgets)) {
            return $old;
        }
        $allowed = current_user_can('unfiltered_html') && current_user_can('edit_theme_options');
        foreach ($widgets as $number => &$widget) {
            if (!is_array($widget) || !is_string($widget['content'] ?? null)) {
                continue;
            }
            $blocks = parse_blocks($widget['content']);
            Modules::visit($blocks, static function (array &$block) use ($allowed, $number): void {
                if ($allowed) {
                    $block['attrs'] = Modules::sign(array_replace($block['attrs'] ?? [], ['sourceId' => 'widget-' . $number]));
                } elseif (!Modules::trusted($block['attrs'] ?? [])) {
                    $block['attrs'] = [];
                }
            });
            $widget['content'] = serialize_blocks($blocks);
        }
        unset($widget);
        return $widgets;
    }

    public static function save_widgets($old, $widgets): void {
        if (!Settings::get('enabled') || !Settings::get('automatic') || !Settings::get('bundling')) {
            return;
        }
        foreach (array_unique(array_merge(array_keys((array) $old), array_keys((array) $widgets))) as $number) {
            if (is_int($number) && $number > 0 && ($old[$number] ?? null) !== ($widgets[$number] ?? null)) {
                Assets::build('widget-' . $number, 'auto', false);
            }
        }
    }
}
