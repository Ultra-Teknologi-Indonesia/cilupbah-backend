<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

final readonly class OrderPullPageResult
{
    public function __construct(
        public int $count,
        public bool $done,
        public array $cursor = [],
    ) {}
}
