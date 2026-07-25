<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Http\Requests\SaveBinMultiSkuRuleRequest;
use Modules\Warehouse\Http\Resources\BinMultiSkuRuleResource;
use Modules\Warehouse\Services\BinMultiSkuRuleService;

class BinMultiSkuRuleController extends Controller
{
    public function __construct(
        protected BinMultiSkuRuleService $ruleService
    ) {}

    public function index(string $locationId): JsonResponse
    {
        if ($response = $this->rejectUnlessStrict($locationId)) {
            return $response;
        }

        return $this->successResponse(
            BinMultiSkuRuleResource::collection($this->ruleService->rulesWithMatchCount($locationId)),
            'Daftar aturan rak multi-SKU berhasil diambil'
        );
    }

    public function suggestions(string $locationId): JsonResponse
    {
        if ($response = $this->rejectUnlessStrict($locationId)) {
            return $response;
        }

        return $this->successResponse(
            $this->ruleService->suggestedPatterns($locationId),
            'Daftar pola yang tersedia berhasil diambil'
        );
    }

    public function store(SaveBinMultiSkuRuleRequest $request, string $locationId): JsonResponse
    {
        if ($response = $this->rejectUnlessStrict($locationId)) {
            return $response;
        }

        try {
            $rule = $this->ruleService->createRule($locationId, $request->validated());
        } catch (QueryException $e) {
            return $this->errorResponse('Pola ini sudah terdaftar di lokasi ini.', 422);
        }

        return $this->successResponse(
            new BinMultiSkuRuleResource($rule),
            'Aturan rak multi-SKU berhasil dibuat',
            201
        );
    }

    public function update(SaveBinMultiSkuRuleRequest $request, string $locationId, string $ruleId): JsonResponse
    {
        if ($response = $this->rejectUnlessStrict($locationId)) {
            return $response;
        }

        $rule = $this->ruleService->findRule($locationId, $ruleId);
        if (! $rule) {
            return $this->errorResponse('Aturan tidak ditemukan.', 404);
        }

        try {
            $rule = $this->ruleService->updateRule($rule, $request->validated());
        } catch (QueryException $e) {
            return $this->errorResponse('Pola ini sudah terdaftar di lokasi ini.', 422);
        }

        return $this->successResponse(
            new BinMultiSkuRuleResource($rule),
            'Aturan rak multi-SKU berhasil diperbarui'
        );
    }

    public function destroy(string $locationId, string $ruleId): JsonResponse
    {
        if ($response = $this->rejectUnlessStrict($locationId)) {
            return $response;
        }

        $rule = $this->ruleService->findRule($locationId, $ruleId);
        if (! $rule) {
            return $this->errorResponse('Aturan tidak ditemukan.', 404);
        }

        $this->ruleService->deleteRule($rule);

        return $this->successResponse(null, 'Aturan rak multi-SKU berhasil dihapus');
    }

    protected function rejectUnlessStrict(string $locationId): ?JsonResponse
    {
        $location = $this->ruleService->findLocation($locationId);

        if (! $location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        if (! $location->enforcesStrictBinSku()) {
            return $this->errorResponse(
                'Aturan ini hanya berlaku untuk gudang kecil. Di gudang ini satu rak memang sudah boleh berisi banyak SKU.',
                422
            );
        }

        return null;
    }
}
