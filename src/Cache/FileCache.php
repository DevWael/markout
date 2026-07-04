<?php

declare(strict_types=1);

namespace Markout\Cache;

/**
 * Stores converted markdown as one file per post under a single
 * upload-relative directory; not database-backed by design.
 */
final class FileCache implements CacheInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
        $this->ensureDirectoryExists();
    }

    public function get(int $postId): ?string
    {
        $path = $this->path($postId);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function write(int $postId, string $content): bool
    {
        $this->ensureDirectoryExists();

        if (!is_dir($this->directory)) {
            $this->logFailure('Cache directory is not writable: ' . $this->directory);

            return false;
        }

        // Written to a uniquely-named temp file first, then moved into place
        // with rename() (atomic on the same filesystem), so a concurrent
        // reader hitting a cache miss can never observe a partially-written
        // file while an async regeneration job is mid-write.
        $path = $this->path($postId);
        $tempPath = $path . '.' . uniqid('tmp', true);

        if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
            $this->logFailure('Failed to write temporary cache file: ' . $tempPath);

            return false;
        }

        if (!rename($tempPath, $path)) {
            $this->logFailure('Failed to move temporary cache file into place: ' . $path);
            @unlink($tempPath);

            return false;
        }

        return true;
    }

    public function delete(int $postId): bool
    {
        $path = $this->path($postId);
        if (!is_file($path)) {
            return true;
        }

        return unlink($path);
    }

    private function path(int $postId): string
    {
        return sprintf('%s/%d.md', $this->directory, $postId);
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0755, true);
        }

        if (!is_dir($this->directory)) {
            return;
        }

        $index = $this->directory . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    private function logFailure(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Markout: ' . $message);
        }
    }
}
