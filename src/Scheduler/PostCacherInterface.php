<?php

declare(strict_types=1);

namespace Markout\Scheduler;

interface PostCacherInterface
{
    public function sync(\WP_Post $post): void;
}
