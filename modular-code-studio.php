<?php
/**
 * Plugin Name: Modular Code Studio
 * Description: Independent Gutenberg HTML, CSS and JavaScript modules with frontend previews and page-level asset bundles.
 * Version: 1.1.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author: CHNyin
 * License: GPL-3.0-or-later
 * Text Domain: modular-code-studio
 */

namespace MCS\CodeModules;

defined('ABSPATH') || exit;

const VERSION = '1.1.0';
const FILE = __FILE__;
const DIR = __DIR__ . '/';

if (!extension_loaded('mbstring') || !is_readable(DIR . 'vendor/autoload.php') || !is_readable(DIR . 'build/code-module/block.json')) {
    $mcs_message = 'Modular Code Studio requires the mbstring extension and the complete compiled plugin package.';
    register_activation_hook(FILE, static function () use ($mcs_message): void {
        wp_die(esc_html($mcs_message), '', ['back_link' => true]);
    });
    add_action('admin_notices', static function () use ($mcs_message): void {
        echo '<div class="notice notice-error"><p>' . esc_html($mcs_message) . '</p></div>';
    });
    unset($mcs_message);
    return;
}

require_once DIR . 'vendor/autoload.php';
foreach (['settings', 'sources', 'modules', 'css', 'bundler', 'storage', 'assets', 'integrations', 'preview', 'admin', 'plugin'] as $mcs_component) {
    require_once DIR . 'includes/class-' . $mcs_component . '.php';
}
unset($mcs_component);

register_activation_hook(FILE, [Plugin::class, 'activate']);
register_deactivation_hook(FILE, [Plugin::class, 'deactivate']);
Plugin::boot();
