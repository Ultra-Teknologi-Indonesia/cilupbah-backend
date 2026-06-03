<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('order::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('order::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('order::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('order::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}

    #[OA\Get(
        path: '/api/v1/{marketplace}/orders',
        operationId: 'getMarketplaceOrder',
        summary: 'Get orders from a specific marketplace',
        description: 'Returns list of orders for the specified marketplace (e.g., tiktok, shopee, tokopedia) after syncing from the marketplace.',
        tags: ['Orders'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'marketplace',
        description: 'Marketplace name',
        required: true,
        in: 'path',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful operation',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'marketplace', type: 'string', example: 'tiktok'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string'))
            ]
        )
    )]
    public function getMarketplaceOrder($marketplace)
    {
        if ($marketplace === 'tiktok') {
            $shopId = request()->header('X-Shop-Id');
            if ($shopId) {
                try {
                    $tiktokService = app(\Modules\Channel\Services\TikTokOrderService::class);
                    $tiktokService->pullOrders($shopId);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("TikTok order pull failed: " . $e->getMessage());
                }
            }
        }

        $orders = DB::table('orders')
            ->join('channel_shops', 'orders.shop_id', '=', 'channel_shops.shop_id')
            ->where('channel_shops.channel_name', $marketplace)
            ->select('orders.*')
            ->get();

        $orderIds = $orders->pluck('id')->toArray();
        
        if (empty($orderIds)) {
            return response()->json([
                'status' => 'success',
                'marketplace' => $marketplace,
                'message' => "Successfully retrieved orders for {$marketplace}",
                'data' => []
            ]);
        }
        
        $items = DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->groupBy('order_id');

        $orders = $orders->map(function ($order) use ($items) {
            $order->items = $items->get($order->id) ?? [];
            return $order;
        });

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => "Successfully retrieved orders for {$marketplace}",
            'data' => $orders
        ]);
    }

    #[OA\Get(
        path: '/api/v1/{marketplace}/orders/{order_id}',
        operationId: 'showMarketplaceOrder',
        summary: 'Get order detail from a specific marketplace',
        tags: ['Orders'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'order_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function showMarketplaceOrder($marketplace, $order_id)
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'data' => [
                'order_id' => $order_id,
                'status' => 'PENDING'
            ]
        ]);
    }

    #[OA\Post(
        path: '/api/v1/{marketplace}/orders/{order_id}/ship',
        operationId: 'shipMarketplaceOrder',
        summary: 'Ship order on a specific marketplace',
        tags: ['Orders'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'order_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function shipMarketplaceOrder($marketplace, $order_id)
    {
        if ($marketplace === 'tiktok') {
            $shopId = request()->header('X-Shop-Id');
            if ($shopId) {
                try {
                    $tiktokService = app(\Modules\Channel\Services\TikTokOrderService::class);
                    $tiktokService->acceptOrder($shopId, $order_id);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("TikTok order accept failed: " . $e->getMessage());
                }
            }
        }
        
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => "Order {$order_id} marked as shipped on {$marketplace}"
        ]);
    }

    #[OA\Get(
        path: '/api/v1/{marketplace}/orders/{order_id}/shipping-document',
        operationId: 'shippingDocumentMarketplaceOrder',
        summary: 'Get shipping document for order',
        tags: ['Orders'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'order_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function shippingDocumentMarketplaceOrder($marketplace, $order_id)
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'data' => [
                'document_url' => 'https://example.com/shipping-label.pdf'
            ]
        ]);
    }
}
