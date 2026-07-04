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
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
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
}
