<?php

declare(strict_types=1);

namespace Markout\Scheduler;

use Markout\Cache\CacheInterface;
use Markout\Conversion\MarkdownGeneratorInterface;
use Markout\Support\PostMetaExtractorInterface;
use Markout\Support\PostVisibility;

/**
 * Holds the cache-or-purge policy in exactly one place so the save-triggered
 * regeneration path (ActionSchedulerRegenerator) and the backfill path
 * (BackfillScheduler) can't drift out of sync with each other.
 */
final class PostCacher implements PostCacherInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly MarkdownGeneratorInterface $generator,
        private readonly PostMetaExtractorInterface $metaExtractor,
        private readonly PostVisibility $visibility
    ) {
    }

    public function sync(\WP_Post $post): void
    {
        $postId = (int) $post->ID;

        // The cache is never written — and any existing entry is deleted —
        // for password-protected posts, regardless of post_status. The
        // uploads directory is web-accessible, so a cached file would be
        // readable via direct HTTP GET, bypassing WordPress's password gate
        // entirely.
        if ($this->visibility->hasPassword($post)) {
            $this->cache->delete($postId);

            return;
        }

        $markdown = $this->generator->generate($post, $this->metaExtractor->extract($post));
        $this->cache->write($postId, $markdown);
    }
}
