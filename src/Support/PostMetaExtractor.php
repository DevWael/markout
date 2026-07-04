<?php

declare(strict_types=1);

namespace Markout\Support;

final class PostMetaExtractor implements PostMetaExtractorInterface
{
    public function extract(\WP_Post $post): array
    {
        return [
            'title' => (string) get_the_title($post),
            'date' => (string) get_the_date('c', $post),
            'author' => (string) get_the_author_meta('display_name', (int) $post->post_author),
            'permalink' => (string) get_permalink($post),
        ];
    }
}
