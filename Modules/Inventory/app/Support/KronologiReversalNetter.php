<?php

namespace Modules\Inventory\Support;

class KronologiReversalNetter
{
    public static function hiddenIds(iterable $rows): array
    {
        $ids = [];

        foreach (self::pairs($rows) as $pair) {
            $ids[] = $pair['original_id'];
            $ids[] = $pair['reversal_id'];
        }

        return array_values(array_unique($ids));
    }

    public static function pairs(iterable $rows): array
    {
        $active = [];
        $pairs = [];

        foreach ($rows as $row) {
            $qty = (int) ($row->qty ?? 0);
            if ($qty === 0) {
                continue;
            }

            $cell = ($row->item_id ?? '').'|'.($row->location_id ?? '').'|'.($row->bin_id ?? 'NULL');

            if (in_array($row->source, InventoryMovementSourceMap::CHRONOLOGY_NETTABLE_REVERSAL_SOURCES, true)) {
                $stack = $active[$cell] ?? [];
                $matchIdx = null;
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['qty'] === -$qty) {
                        $matchIdx = $i;
                        break;
                    }
                }

                if ($matchIdx !== null) {
                    $pairs[] = [
                        'original_id' => $stack[$matchIdx]['id'],
                        'reversal_id' => $row->id,
                    ];
                    array_splice($stack, $matchIdx, 1);
                    $active[$cell] = $stack;
                }

                continue;
            }

            $active[$cell][] = ['id' => $row->id, 'qty' => $qty];
        }

        return $pairs;
    }
}
