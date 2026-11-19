<?php
/**
 * Plugin Name: MCS Content Blocks regression fixture
 * Description: Registers a non-public Gutenberg CPT and renders its saved content on a selected local page.
 */

defined('ABSPATH') || exit;

add_action('init', static function (): void {
    register_post_type('mcs_internal_test', [
        'label' => 'Internal editor test', 'public' => false, 'show_ui' => true,
        'show_in_rest' => true, 'supports' => ['title', 'editor'],
        'capability_type' => 'page', 'map_meta_cap' => true,
    ]);
    if (!post_type_exists('ct_content_block')) {
        register_post_type('ct_content_block', [
            'label' => 'Content Blocks test fixture', 'public' => false, 'show_ui' => true,
            'show_in_rest' => true, 'supports' => ['title', 'editor', 'revisions'],
            'capability_type' => 'page', 'map_meta_cap' => true,
        ]);
    }
});

add_action('wp_footer', static function (): void {
    if (!is_singular('page')) {
        return;
    }
    $id = (int) get_post_meta(get_queried_object_id(), '_mcs_test_content_block', true);
    $post = get_post($id);
    if ($post && $post->post_type === 'ct_content_block' && $post->post_status === 'publish') {
        // Preserve the host page global, as required for dynamic page data.
        echo apply_filters('the_content', $post->post_content);
    }
}, 0);
