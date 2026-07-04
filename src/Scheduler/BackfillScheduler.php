<?php

declare(strict_types=1);

namespace Markout\Scheduler;

/**
 * Populates the cache for pre-existing posts/pages in fixed-size batches,
 * self-re-enqueuing via Action Scheduler until every published post has
 * been visited, without blocking activation or timing out on large sites.
 */
final class BackfillScheduler {

	public const HOOK           = 'markout_backfill_batch';
	private const BATCH_SIZE    = 20;
	private const ALLOWED_TYPES = array( 'post', 'page' );
	private const GROUP         = 'markout';

	public function __construct(
		private readonly PostFinderInterface $finder,
		private readonly PostCacherInterface $cacher
	) {
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'runBatch' ) );
	}

	public function runBatch( int $offset ): void {
		$posts = $this->finder->findPublished( self::ALLOWED_TYPES, self::BATCH_SIZE, $offset );

		foreach ( $posts as $post ) {
			$this->cacher->sync( $post );
		}

		// A batch shorter than the page size means the finder has run out of
		// posts; anything else would re-enqueue forever.
		if ( count( $posts ) !== self::BATCH_SIZE ) {
			return;
		}

		// Dedupes the same way save_post regeneration does, so a manually
		// re-triggered backfill (e.g. re-running the batch action by hand)
		// can't produce overlapping, duplicate batch chains.
		$nextOffset = $offset + self::BATCH_SIZE;
		if ( as_has_scheduled_action( self::HOOK, array( $nextOffset ), self::GROUP ) ) {
			return;
		}

		as_schedule_single_action( time(), self::HOOK, array( $nextOffset ), self::GROUP );
	}
}
