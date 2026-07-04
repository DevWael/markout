<?php

declare(strict_types=1);

namespace Markout\Scheduler;

final class BackfillScheduler
{
    public const HOOK = 'markout_backfill_batch';
    private const BATCH_SIZE = 20;
    private const ALLOWED_TYPES = ['post', 'page'];
    private const GROUP = 'markout';

    public function __construct(
        private readonly PostFinderInterface $finder,
        private readonly PostCacherInterface $cacher
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runBatch']);
    }

    public function runBatch(int $offset): void
    {
        $posts = $this->finder->findPublished(self::ALLOWED_TYPES, self::BATCH_SIZE, $offset);

        foreach ($posts as $post) {
            $this->cacher->sync($post);
        }

        if (count($posts) !== self::BATCH_SIZE) {
            return;
        }

        $nextOffset = $offset + self::BATCH_SIZE;
        if (as_has_scheduled_action(self::HOOK, [$nextOffset], self::GROUP)) {
            return;
        }

        as_schedule_single_action(time(), self::HOOK, [$nextOffset], self::GROUP);
    }
}
