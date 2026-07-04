# Markout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Markout WordPress plugin — serves a YAML-frontmatter markdown version of any post/page at `/slug/md` (pretty permalinks) or `?p=123&md` (plain permalinks), backed by a file cache that regenerates asynchronously on save.

**Architecture:** PSR-4 autoloaded, interface-driven classes with manual constructor DI (no container). Every cross-class collaboration is typed to a named interface — no bare `\Closure` parameters. Wiring happens once, in `Plugin.php`. See spec: [docs/superpowers/specs/2026-07-04-markout-design.md](../specs/2026-07-04-markout-design.md).

**Tech Stack:** PHP 8.1+, Composer/PSR-4, `league/html-to-markdown`, `woocommerce/action-scheduler`, PHPUnit 9 + Brain Monkey (unit tests, no full WP test suite), PHPStan level 8, PHPCS PSR-12.

**Revision history:**
- **v2** (after round-1 review): fixed a cache-security leak where password-protected posts were being cached in the public uploads directory; replaced bare `\Closure` collaborators with named interfaces; made cache writes atomic; made `uninstall.php`'s recursive delete symlink-safe; made missing-dependency handling actually auto-deactivate the plugin.
- **v4** (after round-3 review, this version): closed a non-atomic race in `Plugin::maybeScheduleBackfill()` — a `get_option()`-then-`update_option()` guard could let two near-simultaneous requests both schedule an overlapping backfill chain; replaced with a single atomic `add_option()` insert, which can only succeed once. Also added a `transition_post_status` hook to `ActionSchedulerRegenerator` as an authoritative fallback for purging the cache on unpublish/trash, since `save_post` is not guaranteed to fire on every status change on every WordPress version (this had been flagged as a manual-QA-only caveat twice before being fixed directly).
- **v3** (after round-2 review): **removed a `current_user_can('read_post', ...)` capability check that was itself a functional regression** — it broke `/md` access for every anonymous visitor on every public post, because WordPress's `read_post` meta capability resolves to the `read` primitive capability, which logged-out users never have, even for fully public content. The existing `is_singular()` gate (upstream, in `EndpointRouter`) plus `post_password_required()` (inside `MarkdownResponder`) already correctly mirror WordPress's own visibility rules, per the spec — no additional capability check was needed. Also: extracted a `PostCacher` to eliminate duplicated password-check-then-generate-then-write logic between `ActionSchedulerRegenerator` and `BackfillScheduler`; added a `MarkdownRespondingInterface` so `MarkdownRequestHandler` depends on an interface rather than a concrete class; moved backfill scheduling out of the activation hook (where Action Scheduler's data store may not have finished migrating yet on a brand-new install) into a persistent-flag-guarded check on every `plugins_loaded`, which also consolidates the spec's "activation self-check" behavior into one place; fixed `FrontmatterBuilder` to escape backslashes as well as quotes (otherwise a title containing a literal backslash produced invalid YAML).

## Global Constraints

- PHP minimum version: `8.1`.
- Namespace root: `Markout\`, PSR-4 mapped to `src/`.
- Coding standard: PSR-12, enforced via `phpcs`.
- Static analysis: PHPStan level 8, zero errors, using `szepeviktor/phpstan-wordpress` stubs.
- Every swappable concern is defined behind an interface; concrete classes are `final`.
- Cross-class collaboration is always typed to a named interface — never a bare `\Closure` — so every collaborator is independently fakeable in tests.
- `declare(strict_types=1);` at the top of every PHP file in `src/`.
- No database storage for the markdown cache — file-based only, under `wp-content/uploads/markout/`.
- Scope is Posts and Pages only (`post`, `page` post types).
- **The cache is never written — and any existing entry is deleted — for password-protected posts, regardless of `post_status`.** The uploads directory is web-accessible; a cached file for a password-protected post would be readable by a direct HTTP GET, bypassing WordPress's own password gate entirely. This policy lives in exactly one place, `PostCacher`, so it can't drift out of sync between the save-triggered path and the backfill path.
- **No capability checks are added on top of `is_singular()` + `post_password_required()`.** Those two checks, done in the right places (routing gate and `MarkdownResponder` respectively), already fully mirror WordPress's own visibility rules. Adding `current_user_can()` on top is not "extra safety" — it actively breaks public access for anonymous visitors, since WordPress's capability system requires the `read` capability even for fully public posts, which logged-out users don't have.
- Tests use Brain Monkey (`Markout\Tests\TestCase` base) wherever WordPress functions are touched; pure-logic classes get plain PHPUnit tests with no WP mocking.
- Code paths that call `header()`/`exit` (`MarkdownRequestHandler`, `markout.php`, `uninstall.php`) are not unit-testable by nature — they're verified via the manual QA task (the final task) instead of automated tests.

---

### Task 1: Project Scaffolding & Tooling

**Files:**
- Create: `composer.json`
- Create: `phpcs.xml`
- Create: `phpstan.neon`
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`
- Create: `tests/TestCase.php`
- Create: `tests/Unit/SmokeTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `Markout\Tests\TestCase` (abstract PHPUnit base class wiring Brain Monkey's `setUp()`/`tearDown()`) — every later unit test that touches WordPress functions extends this. A global `WP_Post` stub class is available to all tests via `tests/bootstrap.php`.

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "ahmadwael/markout",
    "description": "Serve a markdown version of WordPress posts and pages via /md.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1",
        "league/html-to-markdown": "^5.1",
        "woocommerce/action-scheduler": "^3.7"
    },
    "require-dev": {
        "squizlabs/php_codesniffer": "^3.9",
        "phpstan/phpstan": "^1.11",
        "szepeviktor/phpstan-wordpress": "^1.3",
        "phpunit/phpunit": "^9.6",
        "brain/monkey": "^2.6"
    },
    "autoload": {
        "psr-4": {
            "Markout\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Markout\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit",
        "stan": "phpstan analyse",
        "cs": "phpcs"
    }
}
```

- [ ] **Step 2: Run `composer install`**

Run: `composer install`
Expected: dependencies resolve, `vendor/` and `composer.lock` are created, no errors.

- [ ] **Step 3: Create `phpcs.xml`**

PSR-12's default method-naming sniff requires camelCase, which conflicts with PHPUnit's idiomatic `test_snake_case` naming used throughout this plan's test files. It's excluded for `tests/*` only — `src/` production code still enforces camelCase. `tests/bootstrap.php` is excluded entirely, since its global (non-namespaced) `WP_Post` stub class is inherently incompatible with PSR-12's namespacing requirement and isn't "our" code style surface.

```xml
<?xml version="1.0"?>
<ruleset name="Markout">
    <arg name="basepath" value="."/>
    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <file>src</file>
    <file>tests</file>
    <exclude-pattern>tests/bootstrap\.php$</exclude-pattern>
    <rule ref="PSR12"/>
    <rule ref="PSR1.Methods.CamelCapsMethodName">
        <exclude-pattern>tests/*</exclude-pattern>
    </rule>
</ruleset>
```

- [ ] **Step 4: Create `phpstan.neon`**

`bootstrapFiles` includes Action Scheduler's `functions.php` (needed from Task 11 onward, where the code first calls `as_*` functions). PHPStan doesn't discover global functions the way Composer's own autoloader does at runtime — Composer's "files" autoload entries make a function callable at runtime, but PHPStan's static analysis only knows a function exists if it's told to scan the file declaring it, via `bootstrapFiles` or `scanFiles`. Without this, PHPStan reports "function not found" for every `as_*` call even though the code runs correctly.

```yaml
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 8
    paths:
        - src
    bootstrapFiles:
        - vendor/woocommerce/action-scheduler/functions.php
```

- [ ] **Step 5: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 6: Create `tests/bootstrap.php`**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_content = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public int $post_author = 0;
        public string $post_password = '';
    }
}
```

- [ ] **Step 7: Create `tests/TestCase.php`**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
```

