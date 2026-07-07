<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;

class ImpexActivityController extends Controller
{
    public function __construct(
        protected ImpexActivityService $activityService,
    ) {}

    public function index(Request $request, string $direction): JsonResponse
    {
        if (! in_array($direction, [ImpexActivity::DIRECTION_IMPORT, ImpexActivity::DIRECTION_EXPORT], true)) {
            return $this->errorResponse('Direction tidak valid.', 422);
        }

        $perPage = (int) $request->query('per_page', 25);

        $activities = $this->activityService->list([
            'direction' => $direction,
            'status' => $request->query('status'),
            'my_only' => $request->boolean('my_only'),
            'user_id' => $request->user()?->id,
        ], $perPage);

        $activities->getCollection()->transform(fn (ImpexActivity $activity) => [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'started_at' => optional($activity->started_at)->toIso8601String(),
            'completed_at' => optional($activity->completed_at)->toIso8601String(),
            'location_name' => $activity->location_name,
            'performed_by' => $activity->user?->email,
            'progress_percentage' => $activity->progress_percentage,
            'status' => $activity->status,
            'file_url' => $activity->file_url,
            'error_message' => $activity->error_message,
        ]);

        return $this->successPaginatedResponse($activities, 'Daftar aktivitas berhasil diambil.');
    }

    public function details(Request $request, string $direction, string $id): JsonResponse
    {
        $details = $this->activityService->details($id)->map(fn ($detail) => [
            'id' => $detail->reference_id,
            'description' => $detail->description,
        ]);

        return $this->successResponse($details, 'Keterangan berhasil diambil.');
    }

    public function recordExport(Request $request): JsonResponse
    {
        $request->validate([
            'activity_type' => 'required|string|max:100',
        ]);

        $this->activityService->recordCompleted(
            ImpexActivity::DIRECTION_EXPORT,
            $request->input('activity_type'),
            $request->user()?->id,
        );

        return $this->successResponse(null, 'Aktivitas export dicatat.');
    }
}
