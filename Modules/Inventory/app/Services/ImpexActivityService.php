<?php

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Repositories\ImpexActivityRepository;
use Modules\Notification\Services\NotificationDispatcher;

class ImpexActivityService
{
    public function __construct(
        private ImpexActivityRepository $repository,
        private NotificationDispatcher $notifications,
    ) {
    }

    private function activityLink(ImpexActivity $activity): string
    {
        return '/dashboard/aktivitas-impex?highlight=' . $activity->id;
    }

    private function activityLabel(ImpexActivity $activity): string
    {
        return trim(($activity->activity_type ?? 'Aktivitas') . ' ' . strtoupper($activity->direction ?? ''));
    }

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    public function details(string $activityId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->detailsFor($activityId);
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

        if ($activity->user_id) {
            $isExport = $activity->direction === 'export';
            $this->notifications->toUser($activity->user_id, [
                'type' => $isExport ? 'impex_export_ready' : 'impex_import_completed',
                'title' => $isExport ? 'Export siap diunduh' : 'Import selesai',
                'message' => $isExport
                    ? "Berkas hasil {$this->activityLabel($activity)} siap diunduh."
                    : "Proses {$this->activityLabel($activity)} berhasil.",
                'data' => [
                    'impex_activity_id' => $activity->id,
                    'file_url' => $fileUrl,
                    'link' => $this->activityLink($activity),
                ],
            ]);
        }

        return $activity;
    }

    public function markFailed(ImpexActivity $activity, string $errorMessage): ImpexActivity
    {
        $activity->update([
            'status' => ImpexActivity::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => Carbon::now(),
        ]);

        if ($activity->user_id) {
            $this->notifications->toUser($activity->user_id, [
                'type' => 'impex_import_failed',
                'title' => 'Import gagal',
                'message' => "Proses {$this->activityLabel($activity)} gagal: {$errorMessage}",
                'data' => [
                    'impex_activity_id' => $activity->id,
                    'error' => $errorMessage,
                    'link' => $this->activityLink($activity),
                ],
            ]);
        }

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
