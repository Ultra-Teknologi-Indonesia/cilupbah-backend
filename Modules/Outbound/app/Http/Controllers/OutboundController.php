<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbounds', description: 'API Endpoints for Outbounds')]
#[OA\Schema(
    schema: 'Outbound',
    title: 'Outbound Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'transaction_number', type: 'string', example: 'OUT-20260604-0001'),
        new OA\Property(property: 'reference_number', type: 'string', example: 'SO-2026-0001', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['SALES_ORDER', 'PURCHASE_RETURN', 'TRANSIT_OUT'], example: 'SALES_ORDER'),
        new OA\Property(property: 'status', type: 'string', example: 'DRAFT'),
        new OA\Property(property: 'expected_date', type: 'string', format: 'date-time', example: '2026-06-05T00:00:00Z'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'StoreOutboundRequest',
    required: ['location_id', 'type', 'expected_date', 'created_by', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'reference_number', type: 'string', example: 'SO-2026-0001', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['SALES_ORDER', 'PURCHASE_RETURN', 'TRANSIT_OUT'], example: 'SALES_ORDER'),
        new OA\Property(property: 'expected_date', type: 'string', format: 'date', example: '2026-06-05'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['item_id', 'qty'],
                properties: [
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
                    new OA\Property(property: 'qty', type: 'integer', example: 50)
                ]
            )
        )
    ]
)]
class OutboundController extends Controller
{
    #[OA\Get(
        path: '/api/v1/outbounds',
        summary: 'Get list of outbounds',
        security: [['bearerAuth' => []]],
        tags: ['Outbounds'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Outbound'))
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index()
    {
        return view('outbound::index');
    }

    public function create()
    {
        return view('outbound::create');
    }

    #[OA\Post(
        path: '/api/v1/outbounds',
        summary: 'Create a new outbound',
        security: [['bearerAuth' => []]],
        tags: ['Outbounds'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreOutboundRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Outbound created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Outbound')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(Request $request) {}

    #[OA\Get(
        path: '/api/v1/outbounds/{outbound}',
        summary: 'Get outbound details',
        security: [['bearerAuth' => []]],
        tags: ['Outbounds'],
        parameters: [
            new OA\Parameter(name: 'outbound', in: 'path', required: true, description: 'ID of the outbound', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Outbound')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Outbound not found')
        ]
    )]
    public function show($id)
    {
        return view('outbound::show');
    }

    public function edit($id)
    {
        return view('outbound::edit');
    }

    #[OA\Put(
        path: '/api/v1/outbounds/{outbound}',
        summary: 'Update an existing outbound',
        security: [['bearerAuth' => []]],
        tags: ['Outbounds'],
        parameters: [
            new OA\Parameter(name: 'outbound', in: 'path', required: true, description: 'ID of the outbound to update', schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreOutboundRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Outbound updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Outbound')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Outbound not found'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(Request $request, $id) {}

    #[OA\Delete(
        path: '/api/v1/outbounds/{outbound}',
        summary: 'Delete an outbound',
        security: [['bearerAuth' => []]],
        tags: ['Outbounds'],
        parameters: [
            new OA\Parameter(name: 'outbound', in: 'path', required: true, description: 'ID of the outbound to delete', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Outbound deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Outbound not found')
        ]
    )]
    public function destroy($id) {}
}
