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
