<?php

namespace MCS\CodeModules;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class Preview {
    private const TTL = 120;
    private static ?array $session = null;
    private static ?string $content = null;
    private static ?array $bundles = null;
    private static array $printed = ['css' => [], 'js' => []];

    public static function register(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mcs_preview'])) {
            Integrations::bypass();
            add_action('template_redirect', [self::class, 'enter'], -100);
        }
    }

    private static function origin(string $url): string {
        $parts = wp_parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme && $host ? $scheme . '://' . $host . ':' . $port : '';
    }

    public static function create(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $post = get_post($id);
        if (!Settings::get('preview') || !$post || !is_post_type_viewable($post->post_type)) {
            return new WP_Error('mcs_preview_unavailable', __('Live preview is unavailable for this post.', 'modular-code-studio'), ['status' => 400]);
        }
        $public = in_array($post->post_status, ['publish', 'private'], true);
        $url = $public ? get_permalink($id) : get_preview_post_link($id);
        if (!$url || self::origin(rest_url()) !== self::origin($url) || self::origin(admin_url()) !== self::origin($url)) {
            return new WP_Error('mcs_preview_origin', __('Live preview requires the editor, REST API and frontend to share an origin.', 'modular-code-studio'), ['status' => 400]);
        }
        $input = $request->get_json_params();
        $attrs = null;
        if (isset($input['module']) && is_array($input['module'])) {
            $attrs = Modules::normalize($input['module']);
            if (!$attrs['instanceId'] || !$attrs['enablePreview']) {
                return new WP_Error('mcs_preview_module', __('This module needs an instance ID and live preview enabled.', 'modular-code-studio'), ['status' => 400]);
            }
            foreach (['html', 'css', 'js'] as $kind) {
                if (strlen($attrs[$kind]) > Modules::MAX_SOURCE) {
                    return new WP_Error('mcs_preview_size', __('Each preview buffer must be 256 KiB or smaller.', 'modular-code-studio'), ['status' => 413]);
                }
            }
            $attrs = Modules::sign($attrs);
        }
        $channel = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $session = [
            'channel' => $channel, 'proof' => hash('sha256', $secret),
            'user' => get_current_user_id(), 'login' => hash('sha256', wp_get_session_token()),
            'page' => $id, 'url' => $url,
            'expires' => time() + self::TTL, 'module' => $attrs,
            'mode' => ($input['mode'] ?? '') === 'module' && $attrs ? 'module' : 'page',
        ];
        $options = self::cookie_options($session['expires']);
        if (!set_transient('mcs_preview_' . $channel, $session, self::TTL) || !setcookie('mcs_preview_' . $channel, $secret, $options)) {
            delete_transient('mcs_preview_' . $channel);
            return new WP_Error('mcs_preview_storage', __('Could not create a preview session.', 'modular-code-studio'), ['status' => 500]);
        }
        $response = new WP_REST_Response(['url' => $url, 'channel' => $channel], 201);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }

    private static function cookie_options(int $expires): array {
        return ['expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict'];
    }

    public static function enter(): void {
        Integrations::bypass();
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0, must-revalidate', true);
        header('Referrer-Policy: no-referrer', true);
        header('Content-Security-Policy: frame-ancestors \'self\'; sandbox allow-scripts; object-src \'none\'; form-action \'none\'', false);
        $channel = is_string($_POST['mcs_preview'] ?? null) ? $_POST['mcs_preview'] : '';
        if (!preg_match('/^[0-9a-f]{32}$/D', $channel)) {
            self::deny();
        }
        $name = 'mcs_preview_' . $channel;
        $session = get_transient($name);
        $secret = is_string($_COOKIE[$name] ?? null) ? $_COOKIE[$name] : '';
        $id = get_queried_object_id();
        $path = wp_parse_url($session['url'] ?? '', PHP_URL_PATH) ?: '/';
        $query = wp_parse_url($session['url'] ?? '', PHP_URL_QUERY);
        $target = $path . ($query !== null ? '?' . $query : '');
        if (!$session || $session['expires'] <= time() || !is_singular() || $session['page'] !== $id
            || $session['user'] !== get_current_user_id() || !Settings::can_code($id)
            || !hash_equals($session['proof'], hash('sha256', $secret))
            || !hash_equals($session['login'], hash('sha256', wp_get_session_token()))
            || !hash_equals($target, $_SERVER['REQUEST_URI'] ?? '')
            || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] !== 'iframe')) {
            self::deny();
        }
        if (!delete_transient($name)) {
            self::deny();
        }
        setcookie($name, '', self::cookie_options(time() - 3600));
        self::$session = $session;
        $post = get_queried_object();
        self::$content = self::prepare($post->post_content, $session['module']);
        $base = Settings::get('cssBase') ?: $session['url'];
        self::$bundles = Bundler::compile($id, Modules::enumerate(self::$content)['modules'], $base);
        remove_action('template_redirect', 'wp_old_slug_redirect');
        add_filter('the_content', [self::class, 'replace_content'], 7);
        add_filter('style_loader_src', [self::class, 'log_style'], PHP_INT_MAX, 2);
        add_filter('script_loader_src', [self::class, 'log_script'], PHP_INT_MAX, 2);
        add_action('wp_head', [self::class, 'bootstrap'], 0);
        add_action('wp_enqueue_scripts', [self::class, 'extras'], 9999);
        add_action('wp_head', [self::class, 'print_css'], PHP_INT_MAX);
        add_action('wp_footer', [self::class, 'dependencies'], 1);
        add_action('wp_footer', [self::class, 'finish'], PHP_INT_MAX);
        show_admin_bar(false);
    }

    private static function deny(): void {
        wp_die(esc_html__('The preview session is invalid or has expired. Refresh it in the editor.', 'modular-code-studio'), '', ['response' => 403]);
    }

    private static function prepare(string $content, ?array $attrs): string {
        if (!$attrs) {
            return $content;
        }
        $blocks = parse_blocks($content);
        $found = false;
        Modules::visit($blocks, static function (array &$block) use ($attrs, &$found): void {
            if (!$found && ($block['attrs']['instanceId'] ?? '') === $attrs['instanceId']) {
                $block['attrs'] = $attrs;
                $found = true;
            }
        });
        if (!$found) {
            $blocks[] = ['blockName' => Modules::BLOCK, 'attrs' => $attrs, 'innerBlocks' => [], 'innerContent' => []];
        }
        return serialize_blocks($blocks);
    }

    public static function replace_content(string $content): string {
        return is_main_query() && in_the_loop() && get_the_ID() === self::$session['page'] ? self::$content : $content;
    }

    public static function active(): bool {
        return self::$session !== null;
    }

    public static function is_target(array $attrs): bool {
        return isset(self::$session['module']['instanceId']) && self::$session['module']['instanceId'] === $attrs['instanceId'];
    }

    public static function log_style(string $src, string $handle): string {
        self::$printed['css'][$handle] = $src;
        return $src;
    }

    public static function log_script(string $src, string $handle): string {
        self::$printed['js'][$handle] = $src;
        return $src;
    }

    public static function extras(): void {
        foreach (['css' => 'previewCss', 'js' => 'previewJs'] as $kind => $setting) {
            foreach (array_filter(explode("\n", Settings::get($setting))) as $index => $url) {
                $handle = 'mcs-preview-' . $kind . '-' . $index;
                if ($kind === 'css') {
                    wp_enqueue_style($handle, $url, [], null);
                } else {
                    wp_enqueue_script($handle, $url, [], null, true);
                }
            }
        }
    }

    public static function bootstrap(): void {
        wp_print_inline_script_tag(file_get_contents(DIR . 'runtime/preview.js'));
    }

    public static function dependencies(): void {
        foreach (Assets::registered(self::$bundles['dependencies']) as $handle) {
            wp_enqueue_script($handle);
        }
    }

    public static function print_css(): void {
        if (self::$bundles['css'] !== '') {
            $css = str_ireplace('</style', '\\3C /style', self::$bundles['css']);
            echo "\n<style id=\"mcs-preview-css\">\n" . $css . "\n</style>\n"; // Authorized module CSS, prepared by the CSS tokenizer.
        }
    }

    public static function finish(): void {
        $data = [
            'channel' => self::$session['channel'], 'mode' => self::$session['mode'],
            'target' => self::$session['module']['instanceId'] ?? '',
            'handles' => self::$printed, 'issues' => self::$bundles['issues'], 'missing' => Assets::missing(),
        ];
        $args = wp_json_encode([$data, self::$bundles['js']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
        wp_print_inline_script_tag('window.MCSPreview?.finish(...' . $args . ');');
    }
}