- [ ] **Step 8: Write the smoke test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit;

use Markout\Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function test_test_harness_boots(): void
    {
        self::assertTrue(true);
    }
}
```

- [ ] **Step 9: Run the test suite**

Run: `composer test`
Expected: `OK, all 1 test passes.`

- [ ] **Step 10: Run static analysis and code style checks**

Run: `composer stan`
Expected: `[OK] No errors` (no files in `src/` yet, so this passes trivially).

Run: `composer cs`
Expected: no errors reported.

- [ ] **Step 11: Add `.gitignore` and commit**

```
vendor/
.phpunit.result.cache
```

```bash
git add composer.json phpcs.xml phpstan.neon phpunit.xml.dist tests/bootstrap.php tests/TestCase.php tests/Unit/SmokeTest.php .gitignore composer.lock
git commit -m "Set up Composer, PHPCS, PHPStan, and PHPUnit/Brain Monkey scaffolding"
```

---

### Task 2: PostVisibility

**Files:**
- Create: `src/Support/PostVisibility.php`
- Test: `tests/Unit/Support/PostVisibilityTest.php`

**Interfaces:**
- Consumes: global WP function `post_password_required(\WP_Post $post): bool`.
- Produces: `Markout\Support\PostVisibility` with two methods:
  - `requiresPassword(\WP_Post $post): bool` — session-aware check (accounts for the visitor's password cookie), used by `MarkdownResponder` (Task 7) to decide what to serve on a live request.
  - `hasPassword(\WP_Post $post): bool` — stateless check of `$post->post_password !== ''`, used by `PostCacher` (Task 10) to decide whether a post is eligible for caching at all. A background job has no visitor session, so it must use the stateless check.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Markout\Support\PostVisibility;
use Markout\Tests\TestCase;

final class PostVisibilityTest extends TestCase
{
    public function test_requires_password_returns_true_when_wp_says_so(): void
    {
        Functions\when('post_password_required')->justReturn(true);

        $post = new \WP_Post();
        $visibility = new PostVisibility();

        self::assertTrue($visibility->requiresPassword($post));
    }

    public function test_requires_password_returns_false_when_wp_says_so(): void
    {
        Functions\when('post_password_required')->justReturn(false);

        $post = new \WP_Post();
        $visibility = new PostVisibility();

        self::assertFalse($visibility->requiresPassword($post));
    }

    public function test_has_password_true_when_post_password_is_set(): void
    {
        $post = new \WP_Post();
        $post->post_password = 'secret';

        self::assertTrue((new PostVisibility())->hasPassword($post));
    }

    public function test_has_password_false_when_post_password_is_empty(): void
    {
        $post = new \WP_Post();
        $post->post_password = '';

        self::assertFalse((new PostVisibility())->hasPassword($post));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Support/PostVisibilityTest.php`
Expected: FAIL — `Class "Markout\Support\PostVisibility" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Markout\Support;

final class PostVisibility
{
    public function requiresPassword(\WP_Post $post): bool
    {
        return (bool) post_password_required($post);
    }

    public function hasPassword(\WP_Post $post): bool
    {
        return $post->post_password !== '';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Support/PostVisibilityTest.php`
Expected: `OK, all 4 tests pass.`

- [ ] **Step 5: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Support/PostVisibility.php tests/Unit/Support/PostVisibilityTest.php
git commit -m "Add PostVisibility for password-protection checks"
```

---

### Task 3: CacheInterface + FileCache

**Files:**
- Create: `src/Cache/CacheInterface.php`
- Create: `src/Cache/FileCache.php`
- Test: `tests/Unit/Cache/FileCacheTest.php`

**Interfaces:**
- Produces:
  - `Markout\Cache\CacheInterface` with `get(int $postId): ?string`, `write(int $postId, string $content): bool`, `delete(int $postId): bool`.
  - `Markout\Cache\FileCache implements CacheInterface`, constructor `__construct(string $directory)`.
  - Used by `MarkdownResponder` (Task 7), `PostCacher` (Task 10), `ActionSchedulerRegenerator` (Task 11).

Writes are atomic: content is written to a uniquely-named temp file in the same directory, then moved into place with `rename()` (atomic on the same filesystem). This prevents a concurrent reader (a live request hitting a cache miss) from ever observing a partially-written file while an async regeneration job is mid-write.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Cache;

use Markout\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/markout-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_get_returns_null_when_file_missing(): void
    {
        $cache = new FileCache($this->directory);

        self::assertNull($cache->get(123));
    }

    public function test_write_then_get_round_trips_content(): void
    {
        $cache = new FileCache($this->directory);

        self::assertTrue($cache->write(123, "---\ntitle: \"x\"\n---\n\nBody"));
        self::assertSame("---\ntitle: \"x\"\n---\n\nBody", $cache->get(123));
    }

    public function test_write_leaves_no_temp_files_behind(): void
    {
        $cache = new FileCache($this->directory);
        $cache->write(123, 'content');

        $leftovers = array_filter(
            glob($this->directory . '/*') ?: [],
            static fn (string $path): bool => !str_ends_with($path, '123.md') && !str_ends_with($path, 'index.php')
        );

        self::assertSame([], $leftovers);
    }

    public function test_delete_removes_file_and_returns_true(): void
    {
        $cache = new FileCache($this->directory);
        $cache->write(123, 'content');

        self::assertTrue($cache->delete(123));
        self::assertNull($cache->get(123));
    }

    public function test_delete_of_missing_file_returns_true(): void
    {
        $cache = new FileCache($this->directory);

        self::assertTrue($cache->delete(999));
    }

    public function test_constructor_creates_directory_with_index_guard(): void
    {
        new FileCache($this->directory);

        self::assertDirectoryExists($this->directory);
        self::assertFileExists($this->directory . '/index.php');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Cache/FileCacheTest.php`
Expected: FAIL — `Class "Markout\Cache\FileCache" not found`.

- [ ] **Step 3: Write `CacheInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Cache;

interface CacheInterface
{
    public function get(int $postId): ?string;

    public function write(int $postId, string $content): bool;

    public function delete(int $postId): bool;
}
```

- [ ] **Step 4: Write `FileCache`**

