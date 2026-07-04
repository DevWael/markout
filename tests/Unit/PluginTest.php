<?php

declare(strict_types=1);

namespace Markout\Tests\Unit;

use Brain\Monkey\Functions;
use Markout\Plugin;
use Markout\Tests\TestCase;

final class PluginTest extends TestCase
{
    private function cacheDir(): string
    {
        return sys_get_temp_dir() . '/markout-plugin-test-' . uniqid('', true);
    }

    public function test_boot_registers_hooks(): void
    {
        Functions\expect('add_action')->atLeast()->once();

        $plugin = new Plugin($this->cacheDir());

        $plugin->boot();

        self::assertTrue(true);
    }

    public function test_boot_schedules_backfill_once_when_option_is_new(): void
    {
        Functions\when('add_action')->justReturn(true);
        // add_option returns true => the backfill has not been scheduled before.
        Functions\when('add_option')->justReturn(true);

        $scheduled = null;
        Functions\when('as_schedule_single_action')->alias(
            static function (int $timestamp, string $hook, array $args, string $group) use (&$scheduled): void {
                $scheduled = [$hook, $args, $group];
            }
        );

        $plugin = new Plugin($this->cacheDir());
        $plugin->boot();

        self::assertSame(
            [\Markout\Scheduler\BackfillScheduler::HOOK, [0], 'markout'],
            $scheduled
        );
    }

    public function test_boot_does_not_reschedule_backfill_when_option_already_exists(): void
    {
        Functions\when('add_action')->justReturn(true);
        // add_option returns false => option already exists, so no scheduling.
        Functions\when('add_option')->justReturn(false);

        $scheduledCalls = 0;
        Functions\when('as_schedule_single_action')->alias(
            static function () use (&$scheduledCalls): void {
                $scheduledCalls++;
            }
        );

        $plugin = new Plugin($this->cacheDir());
        $plugin->boot();

        self::assertSame(0, $scheduledCalls);
    }

    public function test_activate_flushes_rewrite_rules(): void
    {
        $flushed = 0;
        Functions\when('flush_rewrite_rules')->alias(
            static function () use (&$flushed): void {
                $flushed++;
            }
        );

        Plugin::activate();

        self::assertSame(1, $flushed);
    }

    public function test_deactivate_flushes_rules_deletes_option_and_unschedules_actions(): void
    {
        $flushed = 0;
        Functions\when('flush_rewrite_rules')->alias(
            static function () use (&$flushed): void {
                $flushed++;
            }
        );

        $deletedOption = null;
        Functions\when('delete_option')->alias(
            static function (string $name) use (&$deletedOption): void {
                $deletedOption = $name;
            }
        );

        $unscheduledHooks = [];
        Functions\when('as_unschedule_all_actions')->alias(
            static function (string $hook, array $args, string $group) use (&$unscheduledHooks): void {
                $unscheduledHooks[] = $hook;
            }
        );

        Plugin::deactivate();

        self::assertSame(1, $flushed);
        self::assertSame('markout_backfill_scheduled', $deletedOption);
        self::assertSame(
            [
                \Markout\Scheduler\ActionSchedulerRegenerator::REGENERATE_HOOK,
                \Markout\Scheduler\BackfillScheduler::HOOK,
            ],
            $unscheduledHooks
        );
    }
}
