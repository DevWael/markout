<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$uploadDir = wp_upload_dir();
$cacheDir = rtrim((string) $uploadDir['basedir'], '/') . '/markout';

// Symlinks are unlinked rather than recursed into or rmdir'd — is_dir()
// follows symlinks, so without this check a symlinked directory inside the
// cache dir would cause recursion into (and deletion of) whatever it points
// to, anywhere on the filesystem.
$deleteDir = static function (string $dir) use (&$deleteDir): void {
    if (is_link($dir)) {
        unlink($dir);

        return;
    }

    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_link($path)) {
            unlink($path);

            continue;
        }

        is_dir($path) ? $deleteDir($path) : unlink($path);
    }

    rmdir($dir);
};

$deleteDir($cacheDir);

delete_option('markout_backfill_scheduled');

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('markout_regenerate_md', [], 'markout');
    as_unschedule_all_actions('markout_backfill_batch', [], 'markout');
}
