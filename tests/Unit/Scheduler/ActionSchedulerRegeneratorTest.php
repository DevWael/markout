<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Scheduler;

use Brain\Monkey\Functions;
use Markout\Cache\CacheInterface;
use Markout\Scheduler\ActionSchedulerRegenerator;
use Markout\Scheduler\PostCacherInterface;
use Markout\Support\PostVisibility;
use Markout\Tests\TestCase;

final class ActionSchedulerRegeneratorTest extends TestCase
{
    private function fakeCache(): CacheInterface
    {
        return new class implements CacheInterface {
            public array $writes = [];
            public array $deletes = [];

            public function get(int $postId): ?string
            {
                return null;
            }

            public function write(int $postId, string $content): bool
            {
                $this->writes[] = [$postId, $content];

                return true;
            }

            public function delete(int $postId): bool
            {
                $this->deletes[] = $postId;

                return true;
            }
        };
    }

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

    public function test_on_save_enqueues_when_publish_allowed_type_and_no_password(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\expect('as_enqueue_async_action')
            ->once()
            ->with(ActionSchedulerRegenerator::REGENERATE_HOOK, [5], 'markout');

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $post = new \WP_Post();
        $post->post_type = 'post';
        $post->post_status = 'publish';
        $post->post_password = '';

        $regenerator->onSave(5, $post, true);
    }

    public function test_on_save_deletes_cache_when_not_publish(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $post = new \WP_Post();
        $post->post_type = 'post';
        $post->post_status = 'draft';
        $post->post_password = '';

        $regenerator->onSave(5, $post, true);

        self::assertSame([5], $cache->deletes);
    }

    public function test_on_save_deletes_cache_when_password_protected_even_if_published(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $post = new \WP_Post();
        $post->post_type = 'post';
        $post->post_status = 'publish';
        $post->post_password = 'secret';

        $regenerator->onSave(5, $post, true);

        self::assertSame([5], $cache->deletes);
    }

    public function test_on_save_skips_revisions(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(true);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $post = new \WP_Post();
        $post->post_type = 'post';
        $post->post_status = 'publish';

        $regenerator->onSave(5, $post, true);

        self::assertSame([], $cache->deletes);
    }

    public function test_on_delete_removes_cache(): void
    {
        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $regenerator->onDelete(9);

        self::assertSame([9], $cache->deletes);
    }

    public function test_on_transition_deletes_cache_when_leaving_publish(): void
    {
        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $post = new \WP_Post();
        $post->ID = 11;
        $post->post_type = 'post';
        $post->post_status = 'trash';
        $post->post_password = '';

        $regenerator->onTransition('trash', 'publish', $post);

        self::assertSame([11], $cache->deletes);
    }

    public function test_on_transition_enqueues_when_becoming_publish(): void
    {
        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\expect('as_enqueue_async_action')
            ->once()
            ->with(ActionSchedulerRegenerator::REGENERATE_HOOK, [11], 'markout');

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $post = new \WP_Post();
        $post->ID = 11;
        $post->post_type = 'post';
        $post->post_status = 'publish';
        $post->post_password = '';

        $regenerator->onTransition('publish', 'draft', $post);
    }

    public function test_on_transition_does_nothing_when_status_is_unchanged(): void
    {
        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $post = new \WP_Post();
        $post->ID = 11;
        $post->post_type = 'post';
        $post->post_status = 'publish';

        $regenerator->onTransition('publish', 'publish', $post);

        self::assertSame([], $cache->deletes);
    }

    public function test_handle_regenerate_syncs_valid_public_post(): void
    {
        $post = new \WP_Post();
        $post->ID = 3;
        $post->post_type = 'page';
        $post->post_status = 'publish';

        Functions\when('get_post')->justReturn($post);

        $cache = $this->fakeCache();
        $cacher = $this->fakeCacher();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $cacher);

        $regenerator->handleRegenerate(3);

        self::assertSame([3], $cacher->syncedPostIds);
    }

    public function test_handle_regenerate_deletes_cache_when_post_no_longer_exists(): void
    {
        Functions\when('get_post')->justReturn(null);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $regenerator->handleRegenerate(3);

        self::assertSame([3], $cache->deletes);
    }

    public function test_handle_regenerate_deletes_cache_when_post_is_not_publish(): void
    {
        $post = new \WP_Post();
        $post->ID = 3;
        $post->post_type = 'page';
        $post->post_status = 'draft';

        Functions\when('get_post')->justReturn($post);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, new PostVisibility(), $this->fakeCacher());

        $regenerator->handleRegenerate(3);

        self::assertSame([3], $cache->deletes);
    }
}
