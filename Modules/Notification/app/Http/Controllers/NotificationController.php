<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Notification\Http\Resources\NotificationResource;
use Modules\Notification\Services\NotificationService;
use Illuminate\Http\JsonResponse;
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
        new OA\Property(property: 'type', type: 'string', example: 'task_assigned'),
        new OA\Property(property: 'data', type: 'object'),
        new OA\Property(property: 'is_read', type: 'boolean', example: false),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected NotificationService $notificationService
    ) {}

    #[OA\Get(
        path: '/api/v1/notifications',
        summary: 'Get list of notifications for current user',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'is_read', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
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
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->notificationService->paginateForUser($request->user()->id, $request);

        return $this->successPaginatedResponse($paginator);
    }

    #[OA\Get(
        path: '/api/v1/notifications/unread-count',
        summary: 'Get unread notification count',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'count', type: 'integer', example: 5)
                    ]
                )
            ),
        ]
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->unreadCount($request->user()->id);

        return $this->successResponse(['count' => $count]);
    }

    #[OA\Get(
        path: '/api/v1/notifications/{notification}',
        summary: 'Get notification details',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Notification')])
            ),
            new OA\Response(response: 404, description: 'Notification not found')
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationService->findForUser($request->user()->id, $id);

        return $this->successResponse(new NotificationResource($notification));
    }

    #[OA\Patch(
        path: '/api/v1/notifications/{notification}/read',
        summary: 'Mark notification as read',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marked as read'),
            new OA\Response(response: 404, description: 'Notification not found')
        ]
    )]
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationService->markAsRead($request->user()->id, $id);

        return $this->successResponse(new NotificationResource($notification));
    }

    #[OA\Post(
        path: '/api/v1/notifications/read-all',
        summary: 'Mark all notifications as read',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'All marked as read'),
        ]
    )]
    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notificationService->markAllAsRead($request->user()->id);

        return $this->successResponse(null, 'All notifications marked as read');
    }

    #[OA\Delete(
        path: '/api/v1/notifications/{notification}',
        summary: 'Delete a notification',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notification deleted'),
            new OA\Response(response: 404, description: 'Notification not found')
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->notificationService->deleteForUser($request->user()->id, $id);

        return $this->successResponse(null, 'Notification deleted');
    }
}
