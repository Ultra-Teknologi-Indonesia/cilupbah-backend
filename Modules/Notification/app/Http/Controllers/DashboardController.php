<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Repositories\NotificationRepository;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected NotificationRepository $notifications
    ) {}

    public function taskCounts(Request $request): JsonResponse
    {
        return $this->successResponse($this->notifications->taskCounts());
    }
}
