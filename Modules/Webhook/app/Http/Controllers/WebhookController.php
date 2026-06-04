<?php

namespace Modules\Webhook\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Webhooks', description: 'API Endpoints for Webhooks')]
#[OA\Schema(
    schema: 'Webhook',
    title: 'Webhook Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Order Status Update'),
        new OA\Property(property: 'url', type: 'string', example: 'https://example.com/webhook'),
        new OA\Property(property: 'secret', type: 'string', example: 'super_secret_key', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'StoreWebhookRequest',
    required: ['name', 'url'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Order Status Update'),
        new OA\Property(property: 'url', type: 'string', example: 'https://example.com/webhook'),
        new OA\Property(property: 'secret', type: 'string', example: 'super_secret_key', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true)
    ]
)]
class WebhookController extends Controller
{
    #[OA\Get(
        path: '/api/v1/webhooks',
        summary: 'Get list of webhooks',
        security: [['bearerAuth' => []]],
        tags: ['Webhooks'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Webhook'))
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index()
    {
        return view('webhook::index');
    }

    public function create()
    {
        return view('webhook::create');
    }

    #[OA\Post(
        path: '/api/v1/webhooks',
        summary: 'Create a new webhook',
        security: [['bearerAuth' => []]],
        tags: ['Webhooks'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreWebhookRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Webhook created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Webhook')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(Request $request) {}

    #[OA\Get(
        path: '/api/v1/webhooks/{webhook}',
        summary: 'Get webhook details',
        security: [['bearerAuth' => []]],
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(name: 'webhook', in: 'path', required: true, description: 'ID of the webhook', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Webhook')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Webhook not found')
        ]
    )]
    public function show($id)
    {
        return view('webhook::show');
    }

    public function edit($id)
    {
        return view('webhook::edit');
    }

    #[OA\Put(
        path: '/api/v1/webhooks/{webhook}',
        summary: 'Update an existing webhook',
        security: [['bearerAuth' => []]],
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(name: 'webhook', in: 'path', required: true, description: 'ID of the webhook to update', schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreWebhookRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Webhook updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Webhook')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Webhook not found'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(Request $request, $id) {}

    #[OA\Delete(
        path: '/api/v1/webhooks/{webhook}',
        summary: 'Delete a webhook',
        security: [['bearerAuth' => []]],
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(name: 'webhook', in: 'path', required: true, description: 'ID of the webhook to delete', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Webhook deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Webhook not found')
        ]
    )]
    public function destroy($id) {}
}
