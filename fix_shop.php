<?php
require 'vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$client = app(\Modules\Channel\Services\TikTokClient::class);
$shop = DB::table('channel_shops')->where('shop_id', '7643300999967131393')->first();

if ($shop && $shop->access_token) {
    try {
        $res = $client->request('GET', '/authorization/202309/shops', [], [], $shop->access_token);
        print_r($res);
        if (isset($res['data']['shops'])) {
            foreach ($res['data']['shops'] as $s) {
                if ($s['id'] == '7643300999967131393') {
                    DB::table('channel_shops')->where('id', $shop->id)->update([
                        'shop_cipher' => $s['cipher']
                    ]);
                    echo "Shop cipher updated successfully: " . $s['cipher'] . "\n";
                }
            }
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Shop not found or missing access token.\n";
}
