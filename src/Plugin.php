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

/**
 * Composition root: wires the plugin's collaborators and drives the
 * WordPress activation/deactivation/boot lifecycle.
 */
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

    // Runs on every `plugins_loaded` rather than in activate(): Action
    // Scheduler's own data store may not have finished migrating yet at
    // activation time on a brand-new install, so scheduling here instead
    // doubles as the "did activation actually take" self-check.
    //
    // add_option() is used as an atomic insert-if-absent guard rather than
    // a get_option()-then-update_option() pair: the latter is a
    // check-then-act race that lets two near-simultaneous requests both
    // pass the check and each enqueue an overlapping backfill chain.
    // add_option() can only succeed once for a given option name, so at
    // most one request ever wins the race and schedules the backfill.
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
