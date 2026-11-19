<?php

namespace MCS\CodeModules;

defined('ABSPATH') || exit;

final class Settings {
    public const OPTION = 'mcs_settings';
    public const PAGE_OPTIONS = '_mcs_litespeed';
    private static ?array $settings = null;

    public static function defaults(): array {
        return [
            'enabled' => true, 'editorScope' => 'all', 'postTypes' => ['page', 'post', Sources::CONTENT_BLOCK_TYPE],
            'shortcodes' => true, 'bundling' => true, 'automatic' => true,
            'theme' => 'dark', 'height' => 320, 'fontSize' => 14, 'tabSize' => 2,
            'wrap' => false, 'minimap' => false, 'indent' => true, 'closeBrackets' => true, 'format' => true,
            'preview' => true, 'previewWidth' => 1440, 'debounce' => 700, 'previewMode' => 'module',
            'previewCss' => '', 'previewJs' => '', 'cssBase' => '', 'cssPriority' => 999,
            'cleanup' => true, 'retention' => 30, 'hash' => 'sha256',
        ];
    }

    public static function all(): array {
        return self::$settings ??= wp_parse_args(get_option(self::OPTION, []), self::defaults());
    }

    public static function get(string $key) {
        return self::all()[$key];
    }

    public static function types(): array {
        return array_filter(get_post_types(['show_in_rest' => true], 'objects'), static fn($type) => post_type_supports($type->name, 'editor'));
    }

    public static function upgrade(): void {
        if (get_option('mcs_settings_version') === '1.1.0') {
            return;
        }
        $settings = get_option(self::OPTION, []);
        if (is_array($settings) && isset($settings['postTypes']) && is_array($settings['postTypes'])) {
            $settings['postTypes'] = array_values(array_unique(array_merge($settings['postTypes'], [Sources::CONTENT_BLOCK_TYPE])));
            update_option(self::OPTION, $settings);
        }
        update_option('mcs_settings_version', '1.1.0', false);
        self::$settings = null;
    }

    public static function supports(string $type): bool {
        if (self::get('editorScope') === 'all') {
            $object = get_post_type_object($type);
            return $object && $object->show_in_rest && post_type_supports($type, 'editor');
        }
        return in_array($type, self::get('postTypes'), true);
    }

    public static function enabled_types(): array {
        return array_values(array_filter(array_keys(self::types()), [self::class, 'supports']));
    }

    public static function can_edit_context($context): bool {
        if (!current_user_can('unfiltered_html')) {
            return false;
        }
        $post = $context->post ?? null;
        return $post
            ? self::can_code((int) $post->ID) && self::supports($post->post_type)
            : self::get('editorScope') === 'all' && current_user_can('edit_theme_options');
    }

    public static function can_code(int $id): bool {
        return $id > 0 && current_user_can('unfiltered_html') && current_user_can('edit_post', $id);
    }

    public static function update(array $input): array {
        $clean = self::defaults();
        foreach ($clean as $key => $default) {
            if (is_bool($default)) {
                $clean[$key] = !empty($input[$key]);
            }
        }
        foreach ([
            'height' => [180, 850], 'fontSize' => [11, 24], 'tabSize' => [1, 8],
            'debounce' => [250, 3000], 'retention' => [1, 365], 'cssPriority' => [1, 9999],
        ] as $key => [$min, $max]) {
            $clean[$key] = max($min, min($max, (int) ($input[$key] ?? $clean[$key])));
        }
        $clean['postTypes'] = array_values(array_intersect(array_keys(self::types()), array_filter((array) ($input['postTypes'] ?? []), 'is_string')));
        foreach (['editorScope' => ['all', 'selected'], 'theme' => ['dark', 'light'], 'previewWidth' => [1440, 768, 390], 'previewMode' => ['module', 'page'], 'hash' => ['sha256', 'sha384']] as $key => $allowed) {
            $value = $key === 'previewWidth' ? (int) ($input[$key] ?? 0) : ($input[$key] ?? '');
            if (in_array($value, $allowed, true)) {
                $clean[$key] = $value;
            }
        }
        $clean['cssBase'] = self::http_url($input['cssBase'] ?? '');
        foreach (['previewCss', 'previewJs'] as $key) {
            $urls = preg_split('/\R/', (string) ($input[$key] ?? ''));
            $clean[$key] = implode("\n", array_slice(array_unique(array_filter(array_map([self::class, 'http_url'], $urls))), 0, 20));
        }
        update_option(self::OPTION, $clean);
        self::$settings = $clean;
        return $clean;
    }

    public static function http_url($value): string {
        $url = is_string($value) ? esc_url_raw(trim($value), ['http', 'https']) : '';
        $parts = wp_parse_url($url);
        return !empty($parts['host']) && !empty($parts['scheme']) && !isset($parts['user']) ? $url : '';
    }
}
