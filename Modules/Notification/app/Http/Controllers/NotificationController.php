<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Notifications', description: 'API Endpoints for Notifications')]
#[OA\Schema(
    schema: 'Notification',
    title: 'Notification Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'user_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'title', type: 'string', example: 'New Order Received'),
        new OA\Property(property: 'message', type: 'string', example: 'You have received a new order from John Doe.'),
        new OA\Property(property: 'is_read', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'StoreNotificationRequest',
    required: ['title', 'message'],
    type: 'object',
    properties: [
        new OA\Property(property: 'user_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'title', type: 'string', example: 'New Order Received'),
        new OA\Property(property: 'message', type: 'string', example: 'You have received a new order from John Doe.')
    ]
)]
class NotificationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/notifications',
        summary: 'Get list of notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Notification'))
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index()
    {
        return view('notification::index');
    }

    public function create()
    {
        return view('notification::create');
    }

    #[OA\Post(
        path: '/api/v1/notifications',
        summary: 'Create a new notification',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreNotificationRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Notification created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Notification')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(Request $request) {}

    #[OA\Get(
        path: '/api/v1/notifications/{notification}',
        summary: 'Get notification details',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, description: 'ID of the notification', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Notification')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Notification not found')
        ]
    )]
    public function show($id)
    {
        return view('notification::show');
    }

    public function edit($id)
    {
        return view('notification::edit');
    }

    #[OA\Put(
        path: '/api/v1/notifications/{notification}',
        summary: 'Update an existing notification',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, description: 'ID of the notification to update', schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreNotificationRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notification updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Notification')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Notification not found'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(Request $request, $id) {}

    #[OA\Delete(
        path: '/api/v1/notifications/{notification}',
        summary: 'Delete a notification',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, description: 'ID of the notification to delete', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notification deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Notification not found')
        ]
    )]
    public function destroy($id) {}
}
