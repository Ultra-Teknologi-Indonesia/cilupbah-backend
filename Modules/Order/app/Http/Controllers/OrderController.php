<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Order",
 *     description="API Endpoints for Order Module"
 * )
 *
 * @OA\Schema(
 *     schema="OrderItem",
 *     title="OrderItem",
 *     description="Order Item model",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="order_id", type="integer", example=1),
 *     @OA\Property(property="item_id", type="integer", example=123),
 *     @OA\Property(property="channel_product_id", type="string", example="cp-456"),
 *     @OA\Property(property="sku", type="string", example="SKU-12345"),
 *     @OA\Property(property="description", type="string", example="Product Name Description"),
 *     @OA\Property(property="qty_in_base", type="integer", example=2),
 *     @OA\Property(property="price", type="number", format="float", example=150000),
 *     @OA\Property(property="disc", type="number", format="float", example=0),
 *     @OA\Property(property="disc_amount", type="number", format="float", example=0),
 *     @OA\Property(property="tax_amount", type="number", format="float", example=15000),
 *     @OA\Property(property="amount", type="number", format="float", example=315000),
 *     @OA\Property(property="shipper", type="string", example="JNE"),
 *     @OA\Property(property="tracking_no", type="string", example="TRK123456789")
 * )
 *
 * @OA\Schema(
 *     schema="Order",
 *     title="Order",
 *     description="Order model",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="salesorder_no", type="string", example="SO-20260604-001"),
 *     @OA\Property(property="channel_shop_id", type="string", example="shop-123"),
 *     @OA\Property(property="customer_name", type="string", example="John Doe"),
 *     @OA\Property(property="transaction_date", type="string", format="date-time", example="2026-06-04T10:00:00Z"),
 *     @OA\Property(property="sub_total", type="number", format="float", example=300000),
 *     @OA\Property(property="total_disc", type="number", format="float", example=0),
 *     @OA\Property(property="total_tax", type="number", format="float", example=30000),
 *     @OA\Property(property="shipping_cost", type="number", format="float", example=15000),
 *     @OA\Property(property="insurance_cost", type="number", format="float", example=0),
 *     @OA\Property(property="grand_total", type="number", format="float", example=345000),
 *     @OA\Property(property="shipping_full_name", type="string", example="John Doe"),
 *     @OA\Property(property="shipping_phone", type="string", example="081234567890"),
 *     @OA\Property(property="shipping_address", type="string", example="Jl. Sudirman No. 1"),
 *     @OA\Property(property="shipping_area", type="string", example="Kebayoran Baru"),
 *     @OA\Property(property="shipping_city", type="string", example="Jakarta Selatan"),
 *     @OA\Property(property="shipping_province", type="string", example="DKI Jakarta"),
 *     @OA\Property(property="shipping_post_code", type="string", example="12190"),
 *     @OA\Property(property="shipping_country", type="string", example="Indonesia"),
 *     @OA\Property(property="status", type="string", example="pending"),
 *     @OA\Property(property="is_paid", type="boolean", example=false),
 *     @OA\Property(property="is_canceled", type="boolean", example=false),
 *     @OA\Property(property="cancel_reason", type="string", example=null),
 *     @OA\Property(property="channel_status", type="string", example="new"),
 *     @OA\Property(property="payment_method", type="string", example="bank_transfer"),
 *     @OA\Property(property="source", type="string", example="api"),
 *     @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/OrderItem"))
 * )
 */
class OrderController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/orders",
     *     tags={"Order"},
     *     summary="Get list of orders",
     *     description="Returns a list of orders",
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Order")
     *         )
     *     )
     * )
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
     * @OA\Post(
     *     path="/api/v1/orders",
     *     tags={"Order"},
     *     summary="Create a new order",
     *     description="Creates a new order",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Order created successfully",
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     )
     * )
     */
    public function store(Request $request) {}

    /**
     * @OA\Get(
     *     path="/api/v1/orders/{id}",
     *     tags={"Order"},
     *     summary="Get order details",
     *     description="Returns order details by ID",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of order to return",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found"
     *     )
     * )
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
     * @OA\Put(
     *     path="/api/v1/orders/{id}",
     *     tags={"Order"},
     *     summary="Update an existing order",
     *     description="Updates an existing order",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of order to update",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order updated successfully",
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found"
     *     )
     * )
     */
    public function update(Request $request, $id) {}

    /**
     * @OA\Delete(
     *     path="/api/v1/orders/{id}",
     *     tags={"Order"},
     *     summary="Delete an order",
     *     description="Deletes an order",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of order to delete",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order deleted successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found"
     *     )
     * )
     */
    public function destroy($id) {}
}
