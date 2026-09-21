<?php

namespace App\Support;

class ReceivedLineAllocator
{

    private array $used = [];

    private array $ordinal = [];

    public function take(array $bucket, string $itemId, int $lineQty): ?array
    {
        if ($bucket === []) {
            return null;
        }

        $position = $this->ordinal[$itemId] ?? 0;
        $this->ordinal[$itemId] = $position + 1;

        $taken = $this->used[$itemId] ?? [];

        if (isset($bucket[$position])
            && ! isset($taken[$position])
            && $this->qtyOf($bucket[$position]) <= $lineQty) {
            return $this->pick($itemId, $bucket, $position);
        }

        foreach ($bucket as $index => $entry) {
            if (! isset($taken[$index]) && $this->qtyOf($entry) <= $lineQty) {
                return $this->pick($itemId, $bucket, $index);
            }
        }

        foreach ($bucket as $index => $entry) {
            if (! isset($taken[$index])) {
                return $this->pick($itemId, $bucket, $index);
            }
        }

        return $bucket[count($bucket) - 1];
    }

    private function pick(string $itemId, array $bucket, int $index): array
    {
        $this->used[$itemId][$index] = true;

        return $bucket[$index];
    }

    private function qtyOf(array $entry): int
    {
        return (int) ($entry['received_qty'] ?? 0);
    }
}
