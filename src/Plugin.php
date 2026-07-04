<?php

declare(strict_types=1);

namespace Markout;

use Markout\Cache\FileCache;
use Markout\Conversion\FrontmatterBuilder;
use Markout\Conversion\HtmlToMarkdownConverter;
use Markout\Http\MarkdownRequestHandler;
use Markout\Http\MarkdownResponder;
use Markout\Router\EndpointRouter;
use Markout\Scheduler\ActionSchedulerRegenerator;
use Markout\Scheduler\BackfillScheduler;
use Markout\Scheduler\PostCacher;
use Markout\Scheduler\WPQueryPostFinder;
use Markout\Support\PostMetaExtractor;
use Markout\Support\PostVisibility;

final class Plugin
{
    private const BACKFILL_SCHEDULED_OPTION = 'markout_backfill_scheduled';

    private readonly EndpointRouter $router;
    private readonly ActionSchedulerRegenerator $regenerator;
    private readonly BackfillScheduler $backfill;

    public function __construct(string $cacheDirectory)
    {
        $cache = new FileCache($cacheDirectory);
        $visibility = new PostVisibility();
        $metaExtractor = new PostMetaExtractor();

        $responder = new MarkdownResponder(
            $cache,
            new HtmlToMarkdownConverter(),
            new FrontmatterBuilder(),
            $visibility
        );

        $cacher = new PostCacher($cache, $responder, $metaExtractor, $visibility);
        $requestHandler = new MarkdownRequestHandler($responder, $metaExtractor);

        $this->router = new EndpointRouter($requestHandler);
        $this->regenerator = new ActionSchedulerRegenerator($cache, $visibility, $cacher);
        $this->backfill = new BackfillScheduler(new WPQueryPostFinder(), $cacher);
    }

    public function boot(): void
    {
        $this->router->register();
        $this->regenerator->register();
        $this->backfill->register();
        $this->maybeScheduleBackfill();
    }

    private function maybeScheduleBackfill(): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        if (!add_option(self::BACKFILL_SCHEDULED_OPTION, true, '', false)) {
            return;
        }

        as_schedule_single_action(time(), BackfillScheduler::HOOK, [0], 'markout');
    }

    public static function activate(): void
    {
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();

        delete_option(self::BACKFILL_SCHEDULED_OPTION);

        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(ActionSchedulerRegenerator::REGENERATE_HOOK, [], 'markout');
        as_unschedule_all_actions(BackfillScheduler::HOOK, [], 'markout');
    }
}
