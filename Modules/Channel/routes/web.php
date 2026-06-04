<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;
use Modules\Channel\Http\Controllers\TikTokWebController;

Route::get('tiktok-sync', [TikTokWebController::class, 'index'])->name('tiktok-sync.index');
Route::post('tiktok-sync/pull', [TikTokWebController::class, 'pullOrders'])->name('tiktok-sync.pull');
Route::post('tiktok-sync/accept', [TikTokWebController::class, 'acceptOrder'])->name('tiktok-sync.accept');
Route::post('tiktok-sync/decline', [TikTokWebController::class, 'declineOrder'])->name('tiktok-sync.decline');
Route::post('tiktok-sync/push', [TikTokWebController::class, 'pushProduct'])->name('tiktok-sync.push');
Route::post('tiktok-sync/products/bulk-push', [TikTokWebController::class, 'bulkPush'])->name('tiktok-sync.bulk-push');
Route::post('tiktok-sync/products/sync', [TikTokWebController::class, 'syncProduct'])->name('tiktok-sync.sync-product');
Route::post('tiktok-sync/products/store', [TikTokWebController::class, 'storeProduct'])->name('tiktok-sync.store-product');

// TODO: Tambahkan kembali middleware ['auth', 'verified'] setelah selesai testing
Route::get('channels', [ChannelController::class, 'index'])->name('channel.index');
Route::post('channels/shops/{id}/disconnect', [ChannelController::class, 'disconnectShop'])->name('channel.shop.disconnect');
Route::post('channels/shops/{id}/refresh-token', [ChannelController::class, 'refreshShopToken'])->name('channel.shop.refresh-token');
