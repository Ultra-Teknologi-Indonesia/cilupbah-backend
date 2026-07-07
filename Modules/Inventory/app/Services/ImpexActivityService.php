<?php

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\ImpexActivity;

class ImpexActivityService
{
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = ImpexActivity::query()->with('user')->where('direction', $filters['direction']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['my_only']) && ! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function details(string $activityId): \Illuminate\Database\Eloquent\Collection
    {
        return ImpexActivity::findOrFail($activityId)->details()->orderBy('created_at')->get();
    }

    public function record(
        string $direction,
        string $activityType,
        ?string $userId = null,
        ?string $locationName = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): ImpexActivity {
        return ImpexActivity::create([
            'direction' => $direction,
            'activity_type' => $activityType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'user_id' => $userId,
            'location_name' => $locationName,
            'status' => ImpexActivity::STATUS_PENDING,
            'started_at' => Carbon::now(),
        ]);
    }

    public function recordCompleted(string $direction, string $activityType, ?string $userId = null): ImpexActivity
    {
        return ImpexActivity::create([
            'direction' => $direction,
            'activity_type' => $activityType,
            'user_id' => $userId,
            'status' => ImpexActivity::STATUS_SUCCESS,
            'progress_percentage' => 100,
            'started_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
        ]);
    }

    public function findBySource(string $sourceType, string $sourceId): ?ImpexActivity
    {
        return ImpexActivity::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->latest('created_at')
            ->first();
    }

    public function markProcessing(ImpexActivity $activity, ?int $progressPercentage = null): ImpexActivity
    {
        $activity->update([
            'status' => ImpexActivity::STATUS_PROCESSING,
            'progress_percentage' => $progressPercentage,
        ]);

        return $activity;
    }

    public function markSuccess(ImpexActivity $activity, ?string $fileUrl = null): ImpexActivity
    {
        $activity->update([
            'status' => ImpexActivity::STATUS_SUCCESS,
            'file_url' => $fileUrl,
            'progress_percentage' => 100,
            'completed_at' => Carbon::now(),
        ]);

        return $activity;
    }

    public function markFailed(ImpexActivity $activity, string $errorMessage): ImpexActivity
    {
        $activity->update([
            'status' => ImpexActivity::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => Carbon::now(),
        ]);

        return $activity;
    }

    public function addDetails(ImpexActivity $activity, array $details): void
    {
        foreach ($details as $detail) {
            $activity->details()->create([
                'reference_id' => $detail['reference_id'],
                'description' => $detail['description'],
                'created_at' => Carbon::now(),
            ]);
        }
    }
}
