<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Cache;

use Markout\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/markout-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            @chmod($this->directory, 0755);
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                if (is_dir($file)) {
                    foreach (glob($file . '/*') ?: [] as $nested) {
                        @unlink($nested);
                    }
                    @rmdir($file);
                    continue;
                }
                @unlink($file);
            }
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_get_returns_null_when_file_missing(): void
    {
        $cache = new FileCache($this->directory);

        self::assertNull($cache->get(123));
    }

    public function test_write_then_get_round_trips_content(): void
    {
        $cache = new FileCache($this->directory);

        self::assertTrue($cache->write(123, "---\ntitle: \"x\"\n---\n\nBody"));
        self::assertSame("---\ntitle: \"x\"\n---\n\nBody", $cache->get(123));
    }

    public function test_write_leaves_no_temp_files_behind(): void
    {
        $cache = new FileCache($this->directory);
        $cache->write(123, 'content');

        $leftovers = array_filter(
            glob($this->directory . '/*') ?: [],
            static fn (string $path): bool => !str_ends_with($path, '123.md') && !str_ends_with($path, 'index.php')
        );

        self::assertSame([], $leftovers);
    }

    public function test_delete_removes_file_and_returns_true(): void
    {
        $cache = new FileCache($this->directory);
        $cache->write(123, 'content');

        self::assertTrue($cache->delete(123));
        self::assertNull($cache->get(123));
    }

    public function test_delete_of_missing_file_returns_true(): void
    {
        $cache = new FileCache($this->directory);

        self::assertTrue($cache->delete(999));
    }

    public function test_constructor_creates_directory_with_index_guard(): void
    {
        new FileCache($this->directory);

        self::assertDirectoryExists($this->directory);
        self::assertFileExists($this->directory . '/index.php');
    }

    public function test_write_returns_false_when_directory_cannot_be_created(): void
    {
        // A regular file at the target path makes mkdir() fail, so the directory
        // never exists and write() must bail out via the "not writable" branch.
        $filePath = sys_get_temp_dir() . '/markout-blocker-' . uniqid('', true);
        file_put_contents($filePath, 'i am a file, not a directory');

        try {
            $cache = new FileCache($filePath . '/cache');

            self::assertFalse($cache->write(123, 'content'));
        } finally {
            unlink($filePath);
        }
    }

    public function test_write_returns_false_when_temp_file_cannot_be_written(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory write permissions.');
        }

        $cache = new FileCache($this->directory);

        // Make the cache directory read-only so file_put_contents() of the temp
        // file fails, exercising the temp-write failure branch.
        chmod($this->directory, 0500);

        try {
            self::assertFalse(@$cache->write(123, 'content'));
        } finally {
            chmod($this->directory, 0755);
        }
    }

    public function test_write_returns_false_and_cleans_up_when_rename_fails(): void
    {
        $cache = new FileCache($this->directory);

        // Create a directory where the final .md file should go. rename() of a
        // file onto a non-empty directory fails, exercising the rename-failure
        // branch (including the @unlink cleanup of the temp file).
        mkdir($this->directory . '/123.md');
        file_put_contents($this->directory . '/123.md/occupant', 'x');

        try {
            self::assertFalse(@$cache->write(123, 'content'));

            $leftovers = array_filter(
                glob($this->directory . '/*') ?: [],
                static fn (string $path): bool => str_contains(basename($path), 'tmp')
            );

            self::assertSame([], $leftovers, 'Temp file should be cleaned up after a failed rename.');
        } finally {
            @unlink($this->directory . '/123.md/occupant');
            @rmdir($this->directory . '/123.md');
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_write_logs_failure_when_wp_debug_enabled(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory write permissions.');
        }

        // Runs in a separate process so defining WP_DEBUG does not leak into the
        // rest of the suite. With WP_DEBUG on, the write failure routes through
        // logFailure() -> error_log(), which we capture to a temp file to assert
        // the log line is actually emitted.
        define('WP_DEBUG', true);

        $logFile = sys_get_temp_dir() . '/markout-log-' . uniqid('', true) . '.log';
        $previous = ini_set('error_log', $logFile);

        $cache = new FileCache($this->directory);
        chmod($this->directory, 0500);

        try {
            self::assertFalse(@$cache->write(123, 'content'));

            self::assertFileExists($logFile);
            self::assertStringContainsString(
                'Markout: Failed to write temporary cache file',
                (string) file_get_contents($logFile)
            );
        } finally {
            chmod($this->directory, 0755);
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
            @unlink($logFile);
        }
    }
}
