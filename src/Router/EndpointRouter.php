<?php

declare(strict_types=1);

namespace Markout\Router;

use Markout\Http\MarkdownRequestHandlerInterface;

/**
 * Registers the `md` rewrite endpoint and is the primary visibility gate
 * (is_singular()) that, together with MarkdownResponder's password check,
 * mirrors WordPress's own content-visibility rules without any additional
 * capability checks layered on top.
 */
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
        // $GLOBALS['wp'] is expected to be the global WP_Query request object
        // by the time template_redirect fires, but is guarded defensively
        // (missing/non-object/no query_vars) rather than assumed, since this
        // hook can also run in contexts (e.g. some test harnesses or edge-case
        // early bootstraps) where WordPress hasn't populated it yet.
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
        // Endpoint query vars default to '' whether the endpoint is
        // present-with-no-value or entirely absent, so get_query_var()
        // can't distinguish the two cases — key presence is the only
        // reliable signal that /md was actually requested.
        return array_key_exists(self::ENDPOINT, $queryVars);
    }
}