```php
<?php

declare(strict_types=1);

namespace Markout\Cache;

final class FileCache implements CacheInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
        $this->ensureDirectoryExists();
    }

    public function get(int $postId): ?string
    {
        $path = $this->path($postId);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function write(int $postId, string $content): bool
    {
        $this->ensureDirectoryExists();

        if (!is_dir($this->directory)) {
            $this->logFailure('Cache directory is not writable: ' . $this->directory);

            return false;
        }

        $path = $this->path($postId);
        $tempPath = $path . '.' . uniqid('tmp', true);

        if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
            $this->logFailure('Failed to write temporary cache file: ' . $tempPath);

            return false;
        }

        if (!rename($tempPath, $path)) {
            $this->logFailure('Failed to move temporary cache file into place: ' . $path);
            @unlink($tempPath);

            return false;
        }

        return true;
    }

    public function delete(int $postId): bool
    {
        $path = $this->path($postId);
        if (!is_file($path)) {
            return true;
        }

        return unlink($path);
    }

    private function path(int $postId): string
    {
        return sprintf('%s/%d.md', $this->directory, $postId);
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0755, true);
        }

        if (!is_dir($this->directory)) {
            return;
        }

        $index = $this->directory . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    private function logFailure(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Markout: ' . $message);
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Cache/FileCacheTest.php`
Expected: `OK, all 6 tests pass.`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Cache/CacheInterface.php src/Cache/FileCache.php tests/Unit/Cache/FileCacheTest.php
git commit -m "Add file-based cache for converted markdown with atomic writes"
```

---

### Task 4: FrontmatterBuilder

**Files:**
- Create: `src/Conversion/FrontmatterBuilder.php`
- Test: `tests/Unit/Conversion/FrontmatterBuilderTest.php`

**Interfaces:**
- Produces: `Markout\Conversion\FrontmatterBuilder::build(array{title:string,date:string,author:string,permalink:string} $meta): string`. Pure function, no WordPress calls. Used by `MarkdownResponder` (Task 7).

Values are escaped for backslashes *and* double quotes (in that order — escaping backslashes first, then quotes, avoids double-escaping the backslashes that the quote-escaping step introduces) so that a title like `C:\Users\test` produces valid double-quoted YAML rather than a malformed escape sequence.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Conversion;

use Markout\Conversion\FrontmatterBuilder;
use PHPUnit\Framework\TestCase;

final class FrontmatterBuilderTest extends TestCase
{
    public function test_build_produces_yaml_block(): void
    {
        $builder = new FrontmatterBuilder();

        $result = $builder->build([
            'title' => 'Hello World',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/hello-world/',
        ]);

        self::assertSame(
            "---\n"
            . "title: \"Hello World\"\n"
            . "date: \"2026-07-04T00:00:00+00:00\"\n"
            . "author: \"Ahmad\"\n"
            . "permalink: \"https://example.com/hello-world/\"\n"
            . "---\n\n",
            $result
        );
    }

    public function test_build_escapes_double_quotes_in_values(): void
    {
        $builder = new FrontmatterBuilder();

        $result = $builder->build([
            'title' => 'He said "hi"',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/x/',
        ]);

        self::assertStringContainsString('title: "He said \\"hi\\""', $result);
    }

    public function test_build_escapes_backslashes_in_values(): void
    {
        $builder = new FrontmatterBuilder();

        $result = $builder->build([
            'title' => 'C:\\Users\\test',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/x/',
        ]);

        self::assertStringContainsString('title: "C:\\\\Users\\\\test"', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Conversion/FrontmatterBuilderTest.php`
Expected: FAIL — `Class "Markout\Conversion\FrontmatterBuilder" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Markout\Conversion;

final class FrontmatterBuilder
{
    /**
     * @param array{title:string,date:string,author:string,permalink:string} $meta
     */
    public function build(array $meta): string
    {
        $lines = ['---'];
        foreach (['title', 'date', 'author', 'permalink'] as $key) {
            $lines[] = sprintf('%s: %s', $key, $this->quote($meta[$key]));
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n\n";
    }

    private function quote(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversion/FrontmatterBuilderTest.php`
Expected: `OK, all 3 tests pass.`

- [ ] **Step 5: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Conversion/FrontmatterBuilder.php tests/Unit/Conversion/FrontmatterBuilderTest.php
git commit -m "Add YAML frontmatter builder"
```

---

### Task 5: ConverterInterface + HtmlToMarkdownConverter

**Files:**
- Create: `src/Conversion/ConverterInterface.php`
- Create: `src/Conversion/HtmlToMarkdownConverter.php`
- Test: `tests/Unit/Conversion/HtmlToMarkdownConverterTest.php`

**Interfaces:**
- Produces:
  - `Markout\Conversion\ConverterInterface` with `convert(string $html): string`.
  - `Markout\Conversion\HtmlToMarkdownConverter implements ConverterInterface`, constructor `__construct(?\League\HTMLToMarkdown\HtmlConverter $converter = null)`.
  - Used by `MarkdownResponder` (Task 7).

**Note on the test below:** the installed `league/html-to-markdown` (`^5.1`) declares `HtmlConverter::convert()` as `public function convert(string $html): string` — fully typed. The forcing-a-throw subclass below overrides it with the identical signature. (An earlier draft of this plan assumed the parameter was untyped based on a check against a different version/branch; always verify a third-party library's actual installed signature — e.g. `grep -n "function convert" vendor/league/html-to-markdown/src/HtmlConverter.php` — rather than trusting a cached assumption, since overriding a *typed* parent parameter with a mismatched type is a fatal "declaration must be compatible" error at class-load time, not a test failure.)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Conversion;

use League\HTMLToMarkdown\HtmlConverter;
use Markout\Conversion\HtmlToMarkdownConverter;
use PHPUnit\Framework\TestCase;

final class HtmlToMarkdownConverterTest extends TestCase
{
    public function test_convert_turns_html_into_markdown(): void
    {
        $converter = new HtmlToMarkdownConverter();

        $result = $converter->convert('<p>Hello <strong>World</strong></p>');

        self::assertSame('Hello **World**', trim($result));
    }

    public function test_convert_falls_back_to_stripped_tags_on_failure(): void
    {
        $throwingConverter = new class (['strip_tags' => true]) extends HtmlConverter {
            public function convert(string $html): string
            {
                throw new \RuntimeException('boom');
            }
        };

        $converter = new HtmlToMarkdownConverter($throwingConverter);

        $result = $converter->convert('<p>Hello <strong>World</strong></p>');

        self::assertSame('Hello World', trim($result));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Conversion/HtmlToMarkdownConverterTest.php`
Expected: FAIL — `Class "Markout\Conversion\HtmlToMarkdownConverter" not found`.

- [ ] **Step 3: Write `ConverterInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Conversion;

interface ConverterInterface
{
    public function convert(string $html): string;
}
```

- [ ] **Step 4: Write `HtmlToMarkdownConverter`**

```php
<?php

declare(strict_types=1);

namespace Markout\Conversion;

use League\HTMLToMarkdown\HtmlConverter;

final class HtmlToMarkdownConverter implements ConverterInterface
{
    private HtmlConverter $converter;

    public function __construct(?HtmlConverter $converter = null)
    {
        $this->converter = $converter ?? new HtmlConverter(['strip_tags' => true]);
    }

    public function convert(string $html): string
    {
        try {
            return $this->converter->convert($html);
        } catch (\Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Markout: HTML to Markdown conversion failed: %s', $exception->getMessage()));
            }

            return $this->fallback($html);
        }
    }

    private function fallback(string $html): string
    {
        if (function_exists('wp_strip_all_tags')) {
            return wp_strip_all_tags($html);
        }

        return trim(strip_tags($html));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversion/HtmlToMarkdownConverterTest.php`
Expected: `OK, all 2 tests pass.`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Conversion/ConverterInterface.php src/Conversion/HtmlToMarkdownConverter.php tests/Unit/Conversion/HtmlToMarkdownConverterTest.php
git commit -m "Add HTML to Markdown converter with strip-tags fallback"
```

---

### Task 6: PostMetaExtractorInterface + PostMetaExtractor

**Files:**
- Create: `src/Support/PostMetaExtractorInterface.php`
- Create: `src/Support/PostMetaExtractor.php`
- Test: `tests/Unit/Support/PostMetaExtractorTest.php`

**Interfaces:**
- Produces: `Markout\Support\PostMetaExtractorInterface::extract(\WP_Post $post): array` (returns `array{title:string,date:string,author:string,permalink:string}`), implemented by `PostMetaExtractor` (wraps `get_the_title`/`get_the_date`/`get_the_author_meta`/`get_permalink`). Used by `MarkdownRequestHandler` (Task 8) and `PostCacher` (Task 10), both wired once in `Plugin` (Task 13). Extracting this into its own class keeps `Plugin` a pure composition root with no WordPress-function calls of its own.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Markout\Support\PostMetaExtractor;
use Markout\Tests\TestCase;

final class PostMetaExtractorTest extends TestCase
{
    public function test_extract_reads_expected_wordpress_functions(): void
    {
        Functions\when('get_the_title')->justReturn('Hello World');
        Functions\when('get_the_date')->justReturn('2026-07-04T00:00:00+00:00');
        Functions\when('get_the_author_meta')->justReturn('Ahmad');
        Functions\when('get_permalink')->justReturn('https://example.com/hello-world/');

        $post = new \WP_Post();
        $post->post_author = 1;

        $meta = (new PostMetaExtractor())->extract($post);

        self::assertSame([
            'title' => 'Hello World',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/hello-world/',
        ], $meta);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Support/PostMetaExtractorTest.php`
