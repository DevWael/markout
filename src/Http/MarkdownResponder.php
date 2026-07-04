<?php

declare(strict_types=1);

namespace Markout\Http;

use Markout\Cache\CacheInterface;
use Markout\Conversion\ConverterInterface;
use Markout\Conversion\FrontmatterBuilder;
use Markout\Conversion\MarkdownGeneratorInterface;
use Markout\Support\PostVisibility;

/**
 * Ties visibility, caching, and HTML-to-markdown conversion together for a
 * single post: the password gate, cache hit/miss, and cache-write policy
 * all live here.
 */
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

        // hasPassword() (stateless: post_password !== '') is deliberately
        // used here instead of requiresPassword() (session/cookie-aware,
        // already false at this point since we passed the gate above). A
        // visitor who entered the correct password must still see the
        // generated markdown, but their request must never be the one that
        // populates the on-disk cache: the uploads directory is web
        // -accessible, so a cached file for a password-protected post would
        // let any anonymous visitor bypass the password via direct HTTP GET.
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
