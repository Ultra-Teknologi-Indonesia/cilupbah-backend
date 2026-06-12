<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Models\Promotion;

class PromotionController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Promotion::with('items.variant:id,product_id,sku')
            ->when($request->is_active !== null, fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->search, fn ($q, $v) => $q->where('name', 'ilike', "%{$v}%"))
            ->orderByDesc('created_at');

        return $this->successPaginatedResponse($query->paginate($request->input('limit', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:150',
            'type' => 'required|in:' . implode(',', Promotion::TYPES),
            'value' => 'required|numeric|min:0',
            'min_qty' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.variant_id' => 'required_with:items|exists:product_variants,id',
            'items.*.promo_price' => 'nullable|numeric|min:0',
        ]);

        $id = $request->input('id');
        $promo = $id ? Promotion::findOrFail($id) : new Promotion();

        $promo->fill($request->only([
            'name', 'type', 'value', 'min_qty',
            'start_date', 'end_date', 'is_active', 'notes',
        ]));
        $promo->created_by = $promo->created_by ?? ($request->user()->name ?? $request->user()->email);
        $promo->save();

        if ($request->has('items')) {
            $promo->items()->delete();
            foreach ($request->input('items', []) as $item) {
                $promo->items()->create($item);
            }
        }

        $promo->load('items.variant:id,product_id,sku');

        return $this->successResponse($promo, $id ? 'Promotion updated.' : 'Promotion created.', $id ? 200 : 201);
    }

    public function show(string $id): JsonResponse
    {
        $promo = Promotion::with('items.variant:id,product_id,sku,sell_price')
            ->findOrFail($id);

        return $this->successResponse($promo);
    }

    public function destroy(Request $request): JsonResponse
    {
        $ids = (array) ($request->input('id') ?? $request->input('ids', []));

        if (empty($ids)) {
            return $this->errorResponse('id or ids required.', 422);
        }

        $deleted = Promotion::whereIn('id', $ids)->delete();

        return $this->successResponse(['deleted' => $deleted], 'Promotion(s) deleted.');
    }
}
