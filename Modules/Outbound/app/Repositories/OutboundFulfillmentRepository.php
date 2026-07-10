<?php

namespace Modules\Outbound\Repositories;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Outbound\Support\InstantOrderClassifier;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class OutboundFulfillmentRepository
{

    public function getPickers(?string $locationId, string $role): Collection
    {
        $query = User::query();

        if ($locationId !== null && $locationId !== '') {
            $query->where(function ($q) use ($locationId) {
                $q->where('warehouse_id', $locationId)
                  ->orWhereNull('warehouse_id');
            });
        }

        return $query->role($role)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function paginateStage(Builder $query, int $limit = 10)
    {
        return QueryBuilder::for($query->with(['items', 'items.product.media', 'items.product.product.media', 'location:id,location_name,location_code']))
            ->allowedFilters(
                AllowedFilter::exact('source'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('shipping_provider'),
                AllowedFilter::exact('channel_shop_id'),

                AllowedFilter::callback('channel_status', function ($query, $value) {
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $values = array_filter(array_map('trim', $values));
                    if (! empty($values)) $query->whereIn('channel_status', $values);
                }),

                AllowedFilter::callback('payment', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'cod') $query->where('is_cod', true);
                    elseif ($v === 'noncod') $query->where(function ($q) { $q->where('is_cod', false)->orWhereNull('is_cod'); });
                }),

                AllowedFilter::callback('courier_type', function ($query, $value) {
                    $v = strtolower((string) $value);
                    $rx = InstantOrderClassifier::REGEX;
                    if ($v === 'instant') {
                        $query->where(function ($q) use ($rx) {
                            $q->whereRaw('shipping_provider ~* ?', [$rx])
                              ->orWhereRaw('shipping_type ~* ?', [$rx]);
                        });
                    } elseif ($v === 'regular') {
                        $query->where(function ($q) use ($rx) {
                            $q->where(function ($qq) use ($rx) {
                                $qq->whereNull('shipping_provider')
                                   ->orWhereRaw('shipping_provider !~* ?', [$rx]);
                            })->where(function ($qq) use ($rx) {
                                $qq->whereNull('shipping_type')
                                   ->orWhereRaw('shipping_type !~* ?', [$rx]);
                            });
                        });
                    }
                }),

                AllowedFilter::callback('label_printed', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'yes') $query->whereNotNull('shipping_label_prepared_at');
                    elseif ($v === 'no') $query->whereNull('shipping_label_prepared_at');
                }),

                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) $query->whereDate('transaction_date', '>=', $value);
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) $query->whereDate('transaction_date', '<=', $value);
                }),

                AllowedFilter::callback('exclude_transit', function ($query, $value) {
                    if (in_array(strtolower((string) $value), ['1', 'true', 'yes'], true)) {
                        $query->whereHas('location', function ($q) { $q->where('is_warehouse', true); });
                    }
                }),
            )
            ->allowedSearch('salesorder_no', 'channel_order_no', 'tracking_number')
            ->allowedSorts('transaction_date', 'created_at', 'grand_total')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }
}
