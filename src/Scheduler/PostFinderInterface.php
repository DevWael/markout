<?php

declare(strict_types=1);

namespace Markout\Scheduler;

interface PostFinderInterface {

	/**
	 * @param string[] $postTypes
	 * @return \WP_Post[]
	 */
	public function findPublished( array $postTypes, int $limit, int $offset ): array;
}
