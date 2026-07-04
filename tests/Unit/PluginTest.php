<?php

declare(strict_types=1);

namespace Markout\Tests\Unit;

use Brain\Monkey\Functions;
use Markout\Plugin;
use Markout\Tests\TestCase;

final class PluginTest extends TestCase
{
    public function test_boot_registers_hooks(): void
    {
        Functions\expect('add_action')->atLeast()->once();

        $plugin = new Plugin(sys_get_temp_dir() . '/markout-plugin-test-' . uniqid('', true));

        $plugin->boot();

        self::assertTrue(true);
    }
}
