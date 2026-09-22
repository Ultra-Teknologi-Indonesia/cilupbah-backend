<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Channel\Http\Requests\UpdateChannelSyncSettingRequest;
use Modules\Channel\Services\ChannelSyncSettingService;

class ChannelSyncSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ChannelSyncSettingService $service,
    ) {}

    public function show(): JsonResponse
    {
        return $this->successResponse(
            $this->service->status(),
            'Pengaturan sinkronisasi channel berhasil diambil'
        );
    }

    public function update(UpdateChannelSyncSettingRequest $request): JsonResponse
    {
        $setting = $this->service->setEnabled((bool) $request->validated()['sync_enabled']);

        return $this->successResponse(
            $this->service->status(),
            $setting->sync_enabled
                ? 'Sinkronisasi channel diaktifkan'
                : 'Sinkronisasi channel dijeda'
        );
    }
}
