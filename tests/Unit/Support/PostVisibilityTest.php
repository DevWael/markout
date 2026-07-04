<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Markout\Support\PostVisibility;
use Markout\Tests\TestCase;

final class PostVisibilityTest extends TestCase
{
    public function test_requires_password_returns_true_when_wp_says_so(): void
    {
        Functions\when('post_password_required')->justReturn(true);

        $post = new \WP_Post();
        $visibility = new PostVisibility();

        self::assertTrue($visibility->requiresPassword($post));
    }

    public function test_requires_password_returns_false_when_wp_says_so(): void
    {
        Functions\when('post_password_required')->justReturn(false);

        $post = new \WP_Post();
        $visibility = new PostVisibility();

        self::assertFalse($visibility->requiresPassword($post));
    }

    public function test_has_password_true_when_post_password_is_set(): void
    {
        $post = new \WP_Post();
        $post->post_password = 'secret';

        self::assertTrue((new PostVisibility())->hasPassword($post));
    }

    public function test_has_password_false_when_post_password_is_empty(): void
    {
        $post = new \WP_Post();
        $post->post_password = '';

        self::assertFalse((new PostVisibility())->hasPassword($post));
    }
}
