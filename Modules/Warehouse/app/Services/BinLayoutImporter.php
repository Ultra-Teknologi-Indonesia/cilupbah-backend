<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\LocationZone;

class BinLayoutImporter
{

    public function import(Location $location, iterable $codes, bool $commit = true): array
    {
        $codes = is_array($codes) ? $codes : iterator_to_array($codes);
        $codes = array_values(array_unique(array_filter(
            array_map(fn ($c) => trim((string) $c), $codes),
            fn ($c) => $c !== ''
        )));

        $map = LocationBin::where('location_id', $location->id)->pluck('id', 'bin_final_code')->all();
        $newCodes = array_values(array_filter($codes, fn ($c) => ! isset($map[$c])));

        $zonesCreated = 0;
        if ($commit && ! empty($newCodes)) {
            $zoneMap = $this->ensureZones($location->id, $codes, $zonesCreated);
            foreach ($newCodes as $code) {
                $struct = self::deriveStructure($code);
                $bin = LocationBin::firstOrCreate(
                    ['location_id' => $location->id, 'bin_final_code' => $code],
                    array_merge($struct, [
                        'zone_id' => $struct['floor_code'] !== null ? ($zoneMap[$struct['floor_code']] ?? null) : null,
                        'is_inbound' => false,
                        'is_stock_acknowledged' => true,
                        'is_large_bin' => false,
                        'category' => null,
                    ])
                );
                $map[$code] = $bin->id;
            }
        }

        return [
            'total' => count($codes),
            'created' => $commit ? count($newCodes) : 0,
            'existing' => count($codes) - count($newCodes),
            'new_codes' => $newCodes,
            'zones_created' => $zonesCreated,
            'map' => $map,
        ];
    }

    private function ensureZones(string $locationId, array $codes, int &$created): array
    {
        $zoneCodes = [];
        foreach ($codes as $c) {
            $zone = self::deriveStructure($c)['floor_code'];
            if ($zone !== null && $zone !== '') {
                $zoneCodes[$zone] = true;
            }
        }

        $map = LocationZone::where('location_id', $locationId)->pluck('id', 'zone_code')->all();
        foreach (array_keys($zoneCodes) as $code) {
            if (isset($map[$code])) {
                continue;
            }
            $zone = LocationZone::firstOrCreate(
                ['location_id' => $locationId, 'zone_code' => $code],
                ['zone_name' => null]
            );
            if ($zone->wasRecentlyCreated) {
                $created++;
            }
            $map[$code] = $zone->id;
        }

        return $map;
    }

    public static function deriveStructure(string $code): array
    {
        $parts = explode('-', $code);
        $floor = $parts[0] ?? null;
        $row = $parts[1] ?? null;
        $col = $parts[2] ?? null;
        $bin = isset($parts[3]) ? implode('-', array_slice($parts, 3)) : null;

        return [
            'floor_code' => ($floor !== null && $floor !== '') ? $floor : null,
            'row_code' => $row,
            'column_code' => $col,
            'bin_code' => $bin,
        ];
    }

    public static function isSpecialRak(string $code): bool
    {
        return ! preg_match('/-[A-Za-z]*[0-9]+$/', $code);
    }
}
