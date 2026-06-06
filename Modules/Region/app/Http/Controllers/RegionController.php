<?php

namespace Modules\Region\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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

    public function cities(Request $request): JsonResponse
    {
        $request->validate([
            'province_id' => 'required|string'
        ]);

        $data = $this->regionService->getCities($request->query('province_id'));
        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }

    public function districts(Request $request): JsonResponse
    {
        $request->validate([
            'city_id' => 'required|string'
        ]);

        $data = $this->regionService->getDistricts($request->query('city_id'));
        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }

    public function villages(Request $request): JsonResponse
    {
        $request->validate([
            'district_id' => 'required|string'
        ]);

        $data = $this->regionService->getVillages($request->query('district_id'));
        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }
}
