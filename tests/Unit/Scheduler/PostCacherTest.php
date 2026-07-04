<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Scheduler;

use Markout\Cache\CacheInterface;
use Markout\Conversion\MarkdownGeneratorInterface;
use Markout\Scheduler\PostCacher;
use Markout\Support\PostMetaExtractorInterface;
use Markout\Support\PostVisibility;
use PHPUnit\Framework\TestCase;

final class PostCacherTest extends TestCase
{
    private function fakeCache(): CacheInterface
    {
        return new class implements CacheInterface {
            public array $writes = [];
            public array $deletes = [];

            public function get(int $postId): ?string
            {
                return null;
            }

            public function write(int $postId, string $content): bool
            {
                $this->writes[] = [$postId, $content];

                return true;
            }

            public function delete(int $postId): bool
            {
                $this->deletes[] = $postId;

                return true;
            }
        };
    }

    private function fakeGenerator(): MarkdownGeneratorInterface
    {
        return new class implements MarkdownGeneratorInterface {
            public function generate(\WP_Post $post, array $meta): string
            {
                return 'md-' . $post->ID;
            }
        };
    }

    private function fakeMetaExtractor(): PostMetaExtractorInterface
    {
        return new class implements PostMetaExtractorInterface {
            public function extract(\WP_Post $post): array
            {
                return ['title' => '', 'date' => '', 'author' => '', 'permalink' => ''];
            }
        };
    }

    public function test_sync_writes_generated_markdown_for_public_post(): void
    {
        $cache = $this->fakeCache();
        $cacher = new PostCacher($cache, $this->fakeGenerator(), $this->fakeMetaExtractor(), new PostVisibility());

        $post = new \WP_Post();
        $post->ID = 5;
        $post->post_password = '';

        $cacher->sync($post);

        self::assertSame([[5, 'md-5']], $cache->writes);
        self::assertSame([], $cache->deletes);
    }

    public function test_sync_deletes_cache_for_password_protected_post(): void
    {
        $cache = $this->fakeCache();
        $cacher = new PostCacher($cache, $this->fakeGenerator(), $this->fakeMetaExtractor(), new PostVisibility());

        $post = new \WP_Post();
        $post->ID = 5;
        $post->post_password = 'secret';

        $cacher->sync($post);

        self::assertSame([], $cache->writes);
        self::assertSame([5], $cache->deletes);
    }
}
