<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class UnifiedAuthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/authorize',
        operationId: 'authorizeMarketplace',
        summary: 'Authorize OAuth',
        description: 'Redirect to the marketplace OAuth page',
        tags: ['Auth (Global)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'query', required: true, description: 'tiktok|shopee|tokopedia|lazada', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'redirect_uri', in: 'query', required: true, description: 'Callback URL', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 302, description: 'Redirects to authorization URL')]
    public function authorizeMarketplace(Request $request)
    {
        $marketplace = $request->query('marketplace');
        $redirectUri = $request->query('redirect_uri');

        if ($marketplace === 'tiktok') {
            $client = app(\Modules\Channel\Services\TikTokClient::class);
            $url = $client->getAuthUrl($redirectUri ?? env('TIKTOK_REDIRECT_URI'));
            return response()->json([
                'status' => 'success',
                'marketplace' => $marketplace,
                'data' => [
                    'redirect_url' => $url
                ]
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Marketplace not supported'
        ], 400);
    }

    #[OA\Post(
        path: '/api/v1/auth/callback',
        operationId: 'authCallback',
        summary: 'OAuth Callback',
        description: 'Exchange auth_code for access_token',
        tags: ['Auth (Global)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'marketplace', type: 'string', example: 'tiktok'),
                new OA\Property(property: 'code', type: 'string', example: '<auth_code>'),
                new OA\Property(property: 'state', type: 'string', example: '<state>', nullable: true)
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Successfully authenticated')]
    public function callback(Request $request): JsonResponse
    {
        $marketplace = $request->input('marketplace');
        $code = $request->input('code');

        if (!$code) {
            return response()->json(['status' => 'error', 'message' => 'No auth code provided'], 400);
        }

        if ($marketplace === 'tiktok') {
            $client = app(\Modules\Channel\Services\TikTokClient::class);
            $redirectUri = env('TIKTOK_REDIRECT_URI');
            $tokenData = $client->getAccessToken($code, $redirectUri);

            if (isset($tokenData['code']) && $tokenData['code'] !== 0) {
                return response()->json(['status' => 'error', 'message' => 'Failed to get access token', 'details' => $tokenData], 400);
            }

            $data = $tokenData['data'] ?? [];
            $accessToken = $data['access_token'] ?? null;

            if (!$accessToken) {
                return response()->json(['status' => 'error', 'message' => 'No access token received', 'details' => $tokenData], 400);
            }

            try {
                $shopsResponse = $client->getAuthorizedShops($accessToken);
                if (isset($shopsResponse['code']) && $shopsResponse['code'] !== 0) {
                    return response()->json(['status' => 'error', 'message' => 'Failed to fetch authorized shops', 'details' => $shopsResponse], 400);
                }

                $shops = $shopsResponse['data']['shops'] ?? [];
                $savedShops = [];

                foreach ($shops as $shop) {
                    $shopId = $shop['id'] ?? $shop['shop_id'] ?? 'unknown';
                    $shopCipher = $shop['cipher'] ?? $shop['shop_cipher'] ?? null;
                    $shopName = $shop['name'] ?? $shop['shop_name'] ?? null;

                    DB::table('channel_shops')->updateOrInsert(
                        ['shop_id' => $shopId],
                        [
                            'channel_name' => 'tiktok',
                            'shop_name' => $shopName,
                            'shop_cipher' => $shopCipher,
                            'access_token' => $accessToken,
                            'refresh_token' => $data['refresh_token'] ?? null,
                            'token_expires_at' => isset($data['access_token_expire_in']) ? now()->addSeconds($data['access_token_expire_in']) : null,
                            'refresh_token_expires_at' => isset($data['refresh_token_expire_in']) ? now()->addSeconds($data['refresh_token_expire_in']) : null,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $savedShops[] = ['shop_id' => $shopId, 'shop_name' => $shopName];
                }

                return response()->json([
                    'status' => 'success',
                    'marketplace' => 'tiktok',
                    'message' => 'Authorized successfully',
                    'data' => [
                        'shops_authorized' => $savedShops
                    ]
                ]);
            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => 'Error fetching shops: ' . $e->getMessage()], 500);
            }
        }

        return response()->json(['status' => 'error', 'message' => 'Marketplace not supported'], 400);
    }

    #[OA\Post(
        path: '/api/v1/auth/refresh',
        operationId: 'refreshToken',
        summary: 'Refresh Token',
        description: 'Refresh access token',
        tags: ['Auth (Global)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'marketplace', type: 'string', example: 'tiktok'),
                new OA\Property(property: 'shop_id', type: 'string', example: '12345678')
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Token refreshed successfully')]
    public function refresh(Request $request): JsonResponse
    {
        $marketplace = $request->input('marketplace');
        $shopId = $request->input('shop_id');

        // Logic for refresh token will be implemented here
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Token refreshed successfully'
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/revoke',
        operationId: 'revokeToken',
        summary: 'Revoke Token',
        description: 'Cabut otorisasi toko',
        tags: ['Auth (Global)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'marketplace', type: 'string', example: 'tiktok'),
                new OA\Property(property: 'shop_id', type: 'string', example: '12345678')
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Token revoked successfully')]
    public function revoke(Request $request): JsonResponse
    {
        $marketplace = $request->input('marketplace');
        $shopId = $request->input('shop_id');

        DB::table('channel_shops')
            ->where('channel_name', $marketplace)
            ->where('shop_id', $shopId)
            ->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Token revoked successfully'
        ]);
    }

    #[OA\Get(
        path: '/api/v1/auth/shops',
        operationId: 'listAuthorizedShops',
        summary: 'List Authorized Shops',
        description: 'Daftar semua toko yang sudah terotorisasi',
        tags: ['Auth (Global)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'query', required: false, description: 'Filter by marketplace (opsional)', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function shops(Request $request): JsonResponse
    {
        $marketplace = $request->query('marketplace');

        $query = DB::table('channel_shops')->where('is_active', true);
        
        if ($marketplace) {
            $query->where('channel_name', $marketplace);
        }

        $shops = $query->select('shop_id', 'shop_name', 'channel_name')->get();

        return response()->json([
            'status' => 'success',
            'data' => $shops
        ]);
    }
}
