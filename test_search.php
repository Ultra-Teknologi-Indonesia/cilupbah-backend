<?php
require 'vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$shop = DB::table('channel_shops')->where('shop_id', '7494685794425930858')->first();
$client = app(\Modules\Channel\Services\TikTokClient::class);

$queries = ['app_key' => env('TIKTOK_APP_KEY'), 'timestamp' => time(), 'access_token' => $shop->access_token, 'shop_cipher' => $shop->shop_cipher, 'page_size' => 10];
$body = new stdClass(); // Empty object -> "{}"

// Generate signature with "{}"
$signParams = collect($queries)->except(['sign', 'access_token'])->toArray();
ksort($signParams);
$paramString = ''; foreach ($signParams as $k => $v) { $paramString .= $k . $v; }
$bodyString = "{}";
$stringToSign = env('TIKTOK_APP_SECRET') . '/product/202309/products/search' . $paramString . $bodyString . env('TIKTOK_APP_SECRET');
$queries['sign'] = hash_hmac('sha256', $stringToSign, env('TIKTOK_APP_SECRET'));

$url = 'https://open-api.tiktokglobalshop.com/product/202309/products/search?' . http_build_query($queries);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'x-tts-access-token: ' . $shop->access_token,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
echo "RESPONSE:\n$response\n";
