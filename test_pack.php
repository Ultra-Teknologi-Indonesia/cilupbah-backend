<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$shop = DB::table('channel_shops')->first();
$accessToken = $shop->access_token;
$appKey = env('TIKTOK_APP_KEY');
$appSecret = env('TIKTOK_APP_SECRET');
$shopCipher = $shop->shop_cipher;

$path = '/fulfillment/202309/packages';
$queries = [
    'app_key' => $appKey,
    'timestamp' => time(),
    'shop_cipher' => $shopCipher,
];
$body = ['order_id' => '584326589305423653'];

// Sign
ksort($queries);
$signString = $appSecret . $path;
foreach ($queries as $k => $v) { $signString .= $k . $v; }
$signString .= json_encode($body) . $appSecret;
$queries['sign'] = hash_hmac('sha256', $signString, $appSecret);

$client = new \GuzzleHttp\Client();
try {
    $res = $client->post("https://open-api.tiktokglobalshop.com" . $path . "?" . http_build_query($queries), [
        'headers' => [
            'x-tts-access-token' => $accessToken,
            'Content-Type' => 'application/json'
        ],
        'json' => $body
    ]);
    echo $res->getBody();
} catch (\Exception $e) {
    if ($e->hasResponse()) {
        echo $e->getResponse()->getBody();
    } else {
        echo $e->getMessage();
    }
}
