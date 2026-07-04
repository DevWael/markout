# Markout

A WordPress plugin that serves a markdown version of any post or page.

Visit `/your-post-slug/md` (pretty permalinks) or `?p=123&md` (plain permalinks) to get the post's content as `text/markdown`, with a YAML frontmatter block (title, date, author, permalink) followed by the body converted from HTML to markdown.

## Status

Implemented and unit-tested. Manual QA against a live WordPress site (permalink structures, password/private posts, activation backfill, uninstall cleanup — see the plan's final task) is still outstanding before a production release. See:

- [Design spec](docs/superpowers/specs/2026-07-04-markout-design.md) — architecture, request lifecycle, error handling, and safety decisions.
- [Implementation plan](docs/superpowers/plans/2026-07-04-markout-implementation.md) — the task-by-task, test-driven build plan and manual QA checklist.

## How it works

- **Scope:** Posts and Pages only.
- **Caching:** converted markdown is cached to a file under `wp-content/uploads/markout/`, regenerated asynchronously (via Action Scheduler) whenever a post is saved, and self-heals on a cache miss. Writes are atomic (temp file + rename).
- **Visibility:** mirrors WordPress's own rules exactly — private/draft content is unreachable the same way it already is, and password-protected content is denied with a 403. Password-protected posts are never written to the cache, on any code path (live request, save-triggered regeneration, or activation backfill).
- **Routing:** a single WordPress rewrite endpoint (`add_rewrite_endpoint('md', EP_PERMALINK)`) handles both pretty and plain permalink structures.

## Installation

1. Copy this directory into `wp-content/plugins/markout`.
2. Run `composer install --no-dev` inside it (or ship `vendor/` in a release build).
3. Activate the plugin from the WordPress admin.

If Composer dependencies or Action Scheduler are unavailable, the plugin shows an admin notice and deactivates itself rather than fataling.

## Requirements

- PHP 8.1+
- WordPress with Composer-managed dependencies (`league/html-to-markdown`, `woocommerce/action-scheduler`)

## Development

PSR-4 autoloading, PSR-12 formatting, PHPStan level 8, tested with PHPUnit + Brain Monkey.

```bash
composer install
composer test    # PHPUnit
composer stan    # PHPStan level 8
composer cs      # PHPCS (PSR-12)
```

See the [implementation plan](docs/superpowers/plans/2026-07-04-markout-implementation.md) for the full toolchain setup and per-task rationale.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
