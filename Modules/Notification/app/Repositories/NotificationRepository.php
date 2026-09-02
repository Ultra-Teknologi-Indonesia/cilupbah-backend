<?php

namespace Modules\Notification\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\StockOpname;
use Modules\Notification\Models\DeviceToken;
use Modules\Notification\Models\Notification;
use Modules\Notification\Support\NotificationVisibility;
use Modules\Outbound\Models\Picklist;
use Spatie\QueryBuilder\QueryBuilder;

class NotificationRepository
{
    public function paginatedForUser(string $userId, Request $request): LengthAwarePaginator
    {
        $query = NotificationVisibility::applyVisible(Notification::where('user_id', $userId));

        return QueryBuilder::for($query)
            ->when(
                $request->has('is_read'),
                fn ($q) => $q->where('is_read', filter_var($request->is_read, FILTER_VALIDATE_BOOLEAN))
            )
            ->defaultSort('-created_at')
            ->allowedSorts('created_at')
            ->paginate(min((int) request('per_page', 20), 100))
            ->appends(request()->query());
    }

    public function countUnreadForUser(string $userId): int
    {
        return NotificationVisibility::applyVisible(Notification::where('user_id', $userId))
            ->where('is_read', false)
            ->count();
    }

    public function findForUser(string $userId, string $id): Notification
    {
        return NotificationVisibility::applyVisible(Notification::where('user_id', $userId))
            ->findOrFail($id);
    }

    public function markAllReadForUser(string $userId): void
    {
        NotificationVisibility::applyVisible(Notification::where('user_id', $userId))
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function taskCounts(): array
    {

        $scoped = fn ($query, string $column) => \App\Support\WarehouseAccess::apply($query, $column);

        return [
            'putaway' => $scoped(Putaway::whereIn('status', ['NOT_STARTED', 'IN_PROGRESS']), 'location_id')->count(),
            'stock_opname' => $scoped(StockOpname::whereIn('status', ['DRAFT', 'IN_PROGRESS']), 'location_id')->count(),
            'picklist' => $scoped(Picklist::whereIn('status', ['DRAFT', 'IN_PROGRESS']), 'location_id')->count(),
            'transfer_masuk' => $scoped(InventoryTransfer::where('status', 'IN_TRANSIT'), 'destination_location_id')->count(),
            'transfer_keluar' => $scoped(InventoryTransfer::whereIn('status', ['DRAFT', 'IN_TRANSIT']), 'source_location_id')->count(),
        ];
    }

    public function tokensForUser(string $userId): Collection
    {
        return DeviceToken::where('user_id', $userId)->pluck('fcm_token');
    }

    public function updateOrCreateToken(string $userId, string $fcmToken, ?string $deviceId, ?string $platform): DeviceToken
    {

        return DeviceToken::updateOrCreate(
            [
                'fcm_token' => $fcmToken,
            ],
            [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'platform' => $platform ?? 'android',
            ],
        );
    }

    public function deleteTokenForUser(string $userId, string $fcmToken): void
    {
        DeviceToken::where('user_id', $userId)
            ->where('fcm_token', $fcmToken)
            ->delete();
    }

    public function deleteToken(string $fcmToken): void
    {
        DeviceToken::where('fcm_token', $fcmToken)->delete();
    }
}
