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

    public function countries(): JsonResponse
    {
        $data = $this->regionService->getCountries();
        return $this->successResponse($data, 'success');
    }

    public function provinces(): JsonResponse
    {
        $data = $this->regionService->getProvinces();
        return $this->successResponse($data, 'success');
    }

    public function cities(Request $request, string $province_id): JsonResponse
    {
        $data = $this->regionService->getCities($province_id);
        return $this->successResponse($data, 'success');
    }

    public function districts(Request $request, string $city_id): JsonResponse
    {
        $data = $this->regionService->getDistricts($city_id);
        return $this->successResponse($data, 'success');
    }

    public function villages(Request $request, string $district_id): JsonResponse
    {
        $data = $this->regionService->getVillages($district_id);
        return $this->successResponse($data, 'success');
    }
}