Expected: FAIL — `Class "Markout\Support\PostMetaExtractor" not found`.

- [ ] **Step 3: Write `PostMetaExtractorInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Support;

interface PostMetaExtractorInterface
{
    /**
     * @return array{title:string,date:string,author:string,permalink:string}
     */
    public function extract(\WP_Post $post): array;
}
```

- [ ] **Step 4: Write `PostMetaExtractor`**

```php
<?php

declare(strict_types=1);

namespace Markout\Support;

final class PostMetaExtractor implements PostMetaExtractorInterface
{
    public function extract(\WP_Post $post): array
    {
        return [
            'title' => (string) get_the_title($post),
            'date' => (string) get_the_date('c', $post),
            'author' => (string) get_the_author_meta('display_name', (int) $post->post_author),
            'permalink' => (string) get_permalink($post),
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Support/PostMetaExtractorTest.php`
Expected: `OK, all 1 test passes.`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Support/PostMetaExtractorInterface.php src/Support/PostMetaExtractor.php tests/Unit/Support/PostMetaExtractorTest.php
git commit -m "Add PostMetaExtractor for pulling frontmatter source data from WordPress"
```

---

### Task 7: MarkdownGeneratorInterface + MarkdownRespondingInterface + Http\Response + MarkdownResponder

**Files:**
- Create: `src/Conversion/MarkdownGeneratorInterface.php`
- Create: `src/Http/MarkdownRespondingInterface.php`
- Create: `src/Http/Response.php`
- Create: `src/Http/MarkdownResponder.php`
- Test: `tests/Unit/Http/MarkdownResponderTest.php`

**Interfaces:**
- Consumes: `CacheInterface` (Task 3), `ConverterInterface` (Task 5), `FrontmatterBuilder` (Task 4), `PostVisibility` (Task 2).
- Produces:
  - `Markout\Conversion\MarkdownGeneratorInterface::generate(\WP_Post $post, array $meta): string` — implemented by `MarkdownResponder`. This is the seam the caching/regeneration path (`PostCacher`, Task 10) depends on, so it never needs to know about caching or visibility, only "turn this post into markdown."
  - `Markout\Http\MarkdownRespondingInterface::respond(\WP_Post $post, array $meta): Response` — also implemented by `MarkdownResponder`. This is the seam `MarkdownRequestHandler` (Task 8) depends on for the full live-request flow (password gate, cache check, generate-and-cache-on-miss). Splitting this from `MarkdownGeneratorInterface` keeps each consumer typed only to the behavior it actually needs, and keeps `MarkdownResponder` itself fakeable in `MarkdownRequestHandler`'s tests.
  - `Markout\Http\Response` — readonly value object: `int $status`, `string $contentType`, `string $body`.
  - `Markout\Http\MarkdownResponder implements MarkdownGeneratorInterface, MarkdownRespondingInterface`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Http;

use Brain\Monkey\Functions;
use Markout\Cache\CacheInterface;
use Markout\Conversion\ConverterInterface;
use Markout\Conversion\FrontmatterBuilder;
use Markout\Http\MarkdownResponder;
use Markout\Support\PostVisibility;
use Markout\Tests\TestCase;

final class MarkdownResponderTest extends TestCase
{
    private function meta(): array
    {
        return [
            'title' => 'Hello',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/hello/',
        ];
    }

    public function test_respond_returns_403_when_password_required(): void
    {
        Functions\when('post_password_required')->justReturn(true);

        $responder = new MarkdownResponder(
            $this->cacheReturning(null),
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond(new \WP_Post(), $this->meta());

        self::assertSame(403, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->contentType);
    }

    public function test_respond_returns_cached_content_on_hit(): void
    {
        Functions\when('post_password_required')->justReturn(false);

        $responder = new MarkdownResponder(
            $this->cacheReturning('cached markdown'),
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond(new \WP_Post(), $this->meta());

        self::assertSame(200, $response->status);
        self::assertSame('text/markdown; charset=utf-8', $response->contentType);
        self::assertSame('cached markdown', $response->body);
    }

    public function test_respond_generates_and_writes_to_cache_on_miss(): void
    {
        Functions\when('post_password_required')->justReturn(false);

        $cache = new class implements CacheInterface {
            public array $writes = [];

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
                return true;
            }
        };

        $post = new \WP_Post();
        $post->ID = 42;

        $responder = new MarkdownResponder(
            $cache,
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond($post, $this->meta());

        self::assertSame(200, $response->status);
        self::assertStringContainsString('BODY', $response->body);
        self::assertCount(1, $cache->writes);
        self::assertSame(42, $cache->writes[0][0]);
    }

    public function test_respond_does_not_cache_when_post_has_password_even_if_visitor_is_authorized(): void
    {
        // Visitor has entered the correct password (wp-postpass cookie set),
        // so post_password_required() returns false — but the post itself
        // is still password-protected.
        Functions\when('post_password_required')->justReturn(false);

        $cache = new class implements CacheInterface {
            public array $writes = [];

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
                return true;
            }
        };

        $post = new \WP_Post();
        $post->ID = 42;
        $post->post_password = 'secret';

        $responder = new MarkdownResponder(
            $cache,
            $this->converterReturning('BODY'),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $response = $responder->respond($post, $this->meta());

        self::assertSame(200, $response->status);
        self::assertStringContainsString('BODY', $response->body);
        self::assertCount(0, $cache->writes);
    }

    private function cacheReturning(?string $value): CacheInterface
    {
        return new class ($value) implements CacheInterface {
            public function __construct(private ?string $value)
            {
            }

            public function get(int $postId): ?string
            {
                return $this->value;
            }

            public function write(int $postId, string $content): bool
            {
                return true;
            }

            public function delete(int $postId): bool
            {
                return true;
            }
        };
    }

    private function converterReturning(string $value): ConverterInterface
    {
        return new class ($value) implements ConverterInterface {
            public function __construct(private string $value)
            {
            }

            public function convert(string $html): string
            {
                return $this->value;
            }
        };
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Http/MarkdownResponderTest.php`
Expected: FAIL — `Class "Markout\Http\Response" not found`.

- [ ] **Step 3: Write `MarkdownGeneratorInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Conversion;

interface MarkdownGeneratorInterface
{
    /**
     * @param array{title:string,date:string,author:string,permalink:string} $meta
     */
    public function generate(\WP_Post $post, array $meta): string;
}
```

- [ ] **Step 4: Write `Response`**

```php
<?php

declare(strict_types=1);

namespace Markout\Http;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body
    ) {
    }
}
```

- [ ] **Step 5: Write `MarkdownRespondingInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Http;

interface MarkdownRespondingInterface
{
    /**
     * @param array{title:string,date:string,author:string,permalink:string} $meta
     */
    public function respond(\WP_Post $post, array $meta): Response;
}
```

- [ ] **Step 6: Write `MarkdownResponder`**

