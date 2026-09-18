<?php

namespace Modules\Outbound\Repositories;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Support\FilterValues;
use Modules\Product\Repositories\ProductRepository;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Role;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class OutboundFulfillmentRepository
{
    private const STAGE_SORTS = [
        'transaction_date',
        'created_at',
        'grand_total',
        'salesorder_no',
        'status',
    ];

    public function __construct(
        protected ProductRepository $productRepository,
    ) {}

    public function getPickers(?string $locationId, string $role): Collection
    {
        $query = User::query();

        if ($locationId !== null && $locationId !== '') {
            $query->where(function ($q) use ($locationId) {
                $q->where('warehouse_id', $locationId)
                    ->orWhereNull('warehouse_id');
            });
        }

        $roles = match ($role) {
            'packer', 'checker' => ['checker', 'packer'],
            'picker' => ['picker'],
            default => [$role],
        };

        $existingRoles = Role::whereIn('name', $roles)->pluck('name')->all();

        if (empty($existingRoles)) {
            return collect();
        }

        return $query->role($existingRoles)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function paginateStage(
        Builder $query,
        int $limit = 10,
        array $extraSelects = [],
        bool $latestFirst = false,
        bool $lightweight = false,
    ) {
        if ($lightweight) {
            $query->select([
                'sales_orders.id',
                'sales_orders.salesorder_no',
                'sales_orders.channel_order_no',
                'sales_orders.channel_buyer_id',
                'sales_orders.customer_name',
                'sales_orders.shipping_full_name',
                'sales_orders.source',
                'sales_orders.commerce_platform',
                'sales_orders.channel_shop_id',
                'sales_orders.is_manual',
                'sales_orders.status',
                'sales_orders.is_paid',
                'sales_orders.grand_total',
                'sales_orders.actual_shipping_fee',
                'sales_orders.order_weight_gram',
                'sales_orders.transaction_date',
                'sales_orders.location_id',
                'sales_orders.tracking_number',
                'sales_orders.shipping_provider',
                'sales_orders.is_cod',
                'sales_orders.priority_fulfillment',
                'sales_orders.is_split_order',
                'sales_orders.channel_status',
                'sales_orders.is_canceled',
                'sales_orders.cancel_requested_at',
                'sales_orders.cancel_reason',
                'sales_orders.cancel_accepted_at',
                'sales_orders.ship_by_date',
                'sales_orders.pickup_done_time',
                'sales_orders.days_to_ship',
                'sales_orders.dropshipper_name',
                'sales_orders.dropshipper_phone',
                'sales_orders.channel_instant',
                'sales_orders.resolved_shipment_type',
                'sales_orders.shipping_type',
                'sales_orders.driver_call_status',
                'sales_orders.driver_call_message',
                'sales_orders.driver_call_attempted_at',
                'sales_orders.created_at',
            ]);
        }

        if (in_array('picker_name', $extraSelects, true)) {
            $query->addSelect([
                'picker_name' => DB::table('picklists')
                    ->join('picklist_items', 'picklist_items.picklist_id', '=', 'picklists.id')
                    ->join('users', 'users.id', '=', 'picklists.picker_id')
                    ->whereColumn('picklist_items.order_id', 'sales_orders.id')
                    ->whereIn('picklists.status', [
                        Picklist::STATUS_DRAFT,
                        Picklist::STATUS_IN_PROGRESS,
                        Picklist::STATUS_COMPLETED,
                    ])
                    ->orderByDesc('picklists.created_at')
                    ->limit(1)
                    ->select('users.name'),
            ]);
        }

        if (in_array('packer_name', $extraSelects, true)) {
            $query->addSelect([
                'packer_name' => DB::table('packlists')
                    ->join('users', 'users.id', '=', 'packlists.packer_id')
                    ->whereColumn('packlists.order_id', 'sales_orders.id')
                    ->where('packlists.status', Packlist::STATUS_COMPLETED)
                    ->orderByDesc('packlists.completed_at')
                    ->limit(1)
                    ->select('users.name'),
            ]);
        }

        if (in_array('picklist_ref', $extraSelects, true)) {
            $query->addSelect([
                'picklist_id' => DB::table('picklist_items')
                    ->join('picklists', 'picklists.id', '=', 'picklist_items.picklist_id')
                    ->whereColumn('picklist_items.order_id', 'sales_orders.id')
                    ->whereIn('picklists.status', [
                        Picklist::STATUS_DRAFT,
                        Picklist::STATUS_IN_PROGRESS,
                        Picklist::STATUS_COMPLETED,
                    ])
                    ->orderByDesc('picklists.created_at')
                    ->limit(1)
                    ->select('picklists.id'),
                'picklist_no' => DB::table('picklist_items')
                    ->join('picklists', 'picklists.id', '=', 'picklist_items.picklist_id')
                    ->whereColumn('picklist_items.order_id', 'sales_orders.id')
                    ->whereIn('picklists.status', [
                        Picklist::STATUS_DRAFT,
                        Picklist::STATUS_IN_PROGRESS,
                        Picklist::STATUS_COMPLETED,
                    ])
                    ->orderByDesc('picklists.created_at')
                    ->limit(1)
                    ->select('picklists.picklist_no'),
            ]);
        }

        if (in_array('invoice_ref', $extraSelects, true)) {
            $query->addSelect([
                'invoice_id' => DB::table('sales_invoices')
                    ->whereColumn('sales_invoices.order_id', 'sales_orders.id')
                    ->where('sales_invoices.status', '!=', SalesInvoice::STATUS_CANCELLED)
                    ->orderByDesc('sales_invoices.created_at')
                    ->limit(1)
                    ->select('sales_invoices.id'),
                'invoice_no' => DB::table('sales_invoices')
                    ->whereColumn('sales_invoices.order_id', 'sales_orders.id')
                    ->where('sales_invoices.status', '!=', SalesInvoice::STATUS_CANCELLED)
                    ->orderByDesc('sales_invoices.created_at')
                    ->limit(1)
                    ->select('sales_invoices.invoice_number'),
            ]);
        }

        if (filled(request()->query('sort'))) {

            $query->reorder();
        } elseif ($latestFirst) {
            $query->reorder()->orderByDesc('sales_orders.created_at');
        } else {
            $query->orderByRaw("CASE WHEN channel_instant IS TRUE
                OR (channel_instant IS NULL
                    AND (source IS NULL OR source NOT IN ('shopee', 'tiktok', 'lazada'))
                    AND resolved_shipment_type IN ('INSTANT', 'SAME_DAY'))
                THEN 0 ELSE 1 END ASC")
                ->orderByRaw('ship_by_date ASC NULLS LAST');
        }

        $relations = $lightweight
            ? [
                'items:id,order_id,item_id,sku,description,qty_in_base',
                'location:id,location_name',
            ]
            : [
                'items',
                'items.product.media',
                'items.product.product.media',
                'location:id,location_name,location_code',
            ];

        $paginator = QueryBuilder::for($query->with($relations))
            ->allowedFilters(
                AllowedFilter::exact('source'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::callback('shipping_provider', function ($query, $value) {
                    $values = FilterValues::list($value);
                    if (! empty($values)) {
                        $query->whereIn('shipping_provider', $values);
                    }
                }),
                AllowedFilter::exact('channel_shop_id'),

                AllowedFilter::callback('channel_status', function ($query, $value) {
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $values = array_filter(array_map('trim', $values));
                    if (! empty($values)) {
                        $query->whereIn('channel_status', $values);
                    }
                }),

                AllowedFilter::callback('payment', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'cod') {
                        $query->where('is_cod', true);
                    } elseif ($v === 'noncod') {
                        $query->where(function ($q) {
                            $q->where('is_cod', false)->orWhereNull('is_cod');
                        });
                    }
                }),

                AllowedFilter::callback('courier_type', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'instant') {
                        $query->where(function ($q) {
                            $q->where('channel_instant', true)
                                ->orWhere(function ($qq) {
                                    $qq->whereNull('channel_instant')
                                        ->where(function ($qqq) {
                                            $qqq->whereNull('source')
                                                ->orWhereNotIn('source', ['shopee', 'tiktok', 'lazada']);
                                        })
                                        ->whereIn('resolved_shipment_type', ['INSTANT', 'SAME_DAY']);
                                });
                        });
                    } elseif ($v === 'regular') {
                        $query->where(function ($q) {
                            $q->where('channel_instant', false)
                                ->orWhere(function ($qq) {
                                    $qq->whereNull('channel_instant')
                                        ->where(function ($qqq) {
                                            $qqq->whereNull('resolved_shipment_type')
                                                ->orWhereNotIn('resolved_shipment_type', ['INSTANT', 'SAME_DAY'])
                                                ->orWhereIn('source', ['shopee', 'tiktok', 'lazada']);
                                        });
                                });
                        });
                    }
                }),

                AllowedFilter::callback('awb', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'yes') {
                        $query->whereNotNull('tracking_number')->where('tracking_number', '<>', '');
                    } elseif ($v === 'no') {
                        $query->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''));
                    }
                }),

                AllowedFilter::callback('label_printed', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'yes') {
                        $query->whereNotNull('shipping_label_prepared_at');
                    } elseif ($v === 'no') {
                        $query->whereNull('shipping_label_prepared_at');
                    }
                }),

                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) {
                        $query->whereDate('transaction_date', '>=', $value);
                    }
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) {
                        $query->whereDate('transaction_date', '<=', $value);
                    }
                }),

                AllowedFilter::callback('exclude_transit', function ($query, $value) {
                    if (in_array(strtolower((string) $value), ['1', 'true', 'yes'], true)) {
                        $query->whereHas('location', function ($q) {
                            $q->where('is_warehouse', true);
                        });
                    }
                }),
            )
            ->allowedSearch(...array_merge(SalesOrder::SEARCH_COLUMNS, [
                'completedPicklists.picklist_no',
                'items.sku',
                'items.description',
            ]))
            ->allowedSorts(...self::STAGE_SORTS)
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());

        $labelCapabilities = (array) config('channel_print_capabilities', []);

        $paginator->getCollection()->transform(function (SalesOrder $order) use ($labelCapabilities): SalesOrder {
            $source = strtolower(trim((string) ($order->source ?? '')));
            $capability = $labelCapabilities[$source] ?? null;

            $order->setAttribute(
                'shipping_label_supported',
                is_array($capability)
                    && (! empty($capability['document_types']) || ! empty($capability['document_sizes'])),
            );

            return $order;
        });

        if ($lightweight) {
            $bundleComponents = $this->productRepository->bundleComponentsForVariants(
                $paginator->getCollection()
                    ->flatMap(fn (SalesOrder $order) => $order->items->pluck('item_id'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            );

            $paginator->getCollection()->transform(function (SalesOrder $order) use ($bundleComponents): SalesOrder {
                foreach ($order->items as $item) {
                    $variantId = (string) ($item->item_id ?? '');
                    if (array_key_exists($variantId, $bundleComponents)) {
                        $item->setAttribute('bundle_components', $bundleComponents[$variantId]);
                    }
                }

                return $order;
            });

            return $paginator;
        }

        $bundleComponents = $this->productRepository->bundleComponentsForVariants(
            $paginator->getCollection()
                ->flatMap(fn (SalesOrder $order) => $order->items->pluck('item_id'))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        );

        $paginator->getCollection()->transform(function (SalesOrder $order) use ($bundleComponents): SalesOrder {
            $skuSet = [];
            $totalQty = 0;

            foreach ($order->items as $item) {
                $variantId = (string) ($item->item_id ?? '');
                if (array_key_exists($variantId, $bundleComponents)) {
                    $components = $bundleComponents[$variantId];
                    $item->setAttribute('bundle_components', $components);

                    foreach ($components as $component) {
                        $componentSku = trim((string) ($component['sku'] ?? ''));
                        if ($componentSku !== '') {
                            $skuSet[$componentSku] = true;
                        }
                        $totalQty += (int) $item->qty_in_base * (int) ($component['qty'] ?? 0);
                    }

                    continue;
                }

                $sku = trim((string) ($item->sku ?? ''));
                if ($sku !== '') {
                    $skuSet[$sku] = true;
                }
                $totalQty += (int) $item->qty_in_base;
            }

            $order->setAttribute('total_sku', count($skuSet));
            $order->setAttribute('total_qty', $totalQty);

            return $order;
        });

        return $paginator;
    }
}
