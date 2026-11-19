<?php
/**
 * Plugin Name: MCS local acceptance fixture
 * Description: Test-only shortcode, asset handles, and a lower-privilege user for a disposable WordPress instance.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('mcs_acceptance', static fn() => '<strong class="mcs-shortcode">Shortcode ' . (int) get_queried_object_id() . '</strong>');

add_action('wp_enqueue_scripts', static function (): void {
    wp_register_style('mcs-fixture-global', false);
    wp_enqueue_style('mcs-fixture-global');
    wp_add_inline_style('mcs-fixture-global', '.mcs-test-global{color:rgb(25,46,69)}');
    if (is_singular() && str_ends_with(strtok($_SERVER['REQUEST_URI'], '?'), '/mcs-acceptance/')) {
        wp_add_inline_style('mcs-fixture-global', '.mcs-test-conditional{font-weight:700}');
    }
    wp_register_script('mcs-fixture-script', false, ['jquery'], null, true);
    wp_add_inline_script('mcs-fixture-script', 'window.mcsFixtureDependency = true;');
});

add_action('init', static function (): void {
    if (!username_exists('mcs_fixture_author')) {
        $id = wp_insert_user(['user_login' => 'mcs_fixture_author', 'user_pass' => 'mcs-local-fixture-author', 'role' => 'author']);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
    }
});
