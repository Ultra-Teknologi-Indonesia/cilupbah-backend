<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Requests\SaveBinMultiSkuRuleRequest;
use Modules\Warehouse\Http\Resources\BinMultiSkuRuleResource;
use Modules\Warehouse\Models\BinMultiSkuRule;
use Modules\Warehouse\Models\Location;
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

        $rules = BinMultiSkuRule::where('location_id', $locationId)
            ->orderBy('pattern')
            ->get()
            ->each(function (BinMultiSkuRule $rule) use ($locationId) {
                $rule->matched_count = $this->ruleService->countMatching($locationId, $rule->pattern);
            });

        return $this->successResponse(
            BinMultiSkuRuleResource::collection($rules),
            'Daftar aturan rak multi-SKU berhasil diambil'
        );
    }

    public function preview(Request $request, string $locationId): JsonResponse
    {
        if ($response = $this->rejectUnlessStrict($locationId)) {
            return $response;
        }

        $pattern = trim((string) $request->query('pattern', ''));

        if ($pattern === '') {
            return $this->successResponse([
                'pattern' => '',
                'matched_count' => 0,
                'samples' => [],
                'total_bins' => $this->ruleService->totalBins($locationId),
            ], 'Pratinjau pola berhasil diambil');
        }

        return $this->successResponse([
            'pattern' => $pattern,
            'matched_count' => $this->ruleService->countMatching($locationId, $pattern),
            'samples' => $this->ruleService->sampleMatching($locationId, $pattern),
            'total_bins' => $this->ruleService->totalBins($locationId),
        ], 'Pratinjau pola berhasil diambil');
    }

    public function store(SaveBinMultiSkuRuleRequest $request, string $locationId): JsonResponse
    {
        if ($response = $this->rejectUnlessStrict($locationId)) {
            return $response;
        }

        $data = $request->validated();
        $data['pattern'] = trim($data['pattern']);
        $data['location_id'] = $locationId;

        try {
            $rule = BinMultiSkuRule::create($data);
        } catch (QueryException $e) {
            return $this->errorResponse('Pola ini sudah terdaftar di lokasi ini.', 422);
        }

        $rule->matched_count = $this->ruleService->countMatching($locationId, $rule->pattern);

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

        $rule = BinMultiSkuRule::where('location_id', $locationId)->find($ruleId);
        if (! $rule) {
            return $this->errorResponse('Aturan tidak ditemukan.', 404);
        }

        $data = $request->validated();
        if (array_key_exists('pattern', $data)) {
            $data['pattern'] = trim($data['pattern']);
        }

        try {
            $rule->update($data);
        } catch (QueryException $e) {
            return $this->errorResponse('Pola ini sudah terdaftar di lokasi ini.', 422);
        }

        $rule->matched_count = $this->ruleService->countMatching($locationId, $rule->pattern);

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

        $rule = BinMultiSkuRule::where('location_id', $locationId)->find($ruleId);
        if (! $rule) {
            return $this->errorResponse('Aturan tidak ditemukan.', 404);
        }

        $rule->delete();

        return $this->successResponse(null, 'Aturan rak multi-SKU berhasil dihapus');
    }

    protected function rejectUnlessStrict(string $locationId): ?JsonResponse
    {
        $location = Location::find($locationId);

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
