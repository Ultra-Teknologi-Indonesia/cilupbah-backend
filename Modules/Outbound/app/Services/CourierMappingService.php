<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Collection;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Models\CourierChannelMapping;

/**
 * Master data resolver for couriers across channels.
 *
 * Each marketplace channel reports its own raw provider names (Shopee "SPX Express",
 * TikTok "SPX Standard", Lazada "...") and its own service tiers. This service maps
 * those raw names onto a single canonical courier (master data) plus a normalised
 * shipment_type, so that orders coming from different channels but using the same
 * expedition + service tier (e.g. SPX Regular) can be merged into one manifest.
 */
class CourierMappingService
{
    /**
     * Raw provider name keyword => canonical courier code (master data brand).
     */
    private array $codeAliases = [
        'j&t express' => 'jnt',
        'j&t' => 'jnt',
        'jnt express' => 'jnt',
        'jne' => 'jne',
        'jne express' => 'jne',
        'jne reguler' => 'jne',
        'sicepat' => 'sicepat',
        'si cepat' => 'sicepat',
        'sicepat express' => 'sicepat',
        'anteraja' => 'anteraja',
        'ninja express' => 'ninja',
        'ninja van' => 'ninja',
        'ninja xpress' => 'ninja',
        'shopee express' => 'spx',
        'spx' => 'spx',
        'spx express' => 'spx',
        'spx standard' => 'spx',
        'spx instant' => 'spx_instant',
        'shopee xpress' => 'spx',
        'id express' => 'idexpress',
        'idx' => 'idexpress',
        'lion parcel' => 'lionparcel',
        'gosend' => 'gosend',
        'grabexpress' => 'grabexpress',
        'grab express' => 'grabexpress',
        'grab' => 'grabexpress',
        'pos indonesia' => 'pos',
        'tiki' => 'tiki',
        'sap express' => 'sap',
        'sap' => 'sap',
        'wahana' => 'wahana',
        'rex' => 'rex',
        'tiktok shipping' => 'tiktok_shipping',
        'lazada logistics' => 'lazada_logistics',
        'lex id' => 'lex',
    ];

    /**
     * Canonical courier codes that are inherently an instant / same-day service.
     * These drive couriers.type so they show up under the "instant" courier filter,
     * regardless of how an individual order labels its service.
     */
    private array $instantCourierCodes = [
        'spx_instant',
        'gosend',
        'grabexpress',
    ];

    /**
     * Resolve a raw channel provider name to a canonical courier code.
     */
    public function resolveCode(string $name): string
    {
        $lower = strtolower(trim($name));

        if (isset($this->codeAliases[$lower])) {
            return $this->codeAliases[$lower];
        }

        // Match the most specific keyword first (e.g. "spx instant" before "spx") so a
        // name like "SPX Instant - 2 Jam" resolves to spx_instant, not spx.
        $keywords = array_keys($this->codeAliases);
        usort($keywords, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return $this->codeAliases[$keyword];
            }
        }

