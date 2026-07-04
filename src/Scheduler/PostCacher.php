<?php

declare(strict_types=1);

namespace Markout\Scheduler;

use Markout\Cache\CacheInterface;
use Markout\Conversion\MarkdownGeneratorInterface;
use Markout\Support\PostMetaExtractorInterface;
use Markout\Support\PostVisibility;

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

        if ($this->visibility->hasPassword($post)) {
            $this->cache->delete($postId);

            return;
        }

        $markdown = $this->generator->generate($post, $this->metaExtractor->extract($post));
        $this->cache->write($postId, $markdown);
    }
}
