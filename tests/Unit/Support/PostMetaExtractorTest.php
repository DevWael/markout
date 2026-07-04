<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Markout\Support\PostMetaExtractor;
use Markout\Tests\TestCase;

final class PostMetaExtractorTest extends TestCase
{
    public function test_extract_reads_expected_wordpress_functions(): void
    {
        Functions\when('get_the_title')->justReturn('Hello World');
        Functions\when('get_the_date')->justReturn('2026-07-04T00:00:00+00:00');
        Functions\when('get_the_author_meta')->justReturn('Ahmad');
        Functions\when('get_permalink')->justReturn('https://example.com/hello-world/');

        $post = new \WP_Post();
        $post->post_author = 1;

        $meta = (new PostMetaExtractor())->extract($post);

        self::assertSame([
            'title' => 'Hello World',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/hello-world/',
        ], $meta);
    }
}