        return str_replace([' ', '.', '-'], '_', $lower);
    }

    /**
     * Master courier category for a canonical code (couriers.type). Inherently instant
     * brands are tagged INSTANT; everything else defaults to REGULAR.
     */
    public function resolveCourierType(string $code): string
    {
        return in_array($code, $this->instantCourierCodes, true) ? 'INSTANT' : 'REGULAR';
    }

    /**
     * Classify a raw provider/service name into a manifest service tier.
     *
     * Mirrors the values stored in shipments.shipment_type so resolved orders can be
     * grouped straight into a shipment.
     */
    public function resolveShipmentType(string $name): string
    {
        $lower = strtolower(trim($name));

        // NOTE: a bare "express" keyword is intentionally NOT treated as a service tier.
        // Most Indonesian courier brand names embed it (J&T Express, JNE Express, ID
        // Express, SAP Express, ...), so matching it would misclassify regular services.
        // Tier signals must be explicit (instant / same day / next day / cargo).
        return match (true) {
            str_contains($lower, 'instant') || str_contains($lower, 'instan') || str_contains($lower, 'sameday') || str_contains($lower, 'same day') || str_contains($lower, 'same-day') => 'INSTANT',
            str_contains($lower, 'next day') || str_contains($lower, 'nextday') => 'EXPRESS',
            str_contains($lower, 'cargo') || str_contains($lower, 'trucking') || str_contains($lower, 'kargo') => 'CARGO',
            default => 'REGULAR',
        };
    }

    /**
     * Upsert the canonical courier + the channel mapping for a raw provider.
     *
     * Auto-resolved mappings are kept in sync on every call; mappings a human has
     * marked verified are left untouched.
     */
    public function record(string $channelCode, string $externalName, ?string $externalId = null, ?string $tenantId = null): ?CourierChannelMapping
    {
        $externalName = trim($externalName);
        if ($externalName === '') {
            return null;
        }

        $code = $this->resolveCode($externalName);
        $shipmentType = $this->resolveShipmentType($externalName);
        $courierType = $this->resolveCourierType($code);

        $courier = Courier::firstOrCreate(
            ['code' => $code],
            ['name' => $externalName, 'is_active' => true, 'type' => $courierType],
        );

        // Promote an existing courier to INSTANT when its code is an inherently instant
        // brand (e.g. spx_instant) but it was created earlier with the REGULAR default.
        if ($courierType === 'INSTANT' && $courier->type !== 'INSTANT') {
            $courier->update(['type' => 'INSTANT']);
        }

        $existing = CourierChannelMapping::where('channel_code', $channelCode)
            ->where('external_name', $externalName)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($existing && $existing->is_verified) {
            return $existing;
        }

        return CourierChannelMapping::updateOrCreate(
            [
                'channel_code' => $channelCode,
                'external_name' => $externalName,
                'tenant_id' => $tenantId,
            ],
            [
                'external_id' => $externalId,
                'courier_id' => $courier->id,
                'shipment_type' => $shipmentType,
            ],
        );
    }

    /**
     * Resolve the canonical courier + shipment tier for a single sales order.
     *
     * Falls back to on-the-fly resolution (without persisting) when no stored mapping
     * exists yet, so manifest grouping still works before the first sync.
     */
    public function resolveForOrder(object $order): ?array
    {
        $channelCode = $this->channelCodeFor($order);
        $providerName = trim((string) ($order->shipping_provider ?? ''));

        if ($providerName === '') {
            return null;
        }

        $mapping = CourierChannelMapping::with('courier')
            ->where('channel_code', $channelCode)
            ->where('external_name', $providerName)
            ->first();

        if ($mapping) {
            return [
                'courier_code' => $mapping->courier?->code ?? $this->resolveCode($providerName),
                'courier_name' => $mapping->courier?->name ?? $providerName,
                'shipment_type' => $mapping->shipment_type,
            ];
        }

        $code = $this->resolveCode($providerName);

        return [
            'courier_code' => $code,
            'courier_name' => Courier::where('code', $code)->value('name') ?? $providerName,
            'shipment_type' => $this->resolveShipmentType($providerName),
        ];
    }

    /**
     * Group orders from any mix of channels into manifest buckets keyed by
     * "<courier_code>|<shipment_type>". Orders that share the same canonical courier
     * and service tier land in the same bucket regardless of their source channel.
     *
     * @return array<string, array{courier_code: string, courier_name: string, shipment_type: string, orders: array}>
     */
    public function groupOrdersForManifest(iterable $orders): array
    {
        $groups = [];

        foreach ($orders as $order) {
            $resolved = $this->resolveForOrder($order);

            if (! $resolved) {
                continue;
            }

            $key = $resolved['courier_code'] . '|' . $resolved['shipment_type'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'courier_code' => $resolved['courier_code'],
                    'courier_name' => $resolved['courier_name'],
                    'shipment_type' => $resolved['shipment_type'],
                    'orders' => [],
                ];
            }

            $groups[$key]['orders'][] = $order;
        }

        return $groups;
    }

    /**
     * Best-effort channel code for an order. Sales orders store the marketplace in
     * `source`; normalise common variants to the channel codes used by mappings.
     */
    private function channelCodeFor(object $order): string
    {
        $source = strtolower(trim((string) ($order->source ?? '')));

        return match (true) {
            str_contains($source, 'shopee') => 'shopee',
            str_contains($source, 'tiktok') || str_contains($source, 'tts') => 'tiktok',
            str_contains($source, 'lazada') => 'lazada',
            default => $source,
        };
    }
}
