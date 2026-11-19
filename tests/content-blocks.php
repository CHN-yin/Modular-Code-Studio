<?php

use MCS\CodeModules\Assets;
use MCS\CodeModules\Sources;
use MCS\CodeModules\Modules;
use MCS\CodeModules\Plugin;
use MCS\CodeModules\Settings;
use MCS\CodeModules\Storage;

require getenv('MCS_WP_LOAD');
if (!in_array(wp_parse_url(home_url('/'), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)
    || !post_type_exists(Sources::CONTENT_BLOCK_TYPE) || !class_exists(Assets::class)) {
    throw new RuntimeException('Use the disposable local WordPress site with the Content Blocks fixture.');
}
wp_set_current_user((int) get_user_by('login', 'admin')->ID);

function mcs_cb_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$settings_before = get_option(Settings::OPTION);
$version_before = get_option('mcs_settings_version');
$ids = [];
try {
    update_option(Settings::OPTION, ['postTypes' => ['page', 'post']]);
    delete_option('mcs_settings_version');
    Settings::upgrade();
    mcs_cb_assert(Settings::supports('ct_content_block'), 'Existing installation did not enable Content Blocks.');
    mcs_cb_assert(isset(Settings::types()['ct_content_block']), 'Settings omit the non-public content type.');
    mcs_cb_assert(Settings::supports('mcs_internal_test'), 'Another custom block editor still requires a manual allowlist.');
    mcs_cb_assert(Settings::supports('wp_template') && Settings::supports('wp_template_part'), 'Site template types are disabled.');
    mcs_cb_assert(Plugin::allowed(true, (object) ['name' => 'core/edit-site']) === true, 'Site editor context hides the module.');
    mcs_cb_assert(Plugin::allowed(true, (object) ['name' => 'core/edit-widgets']) === true, 'Widget editor context hides the module.');
    $attrs = [
        'instanceId' => 'footer-test', 'moduleName' => 'Footer code',
        'html' => '<div class="mcs-footer-regression">Footer [mcs_acceptance]</div>',
        'css' => '.mcs-footer-regression { color: rgb(21, 63, 84); }',
        'js' => 'root.dataset.ready = "yes"; window.mcsFooterRuns = (window.mcsFooterRuns || 0) + 1;',
        'rootSelector' => '.mcs-footer-regression',
    ];
    $legacy = Modules::normalize($attrs);
    unset($legacy['sourceId']);
    $legacy['signature'] = hash_hmac('sha256', serialize([get_current_blog_id(), $legacy]), wp_salt('auth'));
    mcs_cb_assert(Modules::trusted($legacy), 'A 1.0.x module signature is no longer trusted.');
    $content = get_comment_delimited_block_content(Modules::BLOCK, $attrs, '');
    $id = wp_insert_post(['post_type' => 'ct_content_block', 'post_title' => 'MCS Footer regression', 'post_status' => 'publish', 'post_content' => wp_slash($content)], true);
    mcs_cb_assert(is_int($id) && $id > 0, 'Content Block save failed.');
    $ids[] = $id;
    $post = get_post($id);
    mcs_cb_assert(!is_post_type_viewable($post->post_type), 'Fixture must exercise a non-viewable post type.');
    mcs_cb_assert(Plugin::allowed(true, (object) ['post' => $post]) === true, 'Inserter still hides the module.');
    mcs_cb_assert(Plugin::allowed(['core/html'], (object) ['post' => $post]) === ['core/html'], 'Changed another plugin block allowlist.');
    mcs_cb_assert(Plugin::allowed(false, (object) ['post' => $post]) === false, 'Changed another plugin block denial.');
    $original = Assets::info($id);
    mcs_cb_assert($original['css'] && $original['js'] && $original['counts']['all'] === 1, 'Published Content Block has no asset bundle.');
    $js_path = Storage::base()['dir'] . '/page-' . $id . '/' . Storage::read($id)['js']['file'];
    mcs_cb_assert(str_contains(str_replace('\\/', '/', file_get_contents($js_path)), 'source-' . $id . '/footer-test:1'), 'Bundle instance key lacks its Content Block ID.');
    $saved_content = $post->post_content;
    $GLOBALS['post'] = get_post(1);
    $rendered = apply_filters('the_content', $saved_content);
    mcs_cb_assert(str_contains($rendered, 'source-' . $id . '/footer-test:1'), 'Content Block failed to render in the host post context: ' . $rendered);
    Assets::enqueue_js();
    mcs_cb_assert(wp_style_is('mcs-page-' . $id, 'enqueued'), 'Content Block CSS was not enqueued on rendering.');
    mcs_cb_assert(wp_script_is('mcs-page-' . $id, 'enqueued'), 'Content Block JavaScript was not enqueued on rendering.');
    $attrs['css'] = '.mcs-footer-regression { color: blue; }';
    wp_update_post(['ID' => $id, 'post_content' => wp_slash(get_comment_delimited_block_content(Modules::BLOCK, $attrs, ''))]);
    $updated = Assets::info($id);
    mcs_cb_assert($updated['css']['hash'] !== $original['css']['hash'], 'Content Block CSS save did not update the bundle.');
    mcs_cb_assert($updated['js']['hash'] === $original['js']['hash'], 'Content Block CSS changed the JavaScript hash.');
    wp_update_post(['ID' => $id, 'post_status' => 'draft']);
    mcs_cb_assert(!Storage::read($id), 'Draft Content Block left a current public bundle manifest.');
    $author = get_user_by('login', 'mcs_fixture_author');
    wp_set_current_user((int) $author->ID);
    mcs_cb_assert(!in_array(Modules::BLOCK, Plugin::allowed(true, (object) ['post' => get_post($id)]), true), 'Restricted user gained code access.');
    mcs_cb_assert(!in_array(Modules::BLOCK, Plugin::allowed(true, (object) ['name' => 'core/edit-site']), true), 'Restricted user gained template code access.');
    wp_set_current_user((int) get_user_by('login', 'admin')->ID);
    $template = wp_insert_post(['post_type' => 'wp_template_part', 'post_name' => 'mcs-compatibility-template', 'post_status' => 'publish',
        'post_content' => wp_slash(get_comment_delimited_block_content(Modules::BLOCK, $attrs, ''))], true);
    mcs_cb_assert(is_int($template) && $template > 0, 'Could not save a template part.');
    $ids[] = $template;
    mcs_cb_assert(Storage::read($template)['counts']['all'] === 1, 'Template part assets were not built.');
    $template_html = do_blocks(get_post($template)->post_content);
    mcs_cb_assert(str_contains($template_html, 'source-' . $template . '/footer-test:1'), 'Direct template rendering lost its source identity.');
    $widgets_before = get_option('widget_block', []);
    try {
        $widgets = $widgets_before;
        $widget_attrs = array_replace($attrs, ['instanceId' => 'widget-footer-test']);
        $widgets[98765] = ['content' => get_comment_delimited_block_content(Modules::BLOCK, $widget_attrs, '')];
        update_option('widget_block', $widgets);
        mcs_cb_assert(Storage::read('widget-98765')['counts']['all'] === 1, 'Widget save failed to create an external bundle.');
        $widget_html = do_blocks(get_option('widget_block')[98765]['content']);
        mcs_cb_assert(str_contains($widget_html, 'source-widget-98765/widget-footer-test:1'), 'Widget rendering lost its source identity.');
        wp_set_current_user((int) $author->ID);
        $unsafe = Sources::sign_widgets([98766 => ['content' => get_comment_delimited_block_content(Modules::BLOCK, ['html' => '<script>bad()</script>'], '')]], []);
        mcs_cb_assert(!str_contains(do_blocks($unsafe[98766]['content']), 'bad()'), 'Restricted widget source was executable.');
    } finally {
        wp_set_current_user((int) get_user_by('login', 'admin')->ID);
        update_option('widget_block', $widgets_before);
    }
    mcs_cb_assert(!Storage::read('widget-98765'), 'Removed widget kept a current bundle manifest.');
    echo "Content Blocks settings migration, inserter, permissions, host-context rendering, asset queues, hashes and draft removal passed.\n";
    echo "All custom editor types, site/widget editor contexts, template-part assets, signed widget assets and restricted widget saves passed.\n";
} finally {
    wp_set_current_user((int) get_user_by('login', 'admin')->ID);
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
    $settings_before === false ? delete_option(Settings::OPTION) : update_option(Settings::OPTION, $settings_before);
    $version_before === false ? delete_option('mcs_settings_version') : update_option('mcs_settings_version', $version_before);
}
