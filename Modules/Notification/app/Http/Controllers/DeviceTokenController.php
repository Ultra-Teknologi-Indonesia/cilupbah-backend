<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Notification\Http\Requests\StoreDeviceTokenRequest;
use Modules\Notification\Http\Resources\DeviceTokenResource;
use Modules\Notification\Repositories\NotificationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Device Tokens', description: 'FCM device token management')]
class DeviceTokenController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected NotificationRepository $notifications
    ) {}
    #[OA\Post(
        path: '/api/v1/device-tokens',
        summary: 'Register FCM device token',
        security: [['bearerAuth' => []]],
        tags: ['Device Tokens'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['fcm_token'],
                properties: [
                    new OA\Property(property: 'fcm_token', type: 'string', example: 'dG9rZW4xMjM0NTY3ODk...'),
                    new OA\Property(property: 'device_id', type: 'string', example: 'device-abc-123'),
                    new OA\Property(property: 'platform', type: 'string', enum: ['android', 'ios'], example: 'android'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Token registered'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $token = $this->notifications->updateOrCreateToken(
            $request->user()->id,
            $request->fcm_token,
            $request->device_id,
            $request->platform,
        );

        return $this->successResponse(new DeviceTokenResource($token));
    }

    #[OA\Delete(
        path: '/api/v1/device-tokens',
        summary: 'Unregister FCM device token',
        security: [['bearerAuth' => []]],
        tags: ['Device Tokens'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['fcm_token'],
                properties: [
                    new OA\Property(property: 'fcm_token', type: 'string', example: 'dG9rZW4xMjM0NTY3ODk...'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Token removed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $this->notifications->deleteTokenForUser($request->user()->id, $request->fcm_token);

        return $this->successResponse(null, 'Token removed');
    }
}