```php
<?php

declare(strict_types=1);

namespace Markout\Http;

use Markout\Cache\CacheInterface;
use Markout\Conversion\ConverterInterface;
use Markout\Conversion\FrontmatterBuilder;
use Markout\Conversion\MarkdownGeneratorInterface;
use Markout\Support\PostVisibility;

final class MarkdownResponder implements MarkdownGeneratorInterface, MarkdownRespondingInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ConverterInterface $converter,
        private readonly FrontmatterBuilder $frontmatter,
        private readonly PostVisibility $visibility
    ) {
    }

    /**
     * @param array{title:string,date:string,author:string,permalink:string} $meta
     */
    public function respond(\WP_Post $post, array $meta): Response
    {
        if ($this->visibility->requiresPassword($post)) {
            return new Response(403, 'text/plain; charset=utf-8', 'This content is password protected.');
        }

        $cached = $this->cache->get((int) $post->ID);
        if ($cached !== null) {
            return new Response(200, 'text/markdown; charset=utf-8', $cached);
        }

        $markdown = $this->generate($post, $meta);
        if (!$this->visibility->hasPassword($post)) {
            $this->cache->write((int) $post->ID, $markdown);
        }

        return new Response(200, 'text/markdown; charset=utf-8', $markdown);
    }

    /**
     * @param array{title:string,date:string,author:string,permalink:string} $meta
     */
    public function generate(\WP_Post $post, array $meta): string
    {
        return $this->frontmatter->build($meta) . $this->converter->convert($post->post_content);
    }
}
```

Note the `hasPassword()` guard around the cache write: `requiresPassword()` (used above for the 403 gate) is session/cookie-aware and returns `false` once a visitor has entered the correct password, but `hasPassword()` is the stateless, property-based check that stays `true` regardless of the visitor's session. A password-authorized visitor must still see the generated markdown, but their request must never be the one that populates the on-disk cache — the uploads directory is web-accessible, so a cached file for a password-protected post would let any anonymous visitor bypass the password entirely via direct HTTP GET. This mirrors the same rule `PostCacher` (Task 10) enforces for the two background caching paths.

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Http/MarkdownResponderTest.php`
Expected: `OK, all 4 tests pass.`

- [ ] **Step 8: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add src/Conversion/MarkdownGeneratorInterface.php src/Http/MarkdownRespondingInterface.php src/Http/Response.php src/Http/MarkdownResponder.php tests/Unit/Http/MarkdownResponderTest.php
git commit -m "Add MarkdownResponder tying visibility, cache, and conversion together"
```

---

### Task 8: MarkdownRequestHandlerInterface + MarkdownRequestHandler

**Files:**
- Create: `src/Http/MarkdownRequestHandlerInterface.php`
- Create: `src/Http/MarkdownRequestHandler.php`

**Interfaces:**
- Consumes: `MarkdownRespondingInterface` (Task 7), `PostMetaExtractorInterface` (Task 6).
- Produces: `Markout\Http\MarkdownRequestHandlerInterface::handle(\WP_Post $post): void`, implemented by `MarkdownRequestHandler`. Used by `EndpointRouter` (Task 9).

This class is the only place that touches raw HTTP output (`header()`, `status_header()`, `exit`). It calls `nocache_headers()` and clears any output buffers before sending, so a page cache or CDN never stores a password-denied response under the `/md` URL, and "headers already sent" corruption is avoided if something upstream already echoed output.

**Why there's no automated test here:** an earlier revision of this class added a `current_user_can('read_post', ...)` capability check "for defense in depth." That check was wrong and has been removed — WordPress's `read_post` meta capability resolves to the `read` primitive capability, which anonymous visitors never have, even for fully public posts, so the check silently broke `/md` access for every logged-out visitor on every public post. The correct visibility gating already happens in `EndpointRouter` (`is_singular()`, upstream) and `MarkdownResponder` (`post_password_required()`), both of which are unit-tested where they live. With the capability check gone, `handle()` has no conditional logic left to unit-test in isolation — it unconditionally calls `respond()` then `emit()`, and `emit()` ends in `header()`/`exit`, which would terminate the PHPUnit process if exercised directly. This mirrors `markout.php` and `uninstall.php`: verified via manual QA (the final task), not automated tests.

- [ ] **Step 1: Write `MarkdownRequestHandlerInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Http;

interface MarkdownRequestHandlerInterface
{
    public function handle(\WP_Post $post): void;
}
```

- [ ] **Step 2: Write `MarkdownRequestHandler`**

```php
<?php

declare(strict_types=1);

namespace Markout\Http;

use Markout\Support\PostMetaExtractorInterface;

final class MarkdownRequestHandler implements MarkdownRequestHandlerInterface
{
    public function __construct(
        private readonly MarkdownRespondingInterface $responder,
        private readonly PostMetaExtractorInterface $metaExtractor
    ) {
    }

    public function handle(\WP_Post $post): void
    {
        $response = $this->responder->respond($post, $this->metaExtractor->extract($post));

        $this->emit($response);
    }

    private function emit(Response $response): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            nocache_headers();
            status_header($response->status);
            header('Content-Type: ' . $response->contentType);
        }

        echo $response->body;
        exit;
    }
}
```

- [ ] **Step 3: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/Http/MarkdownRequestHandlerInterface.php src/Http/MarkdownRequestHandler.php
git commit -m "Add MarkdownRequestHandler for cache-safe HTTP output"
```

---

### Task 9: RouterInterface + EndpointRouter

**Files:**
- Create: `src/Router/RouterInterface.php`
- Create: `src/Router/EndpointRouter.php`
- Test: `tests/Unit/Router/EndpointRouterTest.php`

**Interfaces:**
- Consumes: `MarkdownRequestHandlerInterface` (Task 8).
- Produces:
  - `Markout\Router\RouterInterface::register(): void`.
  - `Markout\Router\EndpointRouter implements RouterInterface`, constructor `__construct(MarkdownRequestHandlerInterface $handler)`, public method `isMarkdownRequest(array $queryVars): bool` (pure, unit-tested directly).

This is the class responsible for the spec's `is_singular(['post', 'page'])` visibility gate — the primary mechanism (alongside `post_password_required()` inside `MarkdownResponder`) that makes Markout's visibility mirror WordPress's own rules, with no additional capability checks layered on top.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Router;

use Brain\Monkey\Functions;
use Markout\Http\MarkdownRequestHandlerInterface;
use Markout\Router\EndpointRouter;
use Markout\Tests\TestCase;

final class EndpointRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp']);
        parent::tearDown();
    }

    public function test_is_markdown_request_true_when_md_key_present(): void
    {
        $router = new EndpointRouter($this->fakeHandler());

        self::assertTrue($router->isMarkdownRequest(['md' => '']));
    }

    public function test_is_markdown_request_false_when_md_key_absent(): void
    {
        $router = new EndpointRouter($this->fakeHandler());

        self::assertFalse($router->isMarkdownRequest(['page' => '2']));
    }

    public function test_maybe_respond_invokes_handler_for_matching_singular_post(): void
    {
        $post = new \WP_Post();
        $post->ID = 7;

        $handler = $this->fakeHandler();
        $router = new EndpointRouter($handler);

        $GLOBALS['wp'] = (object) ['query_vars' => ['md' => '']];

        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object')->justReturn($post);

        $router->maybeRespond();

        self::assertSame($post, $handler->handledPost);
    }

    public function test_maybe_respond_does_nothing_when_not_a_markdown_request(): void
    {
        $handler = $this->fakeHandler();
        $router = new EndpointRouter($handler);

        $GLOBALS['wp'] = (object) ['query_vars' => []];

        $router->maybeRespond();

        self::assertNull($handler->handledPost);
    }

    public function test_register_hooks_into_init_and_template_redirect(): void
    {
        Functions\expect('add_action')->twice();

        $router = new EndpointRouter($this->fakeHandler());

        $router->register();
    }

    /**
     * @return MarkdownRequestHandlerInterface&object{handledPost: ?\WP_Post}
     */
    private function fakeHandler(): MarkdownRequestHandlerInterface
    {
        return new class implements MarkdownRequestHandlerInterface {
            public ?\WP_Post $handledPost = null;

            public function handle(\WP_Post $post): void
            {
                $this->handledPost = $post;
            }
        };
    }
}
```

