<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Scheduler;

use Brain\Monkey\Functions;
use Markout\Scheduler\BackfillScheduler;
use Markout\Scheduler\PostCacherInterface;
use Markout\Scheduler\PostFinderInterface;
use Markout\Tests\TestCase;

final class BackfillSchedulerTest extends TestCase
{
    /**
     * @return PostCacherInterface&object{syncedPostIds: int[]}
     */
    private function fakeCacher(): PostCacherInterface
    {
        return new class implements PostCacherInterface {
            public array $syncedPostIds = [];

            public function sync(\WP_Post $post): void
            {
                $this->syncedPostIds[] = $post->ID;
            }
        };
    }

    private function finderReturning(array $posts): PostFinderInterface
    {
        return new class ($posts) implements PostFinderInterface {
            public function __construct(private array $posts)
            {
            }

            public function findPublished(array $postTypes, int $limit, int $offset): array
            {
                return $this->posts;
            }
        };
    }

    private function makePost(int $id): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;

        return $post;
    }

    public function test_run_batch_syncs_every_post_found(): void
    {
        $posts = [$this->makePost(1), $this->makePost(2)];
        $cacher = $this->fakeCacher();

        $scheduler = new BackfillScheduler($this->finderReturning($posts), $cacher);

        $scheduler->runBatch(0);

        self::assertSame([1, 2], $cacher->syncedPostIds);
    }

    public function test_run_batch_reschedules_when_batch_is_full(): void
    {
        $fullBatch = array_map(fn (int $i) => $this->makePost($i), range(1, 20));

        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(\Mockery::type('int'), BackfillScheduler::HOOK, [20], 'markout');

        $scheduler = new BackfillScheduler($this->finderReturning($fullBatch), $this->fakeCacher());

        $scheduler->runBatch(0);
    }

    public function test_run_batch_does_not_reschedule_when_already_scheduled(): void
    {
        $fullBatch = array_map(fn (int $i) => $this->makePost($i), range(1, 20));

        Functions\when('as_has_scheduled_action')->justReturn(true);
        Functions\expect('as_schedule_single_action')->never();

        $scheduler = new BackfillScheduler($this->finderReturning($fullBatch), $this->fakeCacher());

        $scheduler->runBatch(0);
    }

    public function test_run_batch_does_not_reschedule_when_batch_is_partial(): void
    {
        Functions\expect('as_schedule_single_action')->never();

        $scheduler = new BackfillScheduler($this->finderReturning([$this->makePost(1)]), $this->fakeCacher());

        $scheduler->runBatch(0);
    }
}
