# Markout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Markout WordPress plugin — serves a YAML-frontmatter markdown version of any post/page at `/slug/md` (pretty permalinks) or `?p=123&md` (plain permalinks), backed by a file cache that regenerates asynchronously on save.

**Architecture:** PSR-4 autoloaded, interface-driven classes with manual constructor DI (no container). Each concern (visibility, cache, conversion, routing, regeneration, backfill) is its own class behind an interface where it has more than one plausible implementation, wired together in `Plugin.php`. See spec: [docs/superpowers/specs/2026-07-04-markout-design.md](../specs/2026-07-04-markout-design.md).

**Tech Stack:** PHP 8.1+, Composer/PSR-4, `league/html-to-markdown`, `woocommerce/action-scheduler`, PHPUnit 9 + Brain Monkey (unit tests, no full WP test suite), PHPStan level 8, PHPCS PSR-12.

## Global Constraints

- PHP minimum version: `8.1` (spec requires modern typed/readonly properties).
- Namespace root: `Markout\`, PSR-4 mapped to `src/`.
- Coding standard: PSR-12, enforced via `phpcs`.
- Static analysis: PHPStan level 8, zero errors, using `szepeviktor/phpstan-wordpress` stubs.
- Every swappable concern (cache, converter, router, regenerator) is defined behind an interface; concrete classes are `final`.
- `declare(strict_types=1);` at the top of every PHP file in `src/`.
- No database storage for the markdown cache — file-based only, under `wp-content/uploads/markout/`.
- Scope is Posts and Pages only (`post`, `page` post types) — no custom post types in this plan.
- Tests use Brain Monkey (`Markout\Tests\TestCase` base) only where WordPress functions are called; pure-logic classes get plain PHPUnit tests with no WP mocking.

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

```xml
<?xml version="1.0"?>
<ruleset name="Markout">
    <arg name="basepath" value="."/>
    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <file>src</file>
    <file>tests</file>
    <rule ref="PSR12"/>
</ruleset>
```

- [ ] **Step 4: Create `phpstan.neon`**

```yaml
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 8
    paths:
        - src
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
Expected: `OK (1 test, 1 assertion)`

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
- Produces: `Markout\Support\PostVisibility::requiresPassword(\WP_Post $post): bool` — used by `MarkdownResponder` (Task 6).

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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Support/PostVisibilityTest.php`
Expected: `OK (2 tests, 2 assertions)`

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
  - Used by `MarkdownResponder` (Task 6), `ActionSchedulerRegenerator` (Task 8), `BackfillScheduler` (Task 9).

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

        if (file_put_contents($this->path($postId), $content) === false) {
            $this->logFailure('Failed to write cache file: ' . $this->path($postId));

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
Expected: `OK (5 tests, 7 assertions)`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Cache/CacheInterface.php src/Cache/FileCache.php tests/Unit/Cache/FileCacheTest.php
git commit -m "Add file-based cache for converted markdown"
```

---

### Task 4: FrontmatterBuilder

**Files:**
- Create: `src/Conversion/FrontmatterBuilder.php`
- Test: `tests/Unit/Conversion/FrontmatterBuilderTest.php`

**Interfaces:**
- Produces: `Markout\Conversion\FrontmatterBuilder::build(array{title:string,date:string,author:string,permalink:string} $meta): string`. Pure function, no WordPress calls. Used by `MarkdownResponder` (Task 6).

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
        return '"' . str_replace('"', '\\"', $value) . '"';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversion/FrontmatterBuilderTest.php`
Expected: `OK (2 tests, 2 assertions)`

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
  - Used by `MarkdownResponder` (Task 6).

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
Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Conversion/ConverterInterface.php src/Conversion/HtmlToMarkdownConverter.php tests/Unit/Conversion/HtmlToMarkdownConverterTest.php
git commit -m "Add HTML to Markdown converter with strip-tags fallback"
```

---

### Task 6: Http\Response + MarkdownResponder

**Files:**
- Create: `src/Http/Response.php`
- Create: `src/Http/MarkdownResponder.php`
- Test: `tests/Unit/Http/MarkdownResponderTest.php`

