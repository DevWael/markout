<?php

declare(strict_types=1);

namespace Markout\Cache;

interface CacheInterface {

	public function get( int $postId ): ?string;

	public function write( int $postId, string $content ): bool;

	// Must be idempotent (true even if no entry exists): callers rely on
	// this to purge unconditionally from multiple, possibly redundant,
	// hooks without checking existence first.
	public function delete( int $postId ): bool;
}
