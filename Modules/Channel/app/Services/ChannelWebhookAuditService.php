<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Sales\Models\SalesOrder;

final class ChannelWebhookAuditService
{

    public function recordFromInbox(string $channel, string $eventKey, array $payload): int
    {
        $references = $this->extractOrderReferences($channel, $payload);

        if ($references === []) {
            return 0;
        }

        $inbox = ChannelWebhookInbox::query()
            ->where('event_key', $eventKey)
            ->first();

        $receivedAt = $inbox?->received_at ?? now();
        $eventType = $inbox?->event_type ?: $this->eventType($channel, $payload);
        $status = $this->status($payload);
        $note = $this->formatNote($channel, $eventType, $status, $receivedAt, $eventKey);

        return DB::transaction(function () use ($channel, $references, $note, $eventKey): int {
            $orders = SalesOrder::query()
                ->where('source', strtolower($channel))
                ->whereIn('channel_order_no', $references)
                ->lockForUpdate()
                ->get(['id', 'seller_note']);

            $updated = 0;

            foreach ($orders as $order) {
                $existingNote = trim((string) ($order->seller_note ?? ''));

                if (str_contains($existingNote, "Webhook event_key={$eventKey}")) {
                    continue;
                }

                $combinedNote = $existingNote === ''
                    ? $note
                    : $existingNote . PHP_EOL . $note;

                DB::table('sales_orders')
                    ->where('id', $order->id)
                    ->update([
                        'seller_note' => $combinedNote,
                        'updated_at' => now(),
                    ]);

                $updated++;
            }

            return $updated;
        });
    }

    private function extractOrderReferences(string $channel, array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $references = [];

        $add = static function (array &$target, mixed $value): void {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $target[] = trim((string) $value);
            }
        };

        $channel = strtolower($channel);

        if ($channel === 'tiktok') {
            $add($references, $data['order_id'] ?? null);
            $add($references, $data['main_order_id'] ?? null);
            $add($references, $data['reverse_order_id'] ?? null);

            foreach ((array) ($data['package_list'] ?? []) as $package) {
                foreach ((array) ($package['order_id_list'] ?? []) as $orderId) {
                    $add($references, $orderId);
                }
            }

            foreach ((array) ($data['line_items'] ?? []) as $lineItem) {
                $add($references, $lineItem['main_order_id'] ?? null);
            }
        } elseif ($channel === 'shopee') {
            $add($references, $data['ordersn'] ?? $data['order_sn'] ?? null);
        } elseif ($channel === 'lazada') {
            $add($references, $data['trade_order_id'] ?? $data['order_id'] ?? $data['reverse_order_id'] ?? null);
        } elseif ($channel === 'woocommerce') {
            $add($references, $payload['id'] ?? null);
        }

        return array_values(array_unique($references));
    }

    private function status(array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        foreach (['order_status', 'status', 'package_status', 'fulfillment_status'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return strtoupper(trim((string) $data[$key]));
            }
        }

        return null;
    }

    private function eventType(string $channel, array $payload): string
    {
        return match (strtolower($channel)) {
            'tiktok' => 'type:' . (string) ($payload['type'] ?? 'unknown'),
            'shopee' => 'code:' . (string) ($payload['code'] ?? 'unknown'),
            'lazada' => 'message_type:' . (string) ($payload['message_type'] ?? 'unknown'),
            'woocommerce' => (string) ($payload['_webhook_topic'] ?? $payload['topic'] ?? 'order'),
            default => 'webhook',
        };
    }

    private function formatNote(
        string $channel,
        string $eventType,
        ?string $status,
        \DateTimeInterface $receivedAt,
        string $eventKey,
    ): string {
        $receivedWib = (new \DateTimeImmutable($receivedAt->format('c')))
            ->setTimezone(new \DateTimeZone('Asia/Jakarta'))
            ->format('d-m-Y H:i:s');

        return sprintf(
            'Webhook channel=%s | event=%s | status=%s | diterima=%s WIB | Webhook event_key=%s',
            strtoupper($channel),
            $eventType,
            $status ?: '-',
            $receivedWib,
            $eventKey,
        );
    }
}
