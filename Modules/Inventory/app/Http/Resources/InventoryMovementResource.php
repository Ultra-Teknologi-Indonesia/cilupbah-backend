<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    private const SOURCE_MAP = [
        'BILL'              => ['category' => 'BILL', 'label' => 'Tagihan'],
        'ADJUSTMENT'        => ['category' => 'ADJUSTMENT', 'label' => 'Penyesuaian'],
        'STOCK_OPNAME'      => ['category' => 'ADJUSTMENT', 'label' => 'Penyesuaian'],
        'PURCHASE_RETURN'   => ['category' => 'PURCHASE_RETURN', 'label' => 'Retur Pembelian'],
        'SALES_RETURN'      => ['category' => 'SALES_RETURN', 'label' => 'Retur Penjualan'],
        'INVOICE'           => ['category' => 'INVOICE', 'label' => 'Faktur'],
        'ORDER_SHIP'        => ['category' => 'INVOICE', 'label' => 'Faktur'],
        'ORDER_PICK'        => ['category' => 'ORDER', 'label' => 'Pesanan'],
        'ORDER_RESTORE'     => ['category' => 'ORDER', 'label' => 'Pesanan'],
        'ORDER_CANCEL'      => ['category' => 'ORDER_CANCEL', 'label' => 'Pesanan Batal'],
        'ORDER_BOOK'        => ['category' => 'RESERVE', 'label' => 'Cadangan'],
        'TRANSFER_IN'       => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'TRANSFER_OUT'      => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_IN'   => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_OUT'  => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'PUTAWAY_IN'        => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'PUTAWAY_OUT'       => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'REVALUATION'       => ['category' => 'REVALUATION', 'label' => 'Ubah Nilai Stok'],
    ];

    public function toArray(Request $request): array
    {
        $meta = self::SOURCE_MAP[$this->source] ?? ['category' => 'OTHER', 'label' => $this->source];
        $qty = (int) $this->qty;

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'sku' => $this->whenLoaded('product', fn () => $this->product?->sku),
            'product_id' => $this->whenLoaded('product', fn () => $this->product?->product_id),
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'bin_id' => $this->bin_id,
            'bin_code' => $this->whenLoaded('bin', fn () => $this->bin?->bin_final_code),
            'transaction_number' => $this->transaction_number,
            'source' => $this->source,
            'source_category' => $meta['category'],
            'source_label' => $meta['label'],
            'direction' => $qty > 0 ? 'in' : ($qty < 0 ? 'out' : 'none'),
            'qty' => $qty,
            'balance' => (int) ($this->total_balance ?? $this->balance),
            'transaction_date' => $this->transaction_date,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
