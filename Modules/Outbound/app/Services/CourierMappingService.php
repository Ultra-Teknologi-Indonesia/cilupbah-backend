<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Collection;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Models\CourierChannelMapping;
use Modules\Outbound\Support\CourierNameNormalizer;
use Modules\Outbound\Support\InstantOrderClassifier;

class CourierMappingService
{

    private array $codeAliases = [
        'j&t express' => 'jnt',
        'j&t' => 'jnt',
        'jnt express' => 'jnt',
        'jnt' => 'jnt',
        'jne' => 'jne',
        'jne express' => 'jne',
        'jne reguler' => 'jne',
        'sicepat' => 'sicepat',
        'si cepat' => 'sicepat',
        'sicepat express' => 'sicepat',
        'anteraja' => 'anteraja',
        'antar aja' => 'anteraja',
        'ninja express' => 'ninja',
        'ninja van' => 'ninja',
        'ninja xpress' => 'ninja',
        'ninja' => 'ninja',
        'shopee express' => 'spx',
        'spx' => 'spx',
        'spx express' => 'spx',
        'spx standard' => 'spx',

        'spx instant' => 'spx',
        'shopee xpress' => 'spx',
        'id express' => 'idexpress',
        'idx' => 'idexpress',
        'lion parcel' => 'lionparcel',
        'lion' => 'lionparcel',
        'gosend' => 'gosend',
        'go-send same day' => 'gosend',
        'go-send instant' => 'gosend',
        'grabexpress' => 'grabexpress',
        'grab express' => 'grabexpress',
        'grab' => 'grabexpress',
        'pos indonesia' => 'pos',
        'pos' => 'pos',
        'tiki' => 'tiki',
        'sap express' => 'sap',
        'sap' => 'sap',
        'wahana' => 'wahana',
        'rex' => 'rex',
        'paxel' => 'paxel',
        'tiktok shipping' => 'tiktok_shipping',
        'lazada logistics' => 'lazada_logistics',
        'lex id' => 'lex',
    ];

    public function resolveCode(string $name): string
    {
        $lower = strtolower(CourierNameNormalizer::clean($name));

        if ($lower === '') {
            return '';
        }

        if (isset($this->codeAliases[$lower])) {
            return $this->codeAliases[$lower];
        }

        $keywords = array_keys($this->codeAliases);
        usort($keywords, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return $this->codeAliases[$keyword];
            }
        }

        return str_replace([' ', '.', '-'], '_', $lower);
    }

    public function resolveShipmentType(string $name): string
    {

        $clean = CourierNameNormalizer::clean($name);

        if (InstantOrderClassifier::isInstant($clean)) {
            return 'INSTANT';
        }

        $lower = strtolower($clean);

        return match (true) {
            str_contains($lower, 'next day') || str_contains($lower, 'nextday') => 'EXPRESS',
            str_contains($lower, 'cargo') || str_contains($lower, 'trucking') || str_contains($lower, 'kargo') => 'CARGO',
            default => 'REGULAR',
        };
    }

    public function resolveCourierId(?string $providerName): ?string
    {
        $code = $this->resolveCode((string) $providerName);
        if ($code === '') {
            return null;
        }

        return $this->activeCourierCodeMap()[$code] ?? null;
    }

    private ?array $courierCodeMap = null;

    private function activeCourierCodeMap(): array
    {
        if ($this->courierCodeMap !== null) {
            return $this->courierCodeMap;
        }

        $map = [];

        Courier::query()
            ->where('is_active', true)
            ->whereNull('tenant_id')
            ->orderByRaw('length(name)') 
            ->get(['id', 'name', 'code'])
            ->each(function (Courier $c) use (&$map) {
                foreach ([$this->resolveCode((string) $c->name), strtolower(trim((string) $c->code))] as $key) {
                    if ($key !== '' && ! isset($map[$key])) {
                        $map[$key] = $c->id;
                    }
                }
            });

        return $this->courierCodeMap = $map;
    }

    public function record(string $channelCode, string $externalName, ?string $externalId = null, ?string $tenantId = null): ?CourierChannelMapping
    {
        $externalName = trim($externalName);
        if ($externalName === '') {
            return null;
        }

        $code = $this->resolveCode($externalName);
        $shipmentType = $this->resolveShipmentType($externalName);

        $courier = Courier::firstOrCreate(
            ['code' => $code],
            ['name' => $externalName, 'is_active' => true],
        );

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
