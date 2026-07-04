<?php

declare(strict_types=1);

namespace Markout\Support;

final class PostVisibility
{
    public function requiresPassword(\WP_Post $post): bool
    {
        return (bool) post_password_required($post);
    }

    public function hasPassword(\WP_Post $post): bool
    {
        return $post->post_password !== '';
    }
}