**Note on assertion counts:** `test_register_hooks_into_init_and_template_redirect` verifies its expectation entirely through Brain Monkey's `Functions\expect()->twice()`, which is checked during `Monkey\tearDown()` rather than via a `self::assert*()` call — so it contributes to the "tests" count but not the "assertions" count, and PHPUnit will mark it "risky" (no assertions performed) even though it passes. This is expected and not a failure.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Router/EndpointRouterTest.php`
Expected: FAIL — `Class "Markout\Router\EndpointRouter" not found`.

- [ ] **Step 3: Write `RouterInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Router;

interface RouterInterface
{
    public function register(): void;
}
```

- [ ] **Step 4: Write `EndpointRouter`**

```php
<?php

declare(strict_types=1);

namespace Markout\Router;

use Markout\Http\MarkdownRequestHandlerInterface;

final class EndpointRouter implements RouterInterface
{
    private const ENDPOINT = 'md';
    private const ALLOWED_TYPES = ['post', 'page'];

    public function __construct(private readonly MarkdownRequestHandlerInterface $handler)
    {
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            add_rewrite_endpoint(self::ENDPOINT, EP_PERMALINK);
        });
        add_action('template_redirect', [$this, 'maybeRespond']);
    }

    public function maybeRespond(): void
    {
        $wp = $GLOBALS['wp'] ?? null;
        $queryVars = is_object($wp) && isset($wp->query_vars) ? (array) $wp->query_vars : [];

        if (!$this->isMarkdownRequest($queryVars)) {
            return;
        }

        if (!is_singular(self::ALLOWED_TYPES)) {
            return;
        }

        $post = get_queried_object();
        if (!($post instanceof \WP_Post)) {
            return;
        }

        $this->handler->handle($post);
    }

    /**
     * @param array<string, mixed> $queryVars
     */
    public function isMarkdownRequest(array $queryVars): bool
    {
        return array_key_exists(self::ENDPOINT, $queryVars);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Router/EndpointRouterTest.php`
Expected: `OK, all 5 tests pass (one reported as risky — see note above).`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors. (`EP_PERMALINK` is a WordPress core constant provided by the `phpstan-wordpress` stubs.)

- [ ] **Step 7: Commit**

```bash
git add src/Router/RouterInterface.php src/Router/EndpointRouter.php tests/Unit/Router/EndpointRouterTest.php
git commit -m "Add EndpointRouter registering the /md rewrite endpoint"
```

---

### Task 10: PostCacherInterface + PostCacher

**Files:**
- Create: `src/Scheduler/PostCacherInterface.php`
- Create: `src/Scheduler/PostCacher.php`
- Test: `tests/Unit/Scheduler/PostCacherTest.php`

**Interfaces:**
- Consumes: `CacheInterface` (Task 3), `MarkdownGeneratorInterface` (Task 7), `PostMetaExtractorInterface` (Task 6), `PostVisibility` (Task 2).
- Produces: `Markout\Scheduler\PostCacherInterface::sync(\WP_Post $post): void`, implemented by `PostCacher`. Used by `ActionSchedulerRegenerator` (Task 11) and `BackfillScheduler` (Task 12).

This class exists to hold the cache-or-purge policy — "if the post has a password, delete any cached file and never write one; otherwise generate fresh markdown and write it" — in exactly one place. Both the save-triggered regeneration path and the backfill path need this identical decision; without a shared collaborator, the logic (and the password-safety rule in particular) would need to be duplicated and kept in sync by hand across two classes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Scheduler;

use Markout\Cache\CacheInterface;
use Markout\Conversion\MarkdownGeneratorInterface;
use Markout\Scheduler\PostCacher;
use Markout\Support\PostMetaExtractorInterface;
use Markout\Support\PostVisibility;
use PHPUnit\Framework\TestCase;

