<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Support\ChannelStatusNormalizer;
use Modules\Sales\Support\DisputeOutcomeNormalizer;
use Modules\Sales\Support\WmsStatusNormalizer;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeChannelStatus();
        $this->normalizeWmsStatus();
        $this->normalizeMarketplaceDecision();
    }

    public function down(): void
    {

    }

    private function normalizeChannelStatus(): void
    {
        if (! Schema::hasColumn('sales_orders', 'channel_status') || ! Schema::hasColumn('sales_orders', 'source')) {
            return;
        }

        $distincts = DB::table('sales_orders')
            ->select('source', 'channel_status')
            ->whereNotNull('channel_status')
            ->where('channel_status', '<>', '')
            ->distinct()
            ->get();

        foreach ($distincts as $row) {
            $normalized = ChannelStatusNormalizer::normalize($row->source, $row->channel_status);
            if ($normalized === null) {
                continue;
            }
            if ($normalized->value === $row->channel_status) {
                continue;
            }

            $query = DB::table('sales_orders')
                ->where('channel_status', $row->channel_status);

            if ($row->source === null) {
                $query->whereNull('source');
            } else {
                $query->where('source', $row->source);
            }

            $query->update(['channel_status' => $normalized->value]);
        }
    }

    private function normalizeWmsStatus(): void
    {
        if (! Schema::hasColumn('sales_orders', 'wms_status') || ! Schema::hasColumn('sales_orders', 'source')) {
            return;
        }

        $distincts = DB::table('sales_orders')
            ->select('source', 'wms_status')
            ->whereNotNull('wms_status')
            ->where('wms_status', '<>', '')
            ->distinct()
            ->get();

        foreach ($distincts as $row) {
            $normalized = WmsStatusNormalizer::normalize($row->source, $row->wms_status);
            if ($normalized === null || $normalized->value === $row->wms_status) {
                continue;
            }

            $query = DB::table('sales_orders')->where('wms_status', $row->wms_status);
            if ($row->source === null) {
                $query->whereNull('source');
            } else {
                $query->where('source', $row->source);
            }
            $query->update(['wms_status' => $normalized->value]);
        }
    }

    private function normalizeMarketplaceDecision(): void
    {
        if (! Schema::hasTable('sales_returns') || ! Schema::hasColumn('sales_returns', 'marketplace_decision')) {
            return;
        }

        $rows = DB::table('sales_returns as sr')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'sr.order_id')
            ->select('sr.marketplace_decision', 'so.source', DB::raw('COUNT(*) as tally'))
            ->whereNotNull('sr.marketplace_decision')
            ->where('sr.marketplace_decision', '<>', '')
            ->groupBy('sr.marketplace_decision', 'so.source')
            ->get();

        foreach ($rows as $row) {
            $normalized = DisputeOutcomeNormalizer::normalize($row->source, $row->marketplace_decision);
            if ($normalized === null) {
                continue;
            }
            if ($normalized->value === $row->marketplace_decision) {
                continue;
            }

            DB::table('sales_returns')
                ->whereIn('order_id', function ($q) use ($row) {
                    $q->from('sales_orders')->select('id');
                    if ($row->source === null) {
                        $q->whereNull('source');
                    } else {
                        $q->where('source', $row->source);
                    }
                })
                ->where('marketplace_decision', $row->marketplace_decision)
                ->update(['marketplace_decision' => $normalized->value]);
        }
    }
};
