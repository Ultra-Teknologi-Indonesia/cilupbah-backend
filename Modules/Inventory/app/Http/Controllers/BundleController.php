<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductRepository;

class BundleController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductRepository $productRepository) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::where('is_bundle', true)
            ->with([
                'variants' => fn ($q) => $q->select('id', 'product_id', 'sku', 'sell_price'),
                'bundleItems.component:id,product_id,sku,sell_price',
            ])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'ilike', "%{$v}%"))
            ->orderByDesc('created_at');

        return $this->successPaginatedResponse($query->paginate($request->input('limit', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100',
            'category_id' => 'nullable|exists:categories,id',
            'sell_price' => 'nullable|numeric|min:0',
            'components' => 'required|array|min:1',
            'components.*.variant_id' => [
                'required',
                Rule::exists('product_variants', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'components.*.qty' => 'required|integer|min:1',
        ], [
            'components.*.variant_id.exists' => 'Varian komponen tidak ditemukan atau tidak aktif.',
        ]);

        // Guard bundle-in-bundle: komponen tidak boleh berupa varian dari produk bundle.
        $componentVariantIds = array_values(array_filter(array_column($request->input('components'), 'variant_id')));
        if ($this->productRepository->variantIdsFromBundleProducts($componentVariantIds) !== []) {
            return $this->errorResponse(
                'Komponen bundle tidak boleh berisi produk bundle (bundle-in-bundle tidak diizinkan).',
                422,
            );
        }

        $id = $request->input('id');

        if ($id) {
            $product = Product::where('is_bundle', true)->findOrFail($id);
            $product->update($request->only(['name', 'sku']));
            $variant = $product->variants()->first();
            if ($variant && $request->has('sell_price')) {
                $variant->update(['sell_price' => $request->sell_price]);
            }
        } else {
            $categoryId = $request->input('category_id')
                ?? \Modules\Product\Models\Category::value('id');

            $product = Product::create([
                'name' => $request->name,
                'sku' => $request->sku,
                'category_id' => $categoryId,
                'is_bundle' => true,
                'status' => Product::STATUS_MASTER,
                'is_active' => true,
            ]);
            $variant = $product->variants()->create([
                'sku' => $request->sku,
                'sell_price' => $request->input('sell_price', 0),
                'is_active' => true,
            ]);
        }

        $product->bundleItems()->delete();
        foreach ($request->input('components') as $comp) {
            $product->bundleItems()->create([
                'component_variant_id' => $comp['variant_id'],
                'qty' => $comp['qty'],
            ]);
        }

        $product->load('variants:id,product_id,sku,sell_price', 'bundleItems.component:id,product_id,sku,sell_price');

        return $this->successResponse($product, $id ? 'Bundle updated.' : 'Bundle created.', $id ? 200 : 201);
    }
}
