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
            ['sync_enabled' => $this->service->isEnabled()],
            'Pengaturan sinkronisasi channel berhasil diambil'
        );
    }

    public function update(UpdateChannelSyncSettingRequest $request): JsonResponse
    {
        $setting = $this->service->setEnabled((bool) $request->validated()['sync_enabled']);

        return $this->successResponse(
            ['sync_enabled' => (bool) $setting->sync_enabled],
            $setting->sync_enabled
                ? 'Sinkronisasi channel diaktifkan'
                : 'Sinkronisasi channel dijeda'
        );
    }
}
