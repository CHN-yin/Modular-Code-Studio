<?php

namespace MCS\CodeModules;

defined('ABSPATH') || exit;

final class Modules {
    public const BLOCK = 'mcs/code-module';
    public const MAX_SOURCE = 262144;
    private static ?array $defaults = null;
    private static array $render_counts = [];
    private static int $content_depth = 0;
    private static int $content_source = 0;
    private static array $content_stack = [];

    public static function normalize(array $attrs): array {
        if (self::$defaults === null) {
            $meta = json_decode(file_get_contents(DIR . 'build/code-module/block.json'), true, 32, JSON_THROW_ON_ERROR);
            self::$defaults = array_map(static fn(array $entry) => $entry['default'], $meta['attributes']);
            unset(self::$defaults['signature']);
        }
        $attrs = array_intersect_key($attrs, self::$defaults);
        foreach ($attrs as $key => $value) {
            if (gettype($value) !== gettype(self::$defaults[$key])) {
                unset($attrs[$key]);
            }
        }
        $normalized = $attrs + self::$defaults;
        $normalized['instanceId'] = preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $normalized['instanceId']) ? $normalized['instanceId'] : '';
        ksort($normalized);
        return $normalized;
    }

    public static function sign(array $attrs): array {
        $attrs = self::normalize($attrs);
        $payload = $attrs;
        // Keep signatures from 1.0.x valid until the source is saved again.
        if ($payload['sourceId'] === '') {
            unset($payload['sourceId']);
        }
        $attrs['signature'] = hash_hmac('sha256', serialize([get_current_blog_id(), $payload]), wp_salt('auth'));
        return $attrs;
    }

    public static function trusted(array $attrs): bool {
        return is_string($attrs['signature'] ?? null) && hash_equals(self::sign($attrs)['signature'], $attrs['signature']);
    }

    public static function on_save(array $data, array $postarr, array $original): array {
        $content = wp_unslash($data['post_content'] ?? '');
        if (!str_contains($content, 'wp:' . self::BLOCK)) {
            return $data;
        }
        $id = (int) ($postarr['ID'] ?? 0);
        $post_type = $data['post_type'] ?? '';
        $type = get_post_type_object($post_type);
        $allowed = current_user_can('unfiltered_html') && ($id
            ? current_user_can('edit_post', $id)
            : ($type && current_user_can($type->cap->create_posts)));
        // Use the original MCS attributes for restricted saves because core KSES may alter signed source.
        $raw = wp_unslash($original['post_content'] ?? '');
        $original_modules = [];
        if (!$allowed && str_contains($raw, 'wp:' . self::BLOCK)) {
            $raw_blocks = parse_blocks($raw);
            self::visit($raw_blocks, static function (array &$block) use (&$original_modules): void {
                $original_modules[] = $block['attrs'] ?? [];
            });
        }
        $blocks = parse_blocks($content);
        $index = 0;
        $source_id = $id ? 'post-' . $id : 'new-' . wp_generate_uuid4();
        self::visit($blocks, static function (array &$block) use ($allowed, &$index, $original_modules, $source_id): void {
            $attrs = $block['attrs'] ?? [];
            if ($allowed) {
                $block['attrs'] = self::sign(array_replace($attrs, ['sourceId' => $source_id]));
                return;
            }
            $candidate = $original_modules[$index++] ?? [];
            if (self::trusted($candidate) && ($candidate['signature'] ?? '') === ($attrs['signature'] ?? null)) {
                $block['attrs'] = $candidate;
            } elseif (!self::trusted($attrs)) {
                $block['attrs'] = [];
            }
        });
        $data['post_content'] = wp_slash(serialize_blocks($blocks));
        return $data;
    }

    public static function visit(array &$blocks, callable $callback): void {
        foreach ($blocks as &$block) {
            if ($block['blockName'] === self::BLOCK) {
                $callback($block);
            }
            if (!empty($block['innerBlocks'])) {
                self::visit($block['innerBlocks'], $callback);
            }
        }
    }

    public static function enumerate(string $content): array {
        $modules = [];
        $refs = [];
        $counts = [];
        self::collect(parse_blocks($content), $modules, $refs, $counts, []);
        return ['modules' => $modules, 'refs' => array_keys($refs)];
    }

    private static function collect(array $blocks, array &$modules, array &$refs, array &$counts, array $ancestors): void {
        foreach ($blocks as $block) {
            $attrs = $block['attrs'] ?? [];
            if ($block['blockName'] === self::BLOCK && self::trusted($attrs)) {
                $attrs = self::normalize($attrs);
                $attrs['key'] = self::key($attrs, $counts);
                $modules[] = $attrs;
            } elseif ($block['blockName'] === 'core/block' && !empty($attrs['ref'])) {
                $ref = absint($attrs['ref']);
                $refs[$ref] = true;
                $pattern = get_post($ref);
                if (!isset($ancestors[$ref]) && count($ancestors) < 32 && $pattern && $pattern->post_type === 'wp_block'
                    && $pattern->post_status === 'publish' && $pattern->post_password === '') {
                    self::collect(parse_blocks($pattern->post_content), $modules, $refs, $counts, $ancestors + [$ref => true]);
                }
            }
            self::collect($block['innerBlocks'] ?? [], $modules, $refs, $counts, $ancestors);
        }
    }

    private static function key(array $attrs, array &$counts): string {
        $base = $attrs['instanceId'] ?: 'legacy';
        $counts[$base] = ($counts[$base] ?? 0) + 1;
        return $base . ':' . $counts[$base];
    }

    public static function begin_content(string $content): string {
        self::$content_stack[] = [self::$render_counts, self::$content_source];
        $source = Sources::content_source($content);
        self::$content_source = $source;
        if (self::$content_depth++ === 0) {
            self::$render_counts = [];
        }
        if ($source) {
            self::$render_counts = [];
        }
        return $content;
    }

    public static function end_content(string $content): string {
        self::$content_depth = max(0, self::$content_depth - 1);
        [self::$render_counts, self::$content_source] = array_pop(self::$content_stack) ?? [[], 0];
        return $content;
    }

    public static function render(array $attrs, string $content = '', ?\WP_Block $block = null): string {
        if (!Settings::get('enabled') || !self::trusted($attrs)) {
            return '';
        }
        $source = Sources::module($attrs, self::$content_source, $block);
        $assets = $source ? Assets::enqueue_source($source['id']) : '';
        $attrs = self::normalize($attrs);
        $key = $source['key'] ?? self::key($attrs, self::$render_counts);
        $html = $attrs['enableHtml'] ? $attrs['html'] : '';
        if ($attrs['enableShortcode'] && Settings::get('shortcodes')) {
            $html = do_shortcode($html);
        } else {
            // Core runs do_shortcode later in the_content. Protect registered shortcode syntax through that pass.
            $html = self::protect_shortcodes($html);
        }
        $key_attr = esc_attr($key);
        if ($attrs['wrapper']) {
            return $assets . '<div class="mcs-module" data-mcs-instance="' . $key_attr . '">' . $html . '</div>';
        }
        if (($attrs['enableJs'] && $attrs['rootSelector'] !== '') || Preview::is_target($attrs)) {
            return $assets . '<template data-mcs-start="' . $key_attr . '"></template>' . $html
                . '<template data-mcs-end="' . $key_attr . '"></template>';
        }
        return $assets . $html;
    }

    private static function protect_shortcodes(string $html): string {
        if (!str_contains($html, '[')) {
            return $html;
        }
        global $shortcode_tags;
        $tags = array_keys((array) $shortcode_tags);
        if (!$tags) {
            return $html;
        }
        $names = implode('|', array_map(static fn($tag) => preg_quote($tag, '~'), $tags));
        return preg_replace('~\[(?=/?(?:' . $names . ')(?![\w-]))~', '&#91;', $html) ?? $html;
    }
}