**Interfaces:**
- Consumes: `CacheInterface` (Task 3), `ConverterInterface` (Task 5), `FrontmatterBuilder` (Task 4), `PostVisibility` (Task 2).
- Produces:
  - `Markout\Http\Response` — readonly value object: `int $status`, `string $contentType`, `string $body`.
  - `Markout\Http\MarkdownResponder::respond(\WP_Post $post, array $meta): Response` and `::generate(\WP_Post $post, array $meta): string` — `$meta` is `array{title:string,date:string,author:string,permalink:string}`. Used by `EndpointRouter` (Task 7), `ActionSchedulerRegenerator` (Task 8), `BackfillScheduler` (Task 9), and `Plugin` (Task 10).

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

        $writes = [];
        $cache = new class ($writes) implements CacheInterface {
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

- [ ] **Step 3: Write `Response`**

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

- [ ] **Step 4: Write `MarkdownResponder`**

```php
<?php

declare(strict_types=1);

namespace Markout\Http;

use Markout\Cache\CacheInterface;
use Markout\Conversion\ConverterInterface;
use Markout\Conversion\FrontmatterBuilder;
use Markout\Support\PostVisibility;

final class MarkdownResponder
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
        $this->cache->write((int) $post->ID, $markdown);

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

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Http/MarkdownResponderTest.php`
Expected: `OK (3 tests, 8 assertions)`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Http/Response.php src/Http/MarkdownResponder.php tests/Unit/Http/MarkdownResponderTest.php
git commit -m "Add MarkdownResponder tying visibility, cache, and conversion together"
```

---

### Task 7: RouterInterface + EndpointRouter

**Files:**
- Create: `src/Router/RouterInterface.php`
- Create: `src/Router/EndpointRouter.php`
- Test: `tests/Unit/Router/EndpointRouterTest.php`

**Interfaces:**
- Consumes: a `\Closure` callback `fn(\WP_Post $post): void` (the "on match" handler, supplied by `Plugin` in Task 10).
- Produces:
  - `Markout\Router\RouterInterface::register(): void`.
  - `Markout\Router\EndpointRouter implements RouterInterface`, constructor `__construct(\Closure $onMatch)`, public method `isMarkdownRequest(array $queryVars): bool` (pure, unit-tested directly).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Router;

use Markout\Router\EndpointRouter;
use PHPUnit\Framework\TestCase;

final class EndpointRouterTest extends TestCase
{
    public function test_is_markdown_request_true_when_md_key_present(): void
    {
        $router = new EndpointRouter(static function (): void {
        });

        self::assertTrue($router->isMarkdownRequest(['md' => '']));
    }

    public function test_is_markdown_request_false_when_md_key_absent(): void
    {
        $router = new EndpointRouter(static function (): void {
        });

        self::assertFalse($router->isMarkdownRequest(['page' => '2']));
    }

    public function test_maybe_respond_invokes_callback_for_matching_singular_post(): void
    {
        $post = new \WP_Post();
        $post->ID = 7;
        $invokedWith = null;

        $router = new EndpointRouter(function (\WP_Post $matched) use (&$invokedWith): void {
            $invokedWith = $matched;
        });

        $GLOBALS['wp'] = (object) ['query_vars' => ['md' => '']];

        \Brain\Monkey\setUp();
        \Brain\Monkey\Functions\when('is_singular')->justReturn(true);
        \Brain\Monkey\Functions\when('get_queried_object')->justReturn($post);

        $router->maybeRespond();

        \Brain\Monkey\tearDown();
        unset($GLOBALS['wp']);

        self::assertSame($post, $invokedWith);
    }

    public function test_maybe_respond_does_nothing_when_not_a_markdown_request(): void
    {
        $invoked = false;

        $router = new EndpointRouter(function () use (&$invoked): void {
            $invoked = true;
        });

        $GLOBALS['wp'] = (object) ['query_vars' => []];

        $router->maybeRespond();

        unset($GLOBALS['wp']);

        self::assertFalse($invoked);
    }
}
```

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

final class EndpointRouter implements RouterInterface
{
    private const ENDPOINT = 'md';
    private const ALLOWED_TYPES = ['post', 'page'];

    public function __construct(private readonly \Closure $onMatch)
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

        ($this->onMatch)($post);
    }

    public function isMarkdownRequest(array $queryVars): bool
    {
        return array_key_exists(self::ENDPOINT, $queryVars);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Router/EndpointRouterTest.php`
Expected: `OK (4 tests, 4 assertions)`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors. (`EP_PERMALINK` is a WordPress core constant provided by the `phpstan-wordpress` stubs.)

- [ ] **Step 7: Commit**

```bash
git add src/Router/RouterInterface.php src/Router/EndpointRouter.php tests/Unit/Router/EndpointRouterTest.php
git commit -m "Add EndpointRouter registering the /md rewrite endpoint"
```

---

### Task 8: RegeneratorInterface + ActionSchedulerRegenerator

**Files:**
- Create: `src/Scheduler/RegeneratorInterface.php`
- Create: `src/Scheduler/ActionSchedulerRegenerator.php`
- Test: `tests/Unit/Scheduler/ActionSchedulerRegeneratorTest.php`

**Interfaces:**
- Consumes: `CacheInterface` (Task 3), a `\Closure` `fn(\WP_Post $post): string` (regeneration callback, supplied by `Plugin` in Task 10).
- Produces: `Markout\Scheduler\ActionSchedulerRegenerator implements RegeneratorInterface`, public constant `REGENERATE_HOOK = 'markout_regenerate_md'`, methods `register(): void`, `onSave(int $postId, \WP_Post $post, bool $update): void`, `onDelete(int $postId): void`, `handleRegenerate(int $postId): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Scheduler;

use Brain\Monkey\Functions;
use Markout\Cache\CacheInterface;
use Markout\Scheduler\ActionSchedulerRegenerator;
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

    public function test_on_save_enqueues_when_publish_and_allowed_type(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\expect('as_enqueue_async_action')
            ->once()
            ->with(ActionSchedulerRegenerator::REGENERATE_HOOK, [5], 'markout');

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, static fn (\WP_Post $post): string => 'x');

        $post = new \WP_Post();
        $post->post_type = 'post';
        $post->post_status = 'publish';

        $regenerator->onSave(5, $post, true);
    }

    public function test_on_save_deletes_cache_when_not_publish(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, static fn (\WP_Post $post): string => 'x');

        $post = new \WP_Post();
        $post->post_type = 'post';
        $post->post_status = 'draft';

        $regenerator->onSave(5, $post, true);

        self::assertSame([5], $cache->deletes);
    }

    public function test_on_save_skips_revisions(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(true);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, static fn (\WP_Post $post): string => 'x');

        $post = new \WP_Post();
        $post->post_type = 'post';
        $post->post_status = 'publish';

        $regenerator->onSave(5, $post, true);

        self::assertSame([], $cache->deletes);
        self::assertSame([], $cache->writes);
    }

    public function test_on_delete_removes_cache(): void
    {
        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, static fn (\WP_Post $post): string => 'x');

        $regenerator->onDelete(9);

        self::assertSame([9], $cache->deletes);
    }

    public function test_handle_regenerate_writes_cache_for_valid_post(): void
    {
        $post = new \WP_Post();
        $post->ID = 3;
        $post->post_type = 'page';
        $post->post_status = 'publish';

        Functions\when('get_post')->justReturn($post);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, static fn (\WP_Post $p): string => 'regenerated-markdown');

        $regenerator->handleRegenerate(3);

        self::assertSame([[3, 'regenerated-markdown']], $cache->writes);
    }

    public function test_handle_regenerate_deletes_cache_when_post_no_longer_valid(): void
    {
        Functions\when('get_post')->justReturn(null);

        $cache = $this->fakeCache();
        $regenerator = new ActionSchedulerRegenerator($cache, static fn (\WP_Post $p): string => 'x');

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

final class ActionSchedulerRegenerator implements RegeneratorInterface
{
    public const REGENERATE_HOOK = 'markout_regenerate_md';
    private const ALLOWED_TYPES = ['post', 'page'];
    private const GROUP = 'markout';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly \Closure $regenerate
    ) {
    }

    public function register(): void
    {
        add_action('save_post', [$this, 'onSave'], 10, 3);
        add_action('before_delete_post', [$this, 'onDelete']);
        add_action(self::REGENERATE_HOOK, [$this, 'handleRegenerate']);
    }

    public function onSave(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if (!in_array($post->post_type, self::ALLOWED_TYPES, true)) {
            return;
        }

        if ($post->post_status === 'publish') {
            $this->enqueue($postId);

            return;
        }

        $this->cache->delete($postId);
    }

    public function onDelete(int $postId): void
    {
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

        $markdown = ($this->regenerate)($post);
        $this->cache->write($postId, $markdown);
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
Expected: `OK (6 tests, 8 assertions)`

- [ ] **Step 6: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Scheduler/RegeneratorInterface.php src/Scheduler/ActionSchedulerRegenerator.php tests/Unit/Scheduler/ActionSchedulerRegeneratorTest.php
git commit -m "Add ActionSchedulerRegenerator for async cache regeneration on save"
```

---

### Task 9: PostFinderInterface + WPQueryPostFinder + BackfillScheduler

**Files:**
- Create: `src/Scheduler/PostFinderInterface.php`
- Create: `src/Scheduler/WPQueryPostFinder.php`
- Create: `src/Scheduler/BackfillScheduler.php`
- Test: `tests/Unit/Scheduler/BackfillSchedulerTest.php`

**Interfaces:**
- Consumes: `CacheInterface` (Task 3), a `\Closure` `fn(\WP_Post $post): string` (regeneration callback, same one used by `ActionSchedulerRegenerator`).
- Produces:
  - `Markout\Scheduler\PostFinderInterface::findPublished(array $postTypes, int $limit, int $offset): array` (returns `\WP_Post[]`).
  - `Markout\Scheduler\WPQueryPostFinder implements PostFinderInterface` — the real `WP_Query`-backed implementation (not unit-tested; exercised only via manual QA, since it depends on a live `WP_Query`).
  - `Markout\Scheduler\BackfillScheduler`, public constant `HOOK = 'markout_backfill_batch'`, constructor `__construct(PostFinderInterface $finder, CacheInterface $cache, \Closure $regenerate)`, method `runBatch(int $offset): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Scheduler;

use Brain\Monkey\Functions;
use Markout\Cache\CacheInterface;
use Markout\Scheduler\BackfillScheduler;
use Markout\Scheduler\PostFinderInterface;
use Markout\Tests\TestCase;

final class BackfillSchedulerTest extends TestCase
{
    private function fakeCache(): CacheInterface
    {
        return new class implements CacheInterface {
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

    public function test_run_batch_writes_cache_for_each_post_found(): void
    {
        $posts = [$this->makePost(1), $this->makePost(2)];
        $cache = $this->fakeCache();

        $scheduler = new BackfillScheduler(
            $this->finderReturning($posts),
            $cache,
            static fn (\WP_Post $post): string => 'md-' . $post->ID
        );

        $scheduler->runBatch(0);

        self::assertSame([[1, 'md-1'], [2, 'md-2']], $cache->writes);
    }

    public function test_run_batch_reschedules_when_batch_is_full(): void
    {
        $fullBatch = array_map(fn (int $i) => $this->makePost($i), range(1, 20));

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(\Mockery::type('int'), BackfillScheduler::HOOK, [20], 'markout');

        $scheduler = new BackfillScheduler(
            $this->finderReturning($fullBatch),
            $this->fakeCache(),
            static fn (\WP_Post $post): string => 'x'
        );

        $scheduler->runBatch(0);
    }

    public function test_run_batch_does_not_reschedule_when_batch_is_partial(): void
    {
        Functions\expect('as_schedule_single_action')->never();

        $scheduler = new BackfillScheduler(
            $this->finderReturning([$this->makePost(1)]),
            $this->fakeCache(),
            static fn (\WP_Post $post): string => 'x'
        );

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

use Markout\Cache\CacheInterface;

final class BackfillScheduler
{
    public const HOOK = 'markout_backfill_batch';
    private const BATCH_SIZE = 20;
    private const ALLOWED_TYPES = ['post', 'page'];
    private const GROUP = 'markout';

    public function __construct(
        private readonly PostFinderInterface $finder,
        private readonly CacheInterface $cache,
        private readonly \Closure $regenerate
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
            $this->cache->write((int) $post->ID, ($this->regenerate)($post));
        }

        if (count($posts) === self::BATCH_SIZE) {
            as_schedule_single_action(time(), self::HOOK, [$offset + self::BATCH_SIZE], self::GROUP);
        }
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Scheduler/BackfillSchedulerTest.php`
Expected: `OK (3 tests, 4 assertions)`

- [ ] **Step 7: Static analysis and style**

Run: `composer stan && composer cs`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Scheduler/PostFinderInterface.php src/Scheduler/WPQueryPostFinder.php src/Scheduler/BackfillScheduler.php tests/Unit/Scheduler/BackfillSchedulerTest.php
git commit -m "Add BackfillScheduler for populating the cache on activation"
```

---

### Task 10: Plugin Bootstrap Wiring

**Files:**
- Create: `src/Plugin.php`
- Create: `markout.php`
- Test: `tests/Unit/PluginTest.php`

**Interfaces:**
- Consumes: every class from Tasks 2–9.
- Produces: `Markout\Plugin`, constructor `__construct(string $cacheDirectory)`, method `boot(): void`, static methods `activate(): void` and `deactivate(): void`. `markout.php` is the WordPress plugin entry file — not unit-tested (requires a loaded WordPress runtime), verified via the manual QA checklist in Task 12.

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
    public function test_extract_meta_reads_expected_wordpress_functions(): void
    {
        Functions\when('get_the_title')->justReturn('Hello World');
        Functions\when('get_the_date')->justReturn('2026-07-04T00:00:00+00:00');
        Functions\when('get_the_author_meta')->justReturn('Ahmad');
        Functions\when('get_permalink')->justReturn('https://example.com/hello-world/');

        $plugin = new Plugin(sys_get_temp_dir() . '/markout-plugin-test-' . uniqid('', true));

        $post = new \WP_Post();
        $post->post_author = 1;

        $meta = (new \ReflectionMethod($plugin, 'extractMeta'))->invoke($plugin, $post);

        self::assertSame([
            'title' => 'Hello World',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/hello-world/',
        ], $meta);
    }

    public function test_boot_registers_router_and_regenerator(): void
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
use Markout\Http\MarkdownResponder;
use Markout\Http\Response;
use Markout\Router\EndpointRouter;
use Markout\Scheduler\ActionSchedulerRegenerator;
use Markout\Scheduler\BackfillScheduler;
use Markout\Scheduler\WPQueryPostFinder;
use Markout\Support\PostVisibility;

final class Plugin
{
    private readonly MarkdownResponder $responder;
    private readonly EndpointRouter $router;
    private readonly ActionSchedulerRegenerator $regenerator;
    private readonly BackfillScheduler $backfill;

    public function __construct(string $cacheDirectory)
    {
        $cache = new FileCache($cacheDirectory);

        $this->responder = new MarkdownResponder(
            $cache,
            new HtmlToMarkdownConverter(),
            new FrontmatterBuilder(),
            new PostVisibility()
        );

        $this->router = new EndpointRouter(function (\WP_Post $post): void {
            $this->emit($this->responder->respond($post, $this->extractMeta($post)));
        });

        $regenerate = fn (\WP_Post $post): string => $this->responder->generate($post, $this->extractMeta($post));

        $this->regenerator = new ActionSchedulerRegenerator($cache, $regenerate);
        $this->backfill = new BackfillScheduler(new WPQueryPostFinder(), $cache, $regenerate);
    }

    public function boot(): void
    {
        $this->router->register();
        $this->regenerator->register();
        $this->backfill->register();
    }

    /**
     * @return array{title:string,date:string,author:string,permalink:string}
     */
    private function extractMeta(\WP_Post $post): array
    {
        return [
            'title' => (string) get_the_title($post),
            'date' => (string) get_the_date('c', $post),
            'author' => (string) get_the_author_meta('display_name', (int) $post->post_author),
            'permalink' => (string) get_permalink($post),
        ];
    }

    private function emit(Response $response): void
    {
        status_header($response->status);
        header('Content-Type: ' . $response->contentType);
        echo $response->body;
        exit;
    }

    public static function activate(): void
    {
        flush_rewrite_rules();

        if (!as_has_scheduled_action(BackfillScheduler::HOOK, [0], 'markout')) {
            as_schedule_single_action(time(), BackfillScheduler::HOOK, [0], 'markout');
        }
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
        as_unschedule_all_actions(ActionSchedulerRegenerator::REGENERATE_HOOK, [], 'markout');
        as_unschedule_all_actions(BackfillScheduler::HOOK, [], 'markout');
    }
}
```

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

$markoutAutoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($markoutAutoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>Markout: missing Composer dependencies. '
            . 'Run <code>composer install</code> in the plugin directory.</p></div>';
    });

    return;
}

require_once $markoutAutoload;

use Markout\Plugin;

add_action('plugins_loaded', static function (): void {
    if (!function_exists('as_enqueue_async_action')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>Markout: Action Scheduler is unavailable.</p></div>';
        });

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
Expected: `OK (2 tests, 5 assertions)`

- [ ] **Step 6: Run the full test suite**

Run: `composer test`
Expected: all tests across every task pass.

- [ ] **Step 7: Static analysis and style**

Add `markout.php` to `phpcs.xml`'s file list:

```xml
<?xml version="1.0"?>
<ruleset name="Markout">
    <arg name="basepath" value="."/>
    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <file>src</file>
    <file>tests</file>
    <file>markout.php</file>
    <rule ref="PSR12"/>
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

### Task 11: Uninstall Cleanup

**Files:**
- Create: `uninstall.php`

**Interfaces:**
- Consumes: nothing from `src/` — deliberately standalone per WordPress's uninstall convention (executed by WordPress core outside normal plugin bootstrap). Not unit-tested; verified manually (Task 12).

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
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        is_dir($path) ? $deleteDir($path) : unlink($path);
    }

    rmdir($dir);
};

$deleteDir($cacheDir);

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('markout_regenerate_md', [], 'markout');
    as_unschedule_all_actions('markout_backfill_batch', [], 'markout');
}
```

- [ ] **Step 2: Static analysis and style**

Add `uninstall.php` to `phpcs.xml`'s file list (alongside `markout.php`), then run:

Run: `composer stan && composer cs`
Expected: no errors. (`uninstall.php` isn't under `phpstan.neon`'s `paths`, so it's checked by `phpcs` only — this matches its untestable, WP-lifecycle-only nature.)

- [ ] **Step 3: Commit**

```bash
git add uninstall.php phpcs.xml
git commit -m "Add uninstall cleanup for cache directory and scheduled actions"
```

---

### Task 12: Manual QA

**Files:** none (verification only — this task confirms behavior that automated tests can't reach: full WordPress runtime, rewrite rules, file permissions, real HTTP requests).

- [ ] **Step 1: Install on a local WordPress site**

Copy the `markout` directory into `wp-content/plugins/`, run `composer install --no-dev` inside it (or ship `vendor/` in the release build), then activate the plugin from the WordPress admin.

- [ ] **Step 2: Verify pretty-permalink routing**

With pretty permalinks enabled (Settings → Permalinks → Post name), visit `https://<site>/<published-post-slug>/md`.
Expected: `text/markdown` response with YAML frontmatter followed by the converted body.

- [ ] **Step 3: Verify plain-permalink routing**

Switch permalinks to "Plain" (Settings → Permalinks), visit `https://<site>/?p=<post-id>&md`.
Expected: same markdown output as Step 2.

- [ ] **Step 4: Verify password-protected posts**

Password-protect a post, visit its `/md` URL without supplying the password.
Expected: HTTP 403, body `This content is password protected.`

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
Expected: file no longer exists.

- [ ] **Step 8: Verify uploads-directory-unwritable fallback**

Temporarily `chmod` the `wp-content/uploads/markout/` directory (or its parent, before creation) to remove write permission, request a `/md` URL for a post with no existing cache file.
Expected: markdown is still served (generated on the fly); no fatal error; restore permissions afterward.

- [ ] **Step 9: Verify activation backfill**

On a site with several existing published posts/pages, deactivate and reactivate the plugin, wait for the Action Scheduler queue to process, then check `wp-content/uploads/markout/`.
Expected: a `.md` file exists for every published post and page.

- [ ] **Step 10: Verify uninstall cleanup**

Deactivate and delete the plugin through the WordPress admin (triggers `uninstall.php`).
Expected: `wp-content/uploads/markout/` directory is gone; no PHP errors/warnings in the site's debug log.

- [ ] **Step 11: Record results**

Note any deviations from expected results directly in this plan file or a follow-up issue before considering the plugin ready to ship.
