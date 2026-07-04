<?php

declare(strict_types=1);

namespace Markout\Scheduler;

use Markout\Cache\CacheInterface;
use Markout\Support\PostVisibility;

/**
 * Keeps the on-disk markdown cache in sync with post save/status-change/
 * delete events, enqueuing regeneration asynchronously via Action Scheduler.
 */
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

    // save_post and transition_post_status are both hooked, and both funnel
    // into the same syncOrPurge() decision. This is deliberate redundancy,
    // not an oversight: save_post is not guaranteed to fire on every status
    // change on every WordPress version (e.g. some trashing paths have not
    // always routed through wp_insert_post()), while transition_post_status
    // fires on every status change without exception. Calling both hooks
    // for the same event is harmless — enqueue() is deduped via
    // as_has_scheduled_action(), and CacheInterface::delete() is idempotent.
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

    // Re-checks status/type/existence rather than trusting the enqueue-time
    // decision, guarding against a race where the post's status changed
    // again in the (asynchronous, unbounded) gap between enqueue and run.
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
