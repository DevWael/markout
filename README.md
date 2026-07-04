# Markout

A WordPress plugin that serves a markdown version of any post or page.

Visit `/your-post-slug/md` (pretty permalinks) or `?p=123&md` (plain permalinks) to get the post's content as `text/markdown`, with a YAML frontmatter block (title, date, author, permalink) followed by the body converted from HTML to markdown.

## Status

This project is currently at the design/planning stage — no plugin code has been written yet. See:

- [Design spec](docs/superpowers/specs/2026-07-04-markout-design.md) — architecture, request lifecycle, error handling, and safety decisions.
- [Implementation plan](docs/superpowers/plans/2026-07-04-markout-implementation.md) — the task-by-task, test-driven build plan.

## How it will work

- **Scope:** Posts and Pages only.
- **Caching:** converted markdown is cached to a file under `wp-content/uploads/markout/`, regenerated asynchronously (via Action Scheduler) whenever a post is saved, and self-heals on a cache miss.
- **Visibility:** mirrors WordPress's own rules exactly — private/draft content is unreachable the same way it already is, and password-protected content is denied with a 403. Password-protected posts are never written to the cache.
- **Routing:** a single WordPress rewrite endpoint (`add_rewrite_endpoint('md', EP_PERMALINK)`) handles both pretty and plain permalink structures.

## Requirements (once built)

- PHP 8.1+
- WordPress with Composer-managed dependencies (`league/html-to-markdown`, `woocommerce/action-scheduler`)

## Development

The codebase (once implemented) will follow PSR-4 autoloading, PSR-12 formatting, PHPStan level 8, and be tested with PHPUnit + Brain Monkey. See the [implementation plan](docs/superpowers/plans/2026-07-04-markout-implementation.md) for the full toolchain setup.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
