<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Enums\PurchaseActivityAction;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderActivity;

/**
 * Pencatat riwayat PO. Meniru pola sales_order_status_histories: satu baris per
 * peristiwa, pelaku disimpan sebagai FK + snapshot nama/email, dan detail
 * perubahan masuk ke metadata sebagai pasangan nilai lama/baru.
 */
class PurchaseOrderActivityLogger
{
    /** Kolom header yang layak muncul di riwayat. Sisanya (total, status) punya baris sendiri. */
    private const TRACKED_HEADER_FIELDS = [
        'contact_id',
        'location_id',
        'order_date',
        'expected_date',
        'ref_no',
        'payment_term',
        'notes',
        'po_number',
    ];

    /** Kolom item yang perubahannya berarti bagi pengguna. */
    private const TRACKED_ITEM_FIELDS = [
        'qty',
        'unit_price',
        'disc',
        'disc_amount',
        'shipping_cost',
        'description',
        'unit',
    ];

    public function log(
        PurchaseOrder $po,
        PurchaseActivityAction $action,
        array $metadata = [],
        string $entityType = PurchaseOrderActivity::ENTITY_ORDER,
        ?string $entityId = null,
    ): void {
        $actor = auth()->user();

        PurchaseOrderActivity::create([
            'purchase_order_id' => $po->id,
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'action_id'         => $action->code(),
            'action'            => $action->value,
            'actor_id'          => $actor?->id,
            'actor_name'        => $actor?->name ?? 'System',
            'actor_email'       => $actor?->email,
            'metadata'          => $metadata ?: null,
            'created_at'        => now(),
        ]);
    }

    /**
     * Catat perubahan kolom header. Tidak menulis apa pun kalau tak ada yang
     * benar-benar berubah -- riwayat yang penuh baris kosong tidak terbaca.
     */
    public function logHeaderChanges(PurchaseOrder $po, array $before, array $after): void
    {
        $prev = [];
        $next = [];

        foreach (self::TRACKED_HEADER_FIELDS as $field) {
            if (! array_key_exists($field, $after)) {
                continue;
            }

            $oldValue = $this->normalize($before[$field] ?? null);
            $newValue = $this->normalize($after[$field]);

            if ($oldValue === $newValue) {
                continue;
            }

            $prev[$field] = $oldValue;
            $next[$field] = $newValue;
        }

        if (empty($next)) {
            return;
        }

        $this->log($po, PurchaseActivityAction::FIELD_CHANGED, [
            'prev_values' => $prev,
            'new_values'  => $next,
        ]);
    }

    public function logItemAdded(PurchaseOrder $po, array $item, ?string $sku): void
    {
        $this->log($po, PurchaseActivityAction::ITEM_ADDED, [
            'entity_no'  => $sku,
            'new_values' => [
                'qty'        => (int) ($item['qty'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
            ],
        ], PurchaseOrderActivity::ENTITY_ITEM, $item['id'] ?? null);
    }

    public function logItemChanged(PurchaseOrder $po, string $itemId, ?string $sku, array $before, array $after): void
    {
        $prev = [];
        $next = [];

        foreach (self::TRACKED_ITEM_FIELDS as $field) {
            if (! array_key_exists($field, $after)) {
                continue;
            }

            $oldValue = $this->normalize($before[$field] ?? null);
            $newValue = $this->normalize($after[$field]);

            if ($oldValue === $newValue) {
                continue;
            }

            $prev[$field] = $oldValue;
            $next[$field] = $newValue;
        }

        if (empty($next)) {
            return;
        }

        $this->log($po, PurchaseActivityAction::ITEM_CHANGED, [
            'entity_no'   => $sku,
            'prev_values' => $prev,
            'new_values'  => $next,
        ], PurchaseOrderActivity::ENTITY_ITEM, $itemId);
    }

    public function logItemRemoved(PurchaseOrder $po, string $itemId, ?string $sku, int $qty, int $receivedQty): void
    {
        $this->log($po, PurchaseActivityAction::ITEM_REMOVED, [
            'entity_no'   => $sku,
            'prev_values' => ['qty' => $qty, 'received_qty' => $receivedQty],
        ], PurchaseOrderActivity::ENTITY_ITEM, $itemId);
    }

    /**
     * Penarikan balik stok dicatat terpisah dari perubahan qty biasa: ini yang
     * menjelaskan kenapa stok di rak ikut berkurang.
     */
    public function logReceiptReversed(PurchaseOrder $po, ?string $sku, int $qty, string $reason): void
    {
        $this->log($po, PurchaseActivityAction::RECEIPT_REVERSED, [
            'entity_no' => $sku,
            'note'      => $reason,
            'qty'       => $qty,
        ]);
    }

    public function logStatusChanged(PurchaseOrder $po, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->log($po, PurchaseActivityAction::STATUS_CHANGED, [
            'prev_values' => ['status' => $from],
            'new_values'  => ['status' => $to],
        ]);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            return 0 + $value;
        }

        if ($value === '' || $value === null) {
            return null;
        }

        return $value;
    }
}