final class PostCacherTest extends TestCase
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

    private function fakeGenerator(): MarkdownGeneratorInterface
    {
        return new class implements MarkdownGeneratorInterface {
            public function generate(\WP_Post $post, array $meta): string
            {
                return 'md-' . $post->ID;
            }
        };
    }

    private function fakeMetaExtractor(): PostMetaExtractorInterface
    {
        return new class implements PostMetaExtractorInterface {
            public function extract(\WP_Post $post): array
            {
                return ['title' => '', 'date' => '', 'author' => '', 'permalink' => ''];
            }
        };
    }

    public function test_sync_writes_generated_markdown_for_public_post(): void
    {
        $cache = $this->fakeCache();
        $cacher = new PostCacher($cache, $this->fakeGenerator(), $this->fakeMetaExtractor(), new PostVisibility());

        $post = new \WP_Post();
        $post->ID = 5;
        $post->post_password = '';

        $cacher->sync($post);

        self::assertSame([[5, 'md-5']], $cache->writes);
        self::assertSame([], $cache->deletes);
    }

    public function test_sync_deletes_cache_for_password_protected_post(): void
    {
        $cache = $this->fakeCache();
        $cacher = new PostCacher($cache, $this->fakeGenerator(), $this->fakeMetaExtractor(), new PostVisibility());

        $post = new \WP_Post();
        $post->ID = 5;
        $post->post_password = 'secret';

        $cacher->sync($post);

        self::assertSame([], $cache->writes);
        self::assertSame([5], $cache->deletes);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Scheduler/PostCacherTest.php`
Expected: FAIL — `Class "Markout\Scheduler\PostCacher" not found`.

- [ ] **Step 3: Write `PostCacherInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Scheduler;

interface PostCacherInterface
{
    public function sync(\WP_Post $post): void;
}
```

- [ ] **Step 4: Write `PostCacher`**

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Scheduler/PostCacherTest.php`
Expected: `OK, all 2 tests pass.`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Scheduler/PostCacherInterface.php src/Scheduler/PostCacher.php tests/Unit/Scheduler/PostCacherTest.php
git commit -m "Add PostCacher to unify the cache-or-purge policy"
```

---

### Task 11: RegeneratorInterface + ActionSchedulerRegenerator

**Files:**
- Create: `src/Scheduler/RegeneratorInterface.php`
- Create: `src/Scheduler/ActionSchedulerRegenerator.php`
- Test: `tests/Unit/Scheduler/ActionSchedulerRegeneratorTest.php`

**Interfaces:**
- Consumes: `CacheInterface` (Task 3), `PostVisibility` (Task 2), `PostCacherInterface` (Task 10).
- Produces: `Markout\Scheduler\ActionSchedulerRegenerator implements RegeneratorInterface`, public constant `REGENERATE_HOOK = 'markout_regenerate_md'`, methods `register(): void`, `onSave(int $postId, \WP_Post $post, bool $update): void`, `onDelete(int $postId): void`, `handleRegenerate(int $postId): void`.

`onSave`/`onDelete` decide *whether* to enqueue a regeneration or purge the cache immediately (status/type/revision checks); `handleRegenerate` re-validates the post is still eligible, then delegates the actual cache-or-purge work (including the password check) to `PostCacher::sync()`.

A second hook, `transition_post_status`, is registered as an authoritative fallback for purging: `save_post` is not guaranteed to fire on every status change on every WordPress version (notably, `wp_trash_post()` has not always routed through `wp_insert_post()`/`save_post` in every core release). `transition_post_status` fires on every status change without exception, so `onTransition` re-applies the same "not publish → delete" rule `onSave` already applies, making cache purging on unpublish/trash reliable regardless of which path WordPress core took internally. Calling both hooks for the same event is harmless: `enqueue()` is deduped via `as_has_scheduled_action()`, and `CacheInterface::delete()` is idempotent.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Scheduler/ActionSchedulerRegeneratorTest.php`
Expected: FAIL — `Class "Markout\Scheduler\ActionSchedulerRegenerator" not found`.

- [ ] **Step 3: Write `RegeneratorInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Scheduler;

interface RegeneratorInterface
{
    public function register(): void;
}
```

- [ ] **Step 4: Write `ActionSchedulerRegenerator`**

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Scheduler/ActionSchedulerRegeneratorTest.php`
Expected: `OK, all 11 tests pass (some reported as risky, matching the same expect()-only pattern noted in Task 9).`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Scheduler/RegeneratorInterface.php src/Scheduler/ActionSchedulerRegenerator.php tests/Unit/Scheduler/ActionSchedulerRegeneratorTest.php
git commit -m "Add ActionSchedulerRegenerator for async cache regeneration on save"
```

---

### Task 12: PostFinderInterface + WPQueryPostFinder + BackfillScheduler

**Files:**
- Create: `src/Scheduler/PostFinderInterface.php`
- Create: `src/Scheduler/WPQueryPostFinder.php`
- Create: `src/Scheduler/BackfillScheduler.php`
- Test: `tests/Unit/Scheduler/BackfillSchedulerTest.php`

**Interfaces:**
- Consumes: `PostCacherInterface` (Task 10).
- Produces:
  - `Markout\Scheduler\PostFinderInterface::findPublished(array $postTypes, int $limit, int $offset): array` (returns `\WP_Post[]`).
  - `Markout\Scheduler\WPQueryPostFinder implements PostFinderInterface` — the real `WP_Query`-backed implementation (not unit-tested; it depends on a live `WP_Query`, exercised only via manual QA).
  - `Markout\Scheduler\BackfillScheduler`, public constant `HOOK = 'markout_backfill_batch'`, constructor `__construct(PostFinderInterface $finder, PostCacherInterface $cacher)`, method `runBatch(int $offset): void`.

Every post found in a batch is simply handed to `PostCacher::sync()` — the password-safety rule lives there, not duplicated here. The re-enqueue of the next batch is deduped with `as_has_scheduled_action()`, matching the pattern already used for `save_post` regeneration, so a manually re-triggered backfill can't produce overlapping batch chains.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Scheduler/BackfillSchedulerTest.php`
Expected: FAIL — `Class "Markout\Scheduler\BackfillScheduler" not found`.

- [ ] **Step 3: Write `PostFinderInterface`**

```php
<?php

declare(strict_types=1);

namespace Markout\Scheduler;

interface PostFinderInterface
{
    /**
     * @param string[] $postTypes
     * @return \WP_Post[]
     */
    public function findPublished(array $postTypes, int $limit, int $offset): array;
}
```

- [ ] **Step 4: Write `WPQueryPostFinder`**

```php
<?php

declare(strict_types=1);

namespace Markout\Scheduler;

final class WPQueryPostFinder implements PostFinderInterface
{
    public function findPublished(array $postTypes, int $limit, int $offset): array
    {
        $query = new \WP_Query([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        return array_values(array_filter(
            $query->posts,
            static fn ($post): bool => $post instanceof \WP_Post
        ));
    }
}
```

- [ ] **Step 5: Write `BackfillScheduler`**

```php
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
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Scheduler/BackfillSchedulerTest.php`
Expected: `OK, all 4 tests pass (some reported as risky, matching the same expect()-only pattern noted in Task 9).`

- [ ] **Step 7: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Scheduler/PostFinderInterface.php src/Scheduler/WPQueryPostFinder.php src/Scheduler/BackfillScheduler.php tests/Unit/Scheduler/BackfillSchedulerTest.php
git commit -m "Add BackfillScheduler for populating the cache on activation"
```

---

### Task 13: Plugin Bootstrap Wiring

**Files:**
- Create: `src/Plugin.php`
- Create: `markout.php`
- Test: `tests/Unit/PluginTest.php`

**Interfaces:**
- Consumes: every class from Tasks 2–12.
- Produces: `Markout\Plugin`, constructor `__construct(string $cacheDirectory)`, method `boot(): void`, static methods `activate(): void` and `deactivate(): void`. `Plugin` is a pure composition root — it wires dependencies and holds no WordPress-function-calling logic beyond the small self-check described below. `markout.php` is the WordPress plugin entry file — not unit-tested (requires a loaded WordPress runtime), verified via manual QA (the final task). It self-checks that Composer dependencies and Action Scheduler are actually available, auto-deactivating with an admin notice rather than fataling if not.

**Why backfill scheduling isn't in `activate()`:** `register_activation_hook` callbacks run in the same request as the rest of the plugin's bootstrap, which means Action Scheduler's own initialization (hooked to `plugins_loaded` at the earliest possible priority) has already run by the time `activate()` executes — so `as_*` functions being *callable* isn't in question. But on a brand-new WordPress install where Markout is the only plugin bundling Action Scheduler, its database tables may not have finished their migration by that same request. Scheduling directly from `activate()` risked the backfill action being silently lost. Instead, `Plugin::boot()` (which always runs from the `plugins_loaded` closure in `markout.php`, on every request) calls `maybeScheduleBackfill()`, which schedules the backfill at most once.

**Why `add_option()` rather than `get_option()` + `update_option()`:** the guard needs to survive two near-simultaneous requests both reaching `boot()` before either has recorded that scheduling happened (plausible right after activation, e.g. concurrent admin-dashboard requests). `add_option()` performs an insert against a column with a unique index on `option_name` — if two requests race, only one insert can succeed; the other gets back `false` and returns without scheduling anything. A `get_option()` read followed by a separate `update_option()` write has a window between the two where both requests can pass the read check, which would enqueue two overlapping backfill batch chains. `deactivate()` deletes the option, so a deactivate/reactivate cycle re-triggers a fresh backfill, matching the manual QA expectation in the final task.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/PluginTest.php`
Expected: FAIL — `Class "Markout\Plugin" not found`.

- [ ] **Step 3: Write `Plugin`**

```php
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
```

`$responder` is passed to `PostCacher` as its `MarkdownGeneratorInterface` argument — `MarkdownResponder` implements that interface, so the single instance is reused for both live-request serving and background caching without `PostCacher` needing to know about `MarkdownResponder` itself.

- [ ] **Step 4: Write `markout.php`**

```php
<?php

/**
 * Plugin Name: Markout
 * Description: Serve a markdown version of any post or page via /md.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MARKOUT_PLUGIN_FILE', __FILE__);

function markout_deactivate_with_notice(string $message): void
{
    add_action('admin_notices', static function () use ($message): void {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    });

    add_action('admin_init', static function (): void {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins(plugin_basename(MARKOUT_PLUGIN_FILE));
    });
}

$markoutAutoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($markoutAutoload)) {
    markout_deactivate_with_notice(
        'Markout: missing Composer dependencies. Run composer install in the plugin directory.'
    );

    return;
}

require_once $markoutAutoload;

use Markout\Plugin;

add_action('plugins_loaded', static function (): void {
    if (!function_exists('as_enqueue_async_action')) {
        markout_deactivate_with_notice('Markout: Action Scheduler is unavailable.');

        return;
    }

    $uploadDir = wp_upload_dir();
    $plugin = new Plugin(rtrim((string) $uploadDir['basedir'], '/') . '/markout');
    $plugin->boot();
});

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/PluginTest.php`
Expected: `OK, all 1 test passes.`

- [ ] **Step 6: Run the full test suite**

Run: `composer test`
Expected: all tests across every task pass (some marked "risky" per the notes in Tasks 9, 11, and 12 — this is expected, not a failure).

- [ ] **Step 7: Static analysis and style**

Add `markout.php` to `phpcs.xml`'s file list. Like `tests/bootstrap.php`, it needs its own exclusion: PSR-1's `SideEffects` sniff flags any file that both declares something (the `markout_deactivate_with_notice()` helper, the `MARKOUT_PLUGIN_FILE` constant) and executes side effects (`add_action`, `register_activation_hook`) in the same file — which is simply what a WordPress plugin main file *is*. This is a real false positive for this file's role, not a code smell to fix by restructuring.

```xml
<?xml version="1.0"?>
<ruleset name="Markout">
    <arg name="basepath" value="."/>
    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <file>src</file>
    <file>tests</file>
    <file>markout.php</file>
    <exclude-pattern>tests/bootstrap\.php$</exclude-pattern>
    <rule ref="PSR12"/>
    <rule ref="PSR1.Methods.CamelCapsMethodName">
        <exclude-pattern>tests/*</exclude-pattern>
    </rule>
    <rule ref="PSR1.Files.SideEffects">
        <exclude-pattern>markout\.php$</exclude-pattern>
    </rule>
</ruleset>
```

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Plugin.php markout.php phpcs.xml tests/Unit/PluginTest.php
git commit -m "Wire Plugin bootstrap and add markout.php entry file"
```

---

### Task 14: Uninstall Cleanup

**Files:**
- Create: `uninstall.php`

**Interfaces:**
- Consumes: nothing from `src/` — deliberately standalone per WordPress's uninstall convention. Not unit-tested; verified manually (the final task).

The recursive delete is symlink-safe: every path is checked with `is_link()` *before* `is_dir()`. `is_dir()` follows symlinks and would otherwise cause the function to recurse into (and delete the contents of) a symlinked directory's target rather than just removing the link itself.

- [ ] **Step 1: Write `uninstall.php`**

```php
<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$uploadDir = wp_upload_dir();
$cacheDir = rtrim((string) $uploadDir['basedir'], '/') . '/markout';

$deleteDir = static function (string $dir) use (&$deleteDir): void {
    if (is_link($dir)) {
        unlink($dir);

        return;
    }

    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_link($path)) {
            unlink($path);

            continue;
        }

        is_dir($path) ? $deleteDir($path) : unlink($path);
    }

    rmdir($dir);
};

