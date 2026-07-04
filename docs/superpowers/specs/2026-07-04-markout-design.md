# Markout — Design Spec

Date: 2026-07-04

## Summary

Markout is a WordPress plugin that serves a markdown version of any post or
page. Visitors can append `/md` to a permalink (pretty permalinks) or add
`?md` as a query var (plain permalinks) to get the content as
`text/markdown` with a YAML frontmatter block, instead of the normal HTML
page.

## Scope

- Posts and Pages only (no custom post types in v1).
- Output: YAML frontmatter (title, date, author, permalink) + body converted
  from HTML to markdown via `league/html-to-markdown`.
- Visibility mirrors normal WordPress rules: private/draft content is
  unreachable to unauthorized visitors exactly as it already is; password
  -protected content is denied with a 403 (no password form exists in
  markdown).
- Caching: converted markdown is persisted as a file under
  `wp-content/uploads/markout/{post_id}.md`, not the database. Cache is
  regenerated asynchronously (Action Scheduler) on save, and self-heals
  on-the-fly on a cache miss.

## Architecture

Plugin slug: `markout`. Namespace: `Markout\`. PSR-4 autoloaded via
Composer, PSR-12 formatted, `phpstan` level 8 clean, interfaces + manual
constructor-based dependency injection (no DI framework/container).

```
markout/
├── markout.php                   # plugin header, requires vendor/autoload.php, boots Plugin
├── composer.json                 # PSR-4 "Markout\\": "src/"
├── src/
│   ├── Plugin.php                # bootstrap, manual DI wiring, activation/deactivation/uninstall
│   ├── Router/
│   │   ├── RouterInterface.php
│   │   └── EndpointRouter.php        # registers md endpoint, detects request
│   ├── Conversion/
│   │   ├── ConverterInterface.php
│   │   ├── HtmlToMarkdownConverter.php   # wraps league/html-to-markdown
│   │   └── FrontmatterBuilder.php        # YAML frontmatter (title/date/author/permalink)
│   ├── Cache/
│   │   ├── CacheInterface.php
│   │   └── FileCache.php             # uploads/markout/{post_id}.md
│   ├── Scheduler/
│   │   ├── RegeneratorInterface.php
│   │   └── ActionSchedulerRegenerator.php  # save_post/trash → async regen/delete
│   ├── Http/
│   │   └── MarkdownResponder.php     # ties visibility+cache+converter together, emits response
│   └── Support/
│       └── PostVisibility.php        # single source of truth for "can this be shown"
└── tests/
    ├── Unit/
    └── Integration/
