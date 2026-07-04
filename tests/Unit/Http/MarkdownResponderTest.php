<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Http;

use Brain\Monkey\Functions;
use Markout\Cache\CacheInterface;
use Markout\Conversion\ConverterInterface;
use Markout\Conversion\FrontmatterBuilder;
use Markout\Http\MarkdownResponder;
use Markout\Support\PostVisibility;
use Markout\Tests\TestCase;

final class MarkdownResponderTest extends TestCase
{
    private function meta(): array
    {
        return [
            'title' => 'Hello',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/hello/',
        ];
    }

    public function test_respond_returns_403_when_password_required(): void
    {
        Functions\when('post_password_required')->justReturn(true);

        $responder = new MarkdownResponder(
            $this->cacheReturning(null),
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond(new \WP_Post(), $this->meta());

        self::assertSame(403, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->contentType);
    }

    public function test_respond_returns_cached_content_on_hit(): void
    {
        Functions\when('post_password_required')->justReturn(false);

        $responder = new MarkdownResponder(
            $this->cacheReturning('cached markdown'),
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond(new \WP_Post(), $this->meta());

        self::assertSame(200, $response->status);
        self::assertSame('text/markdown; charset=utf-8', $response->contentType);
        self::assertSame('cached markdown', $response->body);
    }

    public function test_respond_generates_and_writes_to_cache_on_miss(): void
    {
        Functions\when('post_password_required')->justReturn(false);

        $cache = new class implements CacheInterface {
            public array $writes = [];

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
                return true;
            }
        };

        $post = new \WP_Post();
        $post->ID = 42;

        $responder = new MarkdownResponder(
            $cache,
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond($post, $this->meta());

        self::assertSame(200, $response->status);
        self::assertStringContainsString('BODY', $response->body);
        self::assertCount(1, $cache->writes);
        self::assertSame(42, $cache->writes[0][0]);
    }

    public function test_respond_does_not_cache_when_post_has_password_even_if_visitor_is_authorized(): void
    {
        // Visitor has entered the correct password (wp-postpass cookie set),
        // so post_password_required() returns false — but the post itself
        // is still password-protected.
        Functions\when('post_password_required')->justReturn(false);

        $cache = new class implements CacheInterface {
            public array $writes = [];

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
                return true;
            }
        };

        $post = new \WP_Post();
        $post->ID = 42;
        $post->post_password = 'secret';

        $responder = new MarkdownResponder(
            $cache,
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond($post, $this->meta());

        self::assertSame(200, $response->status);
        self::assertStringContainsString('BODY', $response->body);
        self::assertCount(0, $cache->writes);
    }

    private function cacheReturning(?string $value): CacheInterface
    {
        return new class ($value) implements CacheInterface {
            public function __construct(private ?string $value)
            {
            }

            public function get(int $postId): ?string
            {
                return $this->value;
            }

            public function write(int $postId, string $content): bool
            {
                return true;
            }

            public function delete(int $postId): bool
            {
                return true;
            }
        };
    }

    private function converterReturning(string $value): ConverterInterface
    {
        return new class ($value) implements ConverterInterface {
            public function __construct(private string $value)
            {
            }

            public function convert(string $html): string
            {
                return $this->value;
            }
        };
    }
}
