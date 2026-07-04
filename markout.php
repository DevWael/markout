<?php

/**
 * Plugin Name: Markout
 * Description: Serve a markdown version of any post or page via /md.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Author: Ahmad Wael
 * Author URI: https://bbioon.com
 * License: GPL-2.0-or-later
 * Text Domain: markout
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MARKOUT_PLUGIN_FILE', __FILE__);

// Loaded unhooked, not on 'init': the missing-Composer-dependencies notice
// below is built (and its __() call resolved) before any WordPress action
// fires, since that check runs at top-level file inclusion.
load_plugin_textdomain('markout', false, dirname(plugin_basename(MARKOUT_PLUGIN_FILE)) . '/languages');

function markout_deactivate_with_notice(string $message): void
{
    add_action('admin_notices', static function () use ($message): void {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    });

    add_action('admin_init', static function (): void {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins(plugin_basename(MARKOUT_PLUGIN_FILE));
    });
}

// Composer dependencies are required, not bundled; a fresh git checkout
// without `composer install` must not fatal on every page load.
$markoutAutoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($markoutAutoload)) {
    markout_deactivate_with_notice(
        __('Markout: missing Composer dependencies. Run composer install in the plugin directory.', 'markout')
    );

    return;
}

require_once $markoutAutoload;

use Markout\Plugin;

add_action('plugins_loaded', static function (): void {
    // Action Scheduler may be missing if no other active plugin bundles it;
    // Markout's cache regeneration and backfill both depend on it, so there
    // is no safe degraded mode to fall back to.
    if (!function_exists('as_enqueue_async_action')) {
        markout_deactivate_with_notice(__('Markout: Action Scheduler is unavailable.', 'markout'));

        return;
    }

    $uploadDir = wp_upload_dir();
    $plugin = new Plugin(rtrim((string) $uploadDir['basedir'], '/') . '/markout');
    $plugin->boot();
});

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
