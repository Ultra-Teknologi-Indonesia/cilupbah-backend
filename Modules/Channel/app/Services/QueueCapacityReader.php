<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

interface QueueCapacityReader
{

    public function inspect(string $queueConnection, string|array $queues): array;
}
