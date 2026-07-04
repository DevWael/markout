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
