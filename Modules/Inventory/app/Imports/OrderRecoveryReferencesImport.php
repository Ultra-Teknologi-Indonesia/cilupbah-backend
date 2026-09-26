<?php

declare(strict_types=1);

namespace Modules\Inventory\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

final class OrderRecoveryReferencesImport implements ToCollection, WithHeadingRow
{
    public const MAX_ITEMS = 20;

    private array $items = [];

    private bool $overflow = false;

    public function __construct(
        private readonly ?string $defaultChannel = null,
        private readonly ?string $defaultShopId = null,
    ) {}

    public function collection(Collection $rows): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $values = $row instanceof Collection ? $row->all() : (array) $row;
            $reference = $this->firstValue($values, [
                'nomor_pesanan', 'no_pesanan', 'order_no', 'order_id', 'reference',
            ]);

            if ($reference === null || isset($seen[$reference])) {
                continue;
            }

            if (count($this->items) >= self::MAX_ITEMS) {
                $this->overflow = true;

                break;
            }

            $seen[$reference] = true;
            $this->items[] = [
                'reference' => $reference,
                'channel' => $this->firstValue($values, ['channel', 'marketplace']) ?? $this->defaultChannel,
                'shop_id' => $this->firstValue($values, ['shop_id', 'toko_id']) ?? $this->defaultShopId,
            ];
        }
    }

    public function items(): array
    {
        return $this->items;
    }

    public function overflowed(): bool
    {
        return $this->overflow;
    }

    private function firstValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 128);
            }
        }

        return null;
    }
}
