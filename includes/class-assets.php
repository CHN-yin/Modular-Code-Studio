<?php

namespace MCS\CodeModules;

use Throwable;
use WP_Error;
use WP_Post;

defined('ABSPATH') || exit;

final class Assets {
    public const PATTERN_REF = '_mcs_pattern_ref';
    private const ERROR = '_mcs_build_error';
    private static array $missing = [];
    private static array $rendered_sources = [];

    public static function build(int|string $id, string $only = 'all', bool $purge = true) {
        try {
            $result = Storage::locked($id, static function (string $dir) use ($id, $only): array {
                if (is_int($id)) {
                    wp_cache_delete($id, 'posts');
                }
                $source = Sources::load($id);
                $old = Storage::read($id);
                $items = $source['active'] ? Modules::enumerate($source['content']) : ['modules' => [], 'refs' => []];
                $items['modules'] = Sources::prepare($id, $items['modules'], $source['shared']);
                if (is_int($id)) {
                    self::update_refs($id, $items['refs']);
                    Sources::index($id, $items['modules']);
                }
                if (!$items['modules'] && !$items['refs']) {
                    $path = $dir . '/manifest.json';
                    if ((is_file($path) || is_link($path)) && !@unlink($path)) {
                        throw new \RuntimeException('Could not remove the empty page manifest.');
                    }
                    Storage::retire($id, $old, []);
                    self::set_error($id, '');
                    return [];
                }
                $base = Settings::get('cssBase') ?: $source['base'];
                $sources = self::fingerprints($items['modules'], $base);
                $compile = [];
                foreach (['css', 'js'] as $kind) {
                    if ($only === 'all' || $only === $kind || $sources[$kind] !== ($old['sources'][$kind] ?? null)
                        || (!empty($old[$kind]) && !Storage::bundle($id, $kind, $old))) {
                        $compile[] = $kind;
                    }
                }
                $selection = count($compile) === 2 ? 'all' : ($compile[0] ?? 'none');
                $built = Bundler::compile($id, $items['modules'], $base, $selection);
                $next = [
                    'id' => $id, 'sources' => $sources, 'counts' => $built['counts'],
                    'css' => $old['css'] ?? null, 'js' => $old['js'] ?? null,
                    'dependencies' => $old['dependencies'] ?? [], 'selectors' => $old['selectors'] ?? [],
                    'issues' => $old['issues'] ?? ['css' => [], 'js' => []],
                ];
                foreach (['css', 'js'] as $kind) {
                    if ($selection !== 'all' && $selection !== $kind) {
                        continue;
                    }
                    $next[$kind] = Storage::write_bundle($dir, $id, $kind, $built[$kind]);
                    $next['issues'][$kind] = $built['issues'][$kind];
                    $key = $kind === 'css' ? 'selectors' : 'dependencies';
                    $next[$key] = $built[$key];
                }
                $changed = ($old['css'] ?? null) !== $next['css'] || ($old['js'] ?? null) !== $next['js'];
                $next['generated'] = $changed ? time() : ($old['generated'] ?? null);
                if ($next !== $old) {
                    Storage::atomic($dir . '/manifest.json', wp_json_encode($next, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
                    Storage::retire($id, $old, $next);
                }
                self::set_error($id, '');
                return $next;
            });
        } catch (Throwable $error) {
            self::set_error($id, sanitize_text_field($error->getMessage()));
            $result = new WP_Error('mcs_build_failed', $error->getMessage(), ['status' => 500]);
        }
        if ($purge && is_int($id)) {
            Integrations::purge($id);
        }
        return $result;
    }

    private static function update_refs(int $id, array $refs): void {
        $before = array_map('intval', get_post_meta($id, self::PATTERN_REF, false));
        foreach (array_diff($before, $refs) as $ref) {
            delete_post_meta($id, self::PATTERN_REF, $ref);
        }
        foreach (array_diff($refs, $before) as $ref) {
            add_post_meta($id, self::PATTERN_REF, $ref);
        }
    }

    public static function on_save(int $id, WP_Post $post, bool $update, ?WP_Post $before): void {
        if (!Settings::get('enabled') || !Settings::get('automatic') || !Settings::get('bundling')
            || wp_is_post_revision($id) || wp_is_post_autosave($id) || $post->post_status === 'auto-draft') {
            return;
        }
        if ($post->post_type === 'wp_block') {
            self::build($id, 'auto');
            self::refresh_dependents($id);
            return;
        }
        if (Settings::supports($post->post_type) && (
            str_contains($post->post_content, 'wp:mcs/') || str_contains($post->post_content, 'wp:core/block')
            || str_contains($post->post_content, '<!-- wp:block') || Storage::read($id)
        )) {
            self::build($id, 'auto');
        }
    }

    public static function on_delete(int $id, WP_Post $post): void {
        if ($post->post_type === 'wp_block' && Settings::get('automatic') && Settings::get('bundling')) {
            self::build($id, 'all', false);
            self::refresh_dependents($id);
        } elseif (Storage::read($id)) {
            self::build($id, 'all', false);
        }
    }

    private static function refresh_dependents(int $pattern): void {
        $ids = get_posts([
            'post_type' => Settings::enabled_types(), 'post_status' => array_values(get_post_stati()),
            'fields' => 'ids', 'numberposts' => -1, 'no_found_rows' => true,
            'meta_key' => self::PATTERN_REF, 'meta_value' => $pattern,
        ]);
        foreach ($ids as $id) {
            self::build((int) $id, 'auto');
        }
        foreach ((array) get_option('widget_block', []) as $number => $widget) {
            if (is_int($number) && $number > 0 && is_array($widget)
                && in_array($pattern, Modules::enumerate((string) ($widget['content'] ?? ''))['refs'], true)) {
                self::build('widget-' . $number, 'auto', false);
            }
        }
    }

    private static function fingerprints(array $modules, string $base): array {
        $css = [$base, Settings::get('hash'), VERSION];
        $js = [Settings::get('hash'), VERSION];
        foreach ($modules as $module) {
            if ($module['enableCss'] && trim($module['css']) !== '') {
                $css[] = [$module['moduleName'], $module['key'], $module['css']];
            }
            if ($module['enableJs'] && trim($module['js']) !== '') {
                $js[] = array_intersect_key($module, array_flip(['js', 'key', 'moduleName', 'rootSelector', 'wrapper', 'dependencies']));
            }
        }
        return ['css' => hash('sha256', serialize($css)), 'js' => hash('sha256', serialize($js))];
    }

    public static function enqueue_css(): void {
        self::enqueue('css');
    }

    public static function enqueue_js(): void {
        foreach (array_keys(self::$rendered_sources) as $id) {
            $id = ctype_digit((string) $id) ? (int) $id : $id;
            $manifest = Storage::read($id);
            $js = Storage::bundle($id, 'js', $manifest);
            if ($js) {
                wp_enqueue_script('mcs-page-' . $id, $js['url'], self::registered($manifest['dependencies'] ?? []), null, true);
            }
        }
        self::enqueue('js');
    }

    private static function enqueue(string $kind): void {
        $id = get_queried_object_id();
        if (!Settings::get('bundling') || Preview::active() || !is_singular(Settings::enabled_types()) || post_password_required($id)
            || get_post_meta($id, self::ERROR, true)) {
            return;
        }
        $manifest = Storage::read($id);
        $asset = Storage::bundle($id, $kind, $manifest);
        if (!$asset) {
            return;
        }
        if ($kind === 'css') {
            wp_enqueue_style('mcs-page-' . $id, $asset['url'], [], null);
        } else {
            wp_enqueue_script('mcs-page-' . $id, $asset['url'], self::registered($manifest['dependencies'] ?? []), null, true);
        }
    }

    public static function registered(array $handles): array {
        $valid = [];
        foreach ($handles as $handle) {
            if (wp_script_is($handle, 'registered')) {
                $valid[] = $handle;
            } else {
                self::$missing[$handle] = true;
            }
        }
        return $valid;
    }

    public static function missing(): array {
        return array_keys(self::$missing);
    }

    private static function set_error(int|string $id, string $error): void {
        if (is_int($id)) {
            $error === '' ? delete_post_meta($id, self::ERROR) : update_post_meta($id, self::ERROR, $error);
        } else {
            $error === '' ? delete_option('mcs_error_' . $id) : update_option('mcs_error_' . $id, $error, false);
        }
    }

    public static function enqueue_source(int|string $id): string {
        $error = is_int($id) ? get_post_meta($id, self::ERROR, true) : get_option('mcs_error_' . $id, '');
        if (!Settings::get('bundling') || $error) {
            return '';
        }
        if (isset(self::$rendered_sources[$id])) {
            return '';
        }
        self::$rendered_sources[$id] = true;
        $manifest = Storage::read($id);
        $css = Storage::bundle($id, 'css', $manifest);
        $handle = 'mcs-page-' . $id;
        if (!$css) {
            return '';
        }
        wp_enqueue_style($handle, $css['url'], [], null);
        if (!did_action('wp_head') || wp_style_is($handle, 'done')) {
            return '';
        }
        // Template parts and widgets can render after the head. Keep late styles in WordPress's loader.
        ob_start();
        wp_print_styles([$handle]);
        return (string) ob_get_clean();
    }

    public static function info(int $id): array {
        $manifest = Storage::read($id);
        return [
            'counts' => $manifest['counts'] ?? ['all' => 0, 'html' => 0, 'css' => 0, 'js' => 0],
            'css' => Storage::bundle($id, 'css', $manifest),
            'js' => Storage::bundle($id, 'js', $manifest),
            'generated' => empty($manifest['generated']) ? null : gmdate('c', $manifest['generated']),
            'selectors' => $manifest['selectors'] ?? [],
            'dependencies' => $manifest['dependencies'] ?? [],
            'issues' => $manifest['issues'] ?? [],
            'error' => (string) get_post_meta($id, self::ERROR, true),
            'integrations' => Integrations::info($id),
        ];
    }
}
