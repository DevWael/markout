<?php

declare(strict_types=1);

namespace Markout\Scheduler;

use Markout\Cache\CacheInterface;
use Markout\Support\PostVisibility;

final class ActionSchedulerRegenerator implements RegeneratorInterface
{
    public const REGENERATE_HOOK = 'markout_regenerate_md';
    private const ALLOWED_TYPES = ['post', 'page'];
    private const GROUP = 'markout';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly PostVisibility $visibility,
        private readonly PostCacherInterface $cacher
    ) {
    }

    public function register(): void
    {
        add_action('save_post', [$this, 'onSave'], 10, 3);
        add_action('transition_post_status', [$this, 'onTransition'], 10, 3);
        add_action('before_delete_post', [$this, 'onDelete']);
        add_action(self::REGENERATE_HOOK, [$this, 'handleRegenerate']);
    }

    public function onSave(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $this->syncOrPurge($postId, $post);
    }

    public function onTransition(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($newStatus === $oldStatus) {
            return;
        }

        $this->syncOrPurge((int) $post->ID, $post);
    }

    public function onDelete(int $postId): void
    {
        $this->cache->delete($postId);
    }

    private function syncOrPurge(int $postId, \WP_Post $post): void
    {
        if (!in_array($post->post_type, self::ALLOWED_TYPES, true)) {
            return;
        }

        if ($post->post_status === 'publish' && !$this->visibility->hasPassword($post)) {
            $this->enqueue($postId);

            return;
        }

        $this->cache->delete($postId);
    }

    public function handleRegenerate(int $postId): void
    {
        $post = get_post($postId);

        if (
            !($post instanceof \WP_Post)
            || $post->post_status !== 'publish'
            || !in_array($post->post_type, self::ALLOWED_TYPES, true)
        ) {
            $this->cache->delete($postId);

            return;
        }

        $this->cacher->sync($post);
    }

    private function enqueue(int $postId): void
    {
        if (as_has_scheduled_action(self::REGENERATE_HOOK, [$postId], self::GROUP)) {
            return;
        }

        as_enqueue_async_action(self::REGENERATE_HOOK, [$postId], self::GROUP);
    }
}
