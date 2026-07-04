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
