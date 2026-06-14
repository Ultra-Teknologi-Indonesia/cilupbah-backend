<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Notification\Models\DeviceToken;
use Modules\Notification\Http\Requests\StoreDeviceTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Device Tokens', description: 'FCM device token management')]
class DeviceTokenController extends Controller
{
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
        $token = DeviceToken::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'fcm_token' => $request->fcm_token,
            ],
            [
                'device_id' => $request->device_id,
                'platform' => $request->platform ?? 'android',
            ],
        );

        return response()->json(['data' => $token], 200);
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
        DeviceToken::where('user_id', $request->user()->id)
            ->where('fcm_token', $request->fcm_token)
            ->delete();

        return response()->json(['message' => 'Token removed']);
    }
}
