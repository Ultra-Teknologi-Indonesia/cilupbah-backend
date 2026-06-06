<?php

namespace Modules\Region\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Region\Services\RegionService;

class RegionController extends Controller
{
    protected RegionService $regionService;

    public function __construct(RegionService $regionService)
    {
        $this->regionService = $regionService;
    }

    public function provinces(): JsonResponse
    {
        $data = $this->regionService->getProvinces();
        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }

    public function cities(): JsonResponse
    {
        $data = $this->regionService->getCities();
        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }

    public function districts(): JsonResponse
    {
        $data = $this->regionService->getDistricts();
        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }

    public function villages(): JsonResponse
    {
        $data = $this->regionService->getVillages();
        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }
}
