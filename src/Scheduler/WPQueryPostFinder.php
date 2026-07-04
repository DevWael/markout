<?php

declare(strict_types=1);

namespace Markout\Scheduler;

final class WPQueryPostFinder implements PostFinderInterface
{
    public function findPublished(array $postTypes, int $limit, int $offset): array
    {
        $query = new \WP_Query([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        return array_values(array_filter(
            $query->posts,
            static fn ($post): bool => $post instanceof \WP_Post
        ));
    }
}
