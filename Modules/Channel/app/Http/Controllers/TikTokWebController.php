<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\TikTokProductService;

use Illuminate\Support\Facades\DB;

class TikTokWebController extends Controller
{
    public function index()
    {
        $products = DB::table('products')->orderBy('id', 'desc')->get();
        $orders = DB::table('orders')->orderBy('id', 'desc')->get();
        
        foreach ($orders as $order) {
            $items = DB::table('order_items')->where('order_id', $order->id)->get();
            $itemNames = [];
            foreach ($items as $item) {
                // Find variant to get product_id
                $variant = DB::table('product_variants')->where('sku', $item->sku)->first();
                if ($variant) {
                    $product = DB::table('products')->where('id', $variant->product_id)->first();
                    $itemNames[] = $product ? $product->name : $item->sku;
                } else {
                    $itemNames[] = $item->sku ?: 'Unknown Item';
                }
            }
            $order->product_names = implode(', ', $itemNames);
        }

        return view('channel::tiktok-sync', compact('products', 'orders'));
    }

    public function storeProduct(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'sku' => 'required|string',
            'price' => 'required|numeric',
            'description' => 'required|string'
        ]);

        $payload = [
            "brand_id" => 1,
            "category_id" => 1,
            "name" => $request->name,
            "sku" => $request->sku,
            "description" => $request->description,
            "search_keyword" => $request->name,
            "order_type" => "PREORDER",
            "indent_days" => 1,
            "weight" => 1.5,
            "length" => 12,
            "width" => 10,
            "height" => 4,
            "condition" => "NEW",
            "is_cod_allowed" => false,
            "danger_level" => 0,
            "is_draft" => false,
            "showcase_id" => null,
            "is_active" => true,
            "specifications" => [
                [
                    "attribute_id" => 1,
                    "attribute_option_id" => null,
                    "text_value" => "Standard"
                ]
            ],
            "media" => [
                [
                    "url" => "https://p16-oec-sg.ibyteimg.com/tos-alisg-i-aphluv4xwc-sg/a628081b10d642cda818106cebfae729~tplv-aphluv4xwc-origin-jpeg.jpeg",
                    "media_type" => "image",
                    "is_primary" => true,
                    "sort_order" => 1
                ]
            ],
            "variation_types" => [
                ["attribute_id" => 1, "sort_order" => 1],
                ["attribute_id" => 2, "sort_order" => 2]
            ],
            "variants" => [
                [
                    "sku" => $request->sku . "-V1",
                    "barcode" => "899" . rand(100000000, 999999999),
                    "buy_price" => $request->price * 0.8,
                    "sell_price" => $request->price,
                    "weight" => 1.2,
                    "length" => 12,
                    "width" => 10,
                    "height" => 4,
                    "is_serial_batch" => false,
                    "is_active" => true,
                    "options" => [
                        ["attribute_id" => 1, "value" => "Black"],
                        ["attribute_id" => 2, "value" => "L"]
                    ],
                    "media" => [
                        [
                            "url" => "https://p16-oec-sg.ibyteimg.com/tos-alisg-i-aphluv4xwc-sg/a628081b10d642cda818106cebfae729~tplv-aphluv4xwc-origin-jpeg.jpeg",
                            "media_type" => "image",
                            "is_primary" => true,
                            "sort_order" => 1
                        ]
                    ],
                    "wholesale_prices" => []
                ]
            ]
        ];

        try {
            $req = \Modules\Product\Http\Requests\CreateProductRequest::create('/api/v1/products', 'POST', $payload);
            $app = app();
            $req->setContainer($app);
            $req->setRedirector($app->make(\Illuminate\Routing\Redirector::class));
            $req->validateResolved();

            $controller = $app->make(\Modules\Product\Http\Controllers\ProductController::class);
            $response = $controller->store($req);
            
            if ($response->getStatusCode() === 201) {
                return redirect()->back()->with('success', "Produk baru berhasil dibuat!");
            } else {
                return redirect()->back()->with('error', "Gagal membuat produk: " . $response->getContent());
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', "Terjadi kesalahan sistem: " . $e->getMessage());
        }
    }

    public function pullOrders(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id' => 'required|string'
        ]);

        try {
            $count = $orderService->pullOrders($request->shop_id);
            return redirect()->back()->with('success', "Berhasil menarik {$count} pesanan!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', "Gagal menarik pesanan: " . $e->getMessage());
        }
    }

    public function declineOrder(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
            'reason' => 'required|string'
        ]);

        try {
            $orderService->declineOrder($request->shop_id, $request->order_id, $request->reason);
            return redirect()->back()->with('success', "Pesanan {$request->order_id} berhasil ditolak!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', "Gagal menolak pesanan: " . $e->getMessage());
        }
    }

    public function acceptOrder(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string'
        ]);

        try {
            $orderService->acceptOrder($request->shop_id, $request->order_id);
            return redirect()->back()->with('success', "Pesanan {$request->order_id} berhasil diterima (Processing)!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', "Gagal menerima pesanan: " . $e->getMessage());
        }
    }

    public function pushProduct(Request $request, TikTokProductService $productService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'product_id' => 'required|integer'
        ]);

        try {
            $productService->pushProduct($request->product_id, $request->shop_id);
            return redirect()->back()->with('success', "Produk (ID: {$request->product_id}) berhasil di-push ke TikTok!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', "Gagal push produk: " . $e->getMessage());
        }
    }

    public function syncProduct(Request $request, TikTokProductService $productService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'product_id' => 'required|integer'
        ]);

        try {
            $productService->syncPriceAndInventory($request->product_id, $request->shop_id);
            return redirect()->back()->with('success', "Stok & Harga Produk (ID: {$request->product_id}) berhasil di-sync ke TikTok!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', "Gagal sync produk: " . $e->getMessage());
        }
    }

    public function bulkPush(Request $request, TikTokProductService $productService)
    {
        $request->validate([
            'shop_id' => 'required|string'
        ]);

        $unsyncedProducts = DB::table('products')->whereNull('tiktok_product_id')->get();
        if ($unsyncedProducts->isEmpty()) {
            return redirect()->back()->with('success', "Semua produk sudah tersinkron!");
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($unsyncedProducts as $p) {
            try {
                $productService->pushProduct($p->id, $request->shop_id);
                $successCount++;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Bulk Push Failed for Product {$p->id}: " . $e->getMessage());
                $failCount++;
            }
        }

        $msg = "Bulk Push Selesai. Sukses: {$successCount}, Gagal: {$failCount}.";
        if ($failCount > 0) {
            return redirect()->back()->with('error', $msg);
        }
        return redirect()->back()->with('success', $msg);
    }
}
