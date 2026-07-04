<?php

declare(strict_types=1);

namespace Markout\Scheduler;

final class WPQueryPostFinder implements PostFinderInterface {

	public function findPublished( array $postTypes, int $limit, int $offset ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => $postTypes,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				// Ordering by ID (rather than the default date order) keeps
				// offset-based pagination stable across batches even if posts
				// are edited between backfill runs. no_found_rows skips the
				// COUNT(*) WP_Query would otherwise run, which BackfillScheduler
				// doesn't need since it detects the last page by batch size.
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return array_values(
			array_filter(
				$query->posts,
				static fn ( $post ): bool => $post instanceof \WP_Post
			)
		);
	}
}
