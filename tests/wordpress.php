<?php

use MCS\CodeModules\Assets;
use MCS\CodeModules\Storage;

$bootstrap = getenv('MCS_WP_LOAD');
if (!$bootstrap || !is_file($bootstrap)) {
    throw new RuntimeException('Set MCS_WP_LOAD to the disposable WordPress wp-load.php.');
}
require $bootstrap;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!in_array(wp_parse_url(home_url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1'], true)
    || !shortcode_exists('mcs_acceptance') || !class_exists(Assets::class)) {
    throw new RuntimeException('The local fixture and the packaged plugin must be active.');
}
wp_set_current_user((int) get_user_by('login', 'admin')->ID);

function mcs_test_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mcs_test_block(array $attrs): string {
    return get_comment_delimited_block_content('mcs/code-module', $attrs, '');
}

$attrs = [
    'instanceId' => 'pattern-instance',
    'html' => '<div class="mcs-fixture-root">[mcs_acceptance]</div>',
    'css' => '.mcs-fixture-root{color:red}',
    'js' => 'root.dataset.initialized = instanceId;',
    'rootSelector' => '.mcs-fixture-root',
    'enableShortcode' => false,
];
$pattern = wp_insert_post([
    'post_type' => 'wp_block', 'post_title' => 'MCS test shared pattern',
    'post_status' => 'publish', 'post_content' => wp_slash(mcs_test_block($attrs)),
], true);
mcs_test_assert(is_int($pattern) && $pattern > 0, 'Could not create the synced pattern.');
$reference = get_comment_delimited_block_content('core/block', ['ref' => $pattern], '');
$id = wp_insert_post([
    'post_type' => 'page', 'post_title' => 'MCS test repeated patterns',
    'post_status' => 'draft', 'post_content' => wp_slash($reference . $reference),
], true);
mcs_test_assert(is_int($id) && $id > 0, 'Could not create the draft page.');

try {
    mcs_test_assert(!Storage::read($id), 'A draft produced a public bundle manifest.');
    wp_update_post(['ID' => $id, 'post_status' => 'publish']);
    $first = Assets::info($id);
    mcs_test_assert($first['counts']['all'] === 2, 'Repeated synced pattern placements were lost.');
    mcs_test_assert($first['css'] && $first['js'], 'Published pattern bundles were absent.');
    $GLOBALS['post'] = get_post($id);
    $html = do_blocks(get_post($id)->post_content);
    mcs_test_assert(str_contains($html, 'pattern-instance:1') && str_contains($html, 'pattern-instance:2'), 'Distinct DOM markers were absent.');
    mcs_test_assert(!str_contains(do_shortcode($html), 'mcs-shortcode') && str_contains($html, '&#91;mcs_acceptance]'), 'The disabled shortcode was executed.');
    $js_before = $first['js']['hash'];
    $attrs['css'] = '.mcs-fixture-root{color:blue}';
    wp_update_post(['ID' => $pattern, 'post_content' => wp_slash(mcs_test_block($attrs))]);
    $updated = Assets::info($id);
    mcs_test_assert($updated['css']['hash'] !== $first['css']['hash'], 'Pattern edit did not refresh the dependent page.');
    mcs_test_assert($updated['js']['hash'] === $js_before, 'Pattern CSS changed the JavaScript hash.');
    $dir = Storage::base()['dir'] . '/page-' . $id;
    $old_file = $dir . '/' . $first['css']['file'];
    $active_file = $dir . '/' . $updated['css']['file'];
    $sentinel = $dir . '/unrelated.txt';
    file_put_contents($sentinel, 'local fixture');
    touch($old_file, time() - 45 * DAY_IN_SECONDS);
    touch($active_file, time() - 45 * DAY_IN_SECONDS);
    touch($sentinel, time() - 45 * DAY_IN_SECONDS);
    Storage::cleanup();
    mcs_test_assert(!is_file($old_file), 'Expired retired file survived cleanup.');
    mcs_test_assert(is_file($active_file) && is_file($sentinel), 'Cleanup removed active or unrelated files.');
    unlink($sentinel);
    $malformed = ['css' => ['file' => '../../wp-config.php', 'hash' => 'x', 'bytes' => 1]];
    mcs_test_assert(Storage::bundle($id, 'css', $malformed) === null, 'Unexpected filename could escape the asset directory.');
    wp_update_post(['ID' => $id, 'post_content' => '']);
    mcs_test_assert(!Storage::read($id), 'A manifest survived removal of the last module.');
    echo "WordPress " . get_bloginfo('version') . ', PHP ' . PHP_VERSION . PHP_EOL;
    echo "Draft suppression, synced-pattern duplicates and invalidation, disabled shortcode, cleanup scope, pathname validation and empty manifest passed.\n";
} finally {
    wp_delete_post($id, true);
    wp_delete_post($pattern, true);
}