$deleteDir($cacheDir);

delete_option('markout_backfill_scheduled');

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('markout_regenerate_md', [], 'markout');
    as_unschedule_all_actions('markout_backfill_batch', [], 'markout');
}
```

- [ ] **Step 2: Static analysis and style**

Add `uninstall.php` to `phpcs.xml`'s file list (alongside `markout.php`). It needs the same `PSR1.Files.SideEffects` exclusion `markout.php` needed in Task 13 — widen that rule's existing `exclude-pattern` to cover both files rather than adding a second `<rule>` block:

```xml
<rule ref="PSR1.Files.SideEffects">
    <exclude-pattern>(markout|uninstall)\.php$</exclude-pattern>
</rule>
```

Run: `composer stan && composer cs`
Expected: no errors. (`uninstall.php` isn't under `phpstan.neon`'s `paths`, so it's checked by `phpcs` only — this matches its untestable, WP-lifecycle-only nature.)

- [ ] **Step 3: Commit**

```bash
git add uninstall.php phpcs.xml
git commit -m "Add uninstall cleanup for cache directory and scheduled actions"
```

---

### Task 15: Manual QA

**Files:** none (verification only — this task confirms behavior that automated tests can't reach: full WordPress runtime, rewrite rules, file permissions, real HTTP requests).

- [ ] **Step 1: Install on a local WordPress site**

Copy the `markout` directory into `wp-content/plugins/`, run `composer install --no-dev` inside it (or ship `vendor/` in the release build), then activate the plugin from the WordPress admin.

- [ ] **Step 2: Verify pretty-permalink routing**

With pretty permalinks enabled (Settings → Permalinks → Post name), visit `https://<site>/<published-post-slug>/md`.
Expected: `text/markdown` response with YAML frontmatter followed by the converted body — for a logged-out visitor, not just while logged in as an administrator.

- [ ] **Step 3: Verify plain-permalink routing**

Switch permalinks to "Plain" (Settings → Permalinks), visit `https://<site>/?p=<post-id>&md`.
Expected: same markdown output as Step 2.

- [ ] **Step 4: Verify password-protected posts are denied and never cached**

Password-protect a published post, visit its `/md` URL without supplying the password.
Expected: HTTP 403, body `This content is password protected.`

Then check `wp-content/uploads/markout/<post-id>.md` directly on disk (and via a direct HTTP GET to that path).
Expected: the file does not exist — password-protected content is never written to the public cache directory, on save or via backfill.

- [ ] **Step 5: Verify private posts**

Set a post to "Private", visit its `/md` URL while logged out.
Expected: standard WordPress 404 (unchanged from normal WordPress behavior — Markout adds no visibility bypass).

Visit the same URL logged in as an administrator.
Expected: markdown output, same as a public post.

- [ ] **Step 6: Verify save-triggered regeneration**

Edit and update a published post's content, wait a few seconds for Action Scheduler to run its queue (or trigger it via `wp action-scheduler run` if using WP-CLI), then re-fetch its `/md` URL.
Expected: updated content reflected in the markdown output.

- [ ] **Step 7: Verify trash/unpublish removes cache**

Move a published post to Trash, then check `wp-content/uploads/markout/<post-id>.md` on disk.
Expected: file no longer exists. `ActionSchedulerRegenerator` purges the cache from both `save_post` and `transition_post_status`, so this holds regardless of which internal path `wp_trash_post()` takes on the WordPress version under test.

- [ ] **Step 8: Verify uploads-directory-unwritable fallback**

Temporarily `chmod` the `wp-content/uploads/markout/` directory (or its parent, before creation) to remove write permission, request a `/md` URL for a post with no existing cache file.
Expected: markdown is still served (generated on the fly); no fatal error; restore permissions afterward.

- [ ] **Step 9: Verify activation backfill, including re-trigger on reactivation**

On a site with several existing published posts/pages, activate the plugin, wait for the Action Scheduler queue to process, then check `wp-content/uploads/markout/`.
Expected: a `.md` file exists for every published post and page (and none for any password-protected ones).

Then deactivate and reactivate the plugin.
Expected: the backfill runs again (the `markout_backfill_scheduled` option is cleared on deactivation specifically so reactivation re-triggers it).

- [ ] **Step 10: Verify missing-dependency self-deactivation**

Temporarily rename `vendor/` (simulating a skipped `composer install`), then activate the plugin.
Expected: an admin notice appears ("Markout: missing Composer dependencies...") and the plugin is automatically deactivated — it does not remain active in a broken state, and no fatal error appears in the debug log. Restore `vendor/` afterward.

- [ ] **Step 11: Verify uninstall cleanup**

Deactivate and delete the plugin through the WordPress admin (triggers `uninstall.php`).
Expected: `wp-content/uploads/markout/` directory is gone; the `markout_backfill_scheduled` option is gone; no PHP errors/warnings in the site's debug log.

- [ ] **Step 12: Record results**

Note any deviations from expected results directly in this plan file or a follow-up issue before considering the plugin ready to ship.
