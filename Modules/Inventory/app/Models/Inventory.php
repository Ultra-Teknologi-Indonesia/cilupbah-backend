<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class Inventory extends Model
{
    use HasUuid7;

    protected $fillable = [
        'item_id',
        'location_id',
        'bin_id',
        'batch_no',
        'serial_no',
        'expired_date',
        'on_hand',
        'on_order',
        'available',
        'avg_cost',
    ];

    protected $casts = [
        'expired_date' => 'datetime',
        'avg_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'bin_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id', 'item_id')
            ->where('location_id', $this->location_id);
    }

    public function scopePlaced($query)
    {
        return $query->whereExists(function ($q) {
            $q->selectRaw('1')
                ->from('location_bins')
                ->whereColumn('location_bins.id', 'inventories.bin_id')
                ->whereColumn('location_bins.location_id', 'inventories.location_id')
                ->where('location_bins.is_inbound', false);
        });
    }

    /**
     * Urutkan rak berdasarkan tanggal pergerakan stok masuk (qty > 0) per rak.
     * SSOT strategi rekomendasi rak — dipakai picking (LIFO) dan transfer (FIFO).
     *
     * - fifo: rak dengan stok masuk PALING LAMA didahulukan (MIN transaction_date).
     * - lifo: rak dengan stok masuk PALING BARU didahulukan (MAX transaction_date).
     *
     * Rak tanpa riwayat pergerakan (movement_at NULL) ditaruh terakhir, lalu
     * di-tiebreak oleh created_at searah strategi.
     */
    public function scopeOrderByBinMovement($query, string $strategy)
    {
        $lifo = strtolower($strategy) === 'lifo';

        $movementSub = InventoryMovement::query()
            ->selectRaw(($lifo ? 'MAX' : 'MIN') . '(transaction_date)')
            ->whereColumn('inventory_movements.item_id', 'inventories.item_id')
            ->whereColumn('inventory_movements.location_id', 'inventories.location_id')
            ->whereColumn('inventory_movements.bin_id', 'inventories.bin_id')
            ->where('qty', '>', 0);

        return $query
            ->select('inventories.*')
            ->selectSub($movementSub, 'movement_at')
            ->orderByRaw('movement_at ' . ($lifo ? 'DESC' : 'ASC') . ' NULLS LAST')
            ->orderBy('inventories.created_at', $lifo ? 'desc' : 'asc');
    }

    public function scopePendingPlacement($query)
    {
        return $query->where(function ($w) {
            $w->whereNull('inventories.bin_id')
                ->orWhereExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('location_bins')
                        ->whereColumn('location_bins.id', 'inventories.bin_id')
                        ->where('location_bins.is_inbound', true);
                });
        });
    }

    public function isPlaced(): bool
    {
        if ($this->bin_id === null) {
            return false;
        }

        $isInbound = $this->relationLoaded('bin')
            ? $this->bin?->is_inbound
            : \Modules\Warehouse\Models\LocationBin::whereKey($this->bin_id)->value('is_inbound');

        return $isInbound !== null && ! (bool) $isInbound;
    }

    public function recalculateAvailable(): void
    {
        $this->available = $this->isPlaced()
            ? (int) $this->on_hand - (int) $this->on_order
            : 0;
    }
}
