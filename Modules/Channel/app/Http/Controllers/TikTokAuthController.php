<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokClient;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;

class TikTokAuthController extends Controller
{
    public function redirect(TikTokClient $client)
    {
        $redirectUri = env('TIKTOK_REDIRECT_URI');
        $url = $client->getAuthUrl($redirectUri);
        return redirect()->away($url);
    }

    public function callback(Request $request, TikTokClient $client)
    {
        $code = $request->query('code');
        if (!$code) {
            return response()->json(['error' => 'No auth code provided by TikTok'], 400);
        }

        $redirectUri = env('TIKTOK_REDIRECT_URI');
        $tokenData = $client->getAccessToken($code, $redirectUri);

        if (isset($tokenData['code']) && $tokenData['code'] !== 0) {
            return response()->json(['error' => 'Failed to get access token', 'details' => $tokenData], 400);
        }

        $data = $tokenData['data'] ?? [];
        $accessToken = $data['access_token'] ?? null;

        if (!$accessToken) {
            return response()->json(['error' => 'No access token received', 'details' => $tokenData], 400);
        }

        // As an ISV, one seller account might grant access to multiple shops.
        // We fetch the authorized shops using the obtained access token.
        try {
            $shopsResponse = $client->getAuthorizedShops($accessToken);
            if (isset($shopsResponse['code']) && $shopsResponse['code'] !== 0) {
                return response()->json(['error' => 'Failed to fetch authorized shops', 'details' => $shopsResponse], 400);
            }

            $shops = $shopsResponse['data']['shops'] ?? [];
            $savedShops = [];

            foreach ($shops as $shop) {
                $shopId = $shop['id'] ?? $shop['shop_id'] ?? 'unknown';
                $shopCipher = $shop['cipher'] ?? $shop['shop_cipher'] ?? null;
                $shopName = $shop['name'] ?? $shop['shop_name'] ?? null;

                ChannelShop::updateOrCreate(
                    ['shop_id' => $shopId],
                    [
                        'channel_id' => \Modules\Channel\Models\Channel::where('code', 'tiktok')->value('id'),
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
                'message' => 'TikTok authorized successfully for ISV',
                'shops_authorized' => $savedShops,
                'note' => 'You can now use the tiktok:push-product command with any of the above shop IDs.'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error fetching shops', 'message' => $e->getMessage()], 500);
        }
    }
}