```

### Dependencies

- Runtime: `league/html-to-markdown`, `woocommerce/action-scheduler`
  (self-detects and reuses the newest copy loaded by any active plugin —
  standard WordPress practice, no version conflicts).
- Dev: `squizlabs/php_codesniffer` (PSR-12 ruleset), `phpstan/phpstan` +
  `szepeviktor/phpstan-wordpress` (level 8, WP stubs), `phpunit/phpunit` +
  `brain/monkey` (unit tests that mock WordPress functions without a full
  WP test suite).

## Request Lifecycle

### Routing

`add_rewrite_endpoint('md', EP_PERMALINK)` — the same WordPress core
mechanism that powers `/feed/` and `/amp/`. It registers a `md` query var
that works both as a pretty-permalink suffix (`/post-slug/md/`) and as a
plain-permalink query var (`?p=123&md`) automatically. This single
mechanism satisfies both routing forms without separate code paths.

### Detecting the request

Hook `template_redirect`. Check `array_key_exists('md', $wp->query_vars)`
rather than `get_query_var('md')`, because endpoint query vars default to
`''` whether the endpoint is present-with-no-value or entirely absent — key
presence is the only reliable signal. Gate on `is_singular(['post',
'page'])`.

### Visibility (`PostVisibility`)

- Private/draft content unauthorized to the current viewer: already
  handled upstream by WordPress's own query (private posts only resolve
  for users with `read_private_posts`), so `is_singular()` is simply
  `false` for anonymous visitors on those. No reimplementation needed —
  trust the existing gate.
- Password-protected content: `is_singular()` is still `true` (the post
  exists), so this is checked explicitly via `post_password_required($post)`.
  Since markdown has no password form to render, "mirror normal rules"
  means: deny with `403` and a short plain-text body ("This content is
  password protected."), rather than serving content.

### Serving (`MarkdownResponder`)

1. Cache hit: read `uploads/markout/{post_id}.md`, echo it, set
   `Content-Type: text/markdown; charset=utf-8`, `exit`.
2. Cache miss: convert live via `Converter` + `FrontmatterBuilder`, write
   the result to the cache file, then serve it the same way (self-healing —
   there is no permanent 404 state for valid content).

### Regeneration (`ActionSchedulerRegenerator`)

- `save_post` (skipping revisions/autosaves, allowed post types only): if
  the new status is `publish`, enqueue
  `as_enqueue_async_action('markout_regenerate_md', [$postId])`, guarded by
  `as_has_scheduled_action()` to avoid duplicate jobs from rapid saves.
- Status transitions away from `publish` (draft/trash/pending/private), or
  the post is deleted (`before_delete_post`): delete the cache file
  synchronously — cheap enough not to need async.
- The async callback re-verifies the post is still `publish` and an
  allowed type before regenerating, guarding against a race where the
  status changed again between enqueue and run.

### Activation / Deactivation / Uninstall

- Activation: `flush_rewrite_rules()` (required for the new endpoint to
  take effect) + schedule a one-time batched backfill
  (`as_schedule_single_action`, self-re-enqueuing per batch via a paged
  `WP_Query`) so existing posts/pages get a cached file without blocking
  activation or timing out on large sites.
- Deactivation: `flush_rewrite_rules()`, `as_unschedule_all_actions()` for
  Markout's hooks.
- Uninstall: recursively delete `uploads/markout/`, clear any leftover
  scheduled actions.

## Error Handling

- Uploads directory not writable: `FileCache::write()` catches the
  failure, logs via `error_log` only when `WP_DEBUG` is enabled, and falls
  back to serving the converted content without persisting it. Never
  fatals.
- `league/html-to-markdown` throws on malformed HTML: caught, falls back
  to `wp_strip_all_tags()` plain-text output, logs the failure.
- Missing `vendor/autoload.php` (Composer install skipped): detected on
  activation, surfaced as an `admin_notice`, plugin auto-deactivates rather
  than fataling on every page load.
- Action Scheduler unavailable: same activation self-check pattern as
  above.

## Safety

- Cache filenames are built only from `(int) $post->ID` — never from user
  input — eliminating path traversal as a concern.
- `uploads/markout/` contains an empty `index.php` to block directory
  listing.
- `declare(strict_types=1)` throughout `src/`; every WordPress
  superglobal/query-var read is explicitly cast before use.

## Testing

- **Unit (PHPUnit + Brain Monkey):** `HtmlToMarkdownConverter` (HTML
  fixtures → expected markdown), `FrontmatterBuilder` (mock post → expected
  YAML), `PostVisibility` (mocked `post_password_required` return values),
  `FileCache` (read/write/delete against a temp directory).
- **Integration-style (Brain Monkey mocking WordPress/Action Scheduler
  functions):** `EndpointRouter` registers the endpoint correctly;
  `ActionSchedulerRegenerator` enqueues, dedupes, and deletes on the
  correct `save_post` transitions.
- **Static analysis:** `phpstan` level 8 clean, `phpcs` PSR-12 clean, both
  wired as Composer scripts (`composer test`, `composer stan`,
  `composer cs`).
- **Manual QA checklist:** pretty vs. plain permalinks, password-protected
  post, private post as anonymous vs. authorized user, uploads directory
  made read-only, activation backfill on a site with existing content.
