<?php

namespace Modules\Bantuan\Services;

use ReflectionClass;
use Throwable;

/**
 * Resolve nilai valid untuk filter/kolom yang enum-like.
 * Sumber: config/filter-enums.php + konstanta Model (mis. SalesOrder::STATUS_*).
 */
class EnumResolver
{
    /** @var array<string,array<int,string>> */
    private array $config;

    public function __construct()
    {
        $path = __DIR__ . '/../../config/filter-enums.php';
        $this->config = is_file($path) ? (array) require $path : [];
    }

    /**
     * Lookup by (module, filterName). Cek "module.filter" dulu, lalu "filter" saja.
     * @return array<int,string>|null
     */
    public function forFilter(string $moduleSlug, string $filterName): ?array
    {
        $keyed = "{$moduleSlug}.{$filterName}";
        if (isset($this->config[$keyed])) return $this->config[$keyed];
        if (isset($this->config[$filterName])) return $this->config[$filterName];

        // Coba lookup constant di model modul (mis. Modules\Sales\Models\SalesOrder::STATUS_*)
        $model = $this->guessModelForModule($moduleSlug);
        if ($model && class_exists($model)) {
            $consts = $this->extractPrefixedConstants($model, strtoupper($filterName));
            if ($consts) return $consts;
        }
        return null;
    }

    private function guessModelForModule(string $slug): ?string
    {
        $map = [
            'sales'      => 'Modules\\Sales\\Models\\SalesOrder',
            'inventory'  => 'Modules\\Inventory\\Models\\InventoryTransfer',
            'inbound'    => 'Modules\\Inbound\\Models\\Inbound',
            'outbound'   => 'Modules\\Outbound\\Models\\Picklist',
            'product'    => 'Modules\\Product\\Models\\Product',
            'purchase'   => 'Modules\\Purchase\\Models\\PurchaseOrder',
            'channel'    => 'Modules\\Channel\\Models\\ChannelShop',
            'auth'       => 'Modules\\Auth\\Models\\User',
        ];
        return $map[strtolower($slug)] ?? null;
    }

    /**
     * Ambil konstanta model yang diawali prefix (mis. STATUS_) sebagai nilai enum.
     * @return array<int,string>
     */
    private function extractPrefixedConstants(string $class, string $prefix): array
    {
        try {
            $ref = new ReflectionClass($class);
            $constants = $ref->getConstants();
            $prefix = rtrim($prefix, '_') . '_';
            $out = [];
            foreach ($constants as $name => $value) {
                if (! is_string($value)) continue;
                if (str_starts_with($name, $prefix)) {
                    $out[] = $value;
                }
            }
            return array_values(array_unique($out));
        } catch (Throwable $e) {
            return [];
        }
    }
}
