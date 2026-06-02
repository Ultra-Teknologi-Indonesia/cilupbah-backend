<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;
use Modules\Channel\Http\Controllers\TikTokSyncController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('channels', ChannelController::class)->names('channel');
    
    // TikTok Sync UI
    Route::get('tiktok-sync', [TikTokSyncController::class, 'index'])->name('channel.tiktok-sync');
    Route::post('tiktok-sync/push', [TikTokSyncController::class, 'push'])->name('channel.tiktok-sync.push');
    Route::post('tiktok-sync/pull', [TikTokSyncController::class, 'pull'])->name('channel.tiktok-sync.pull');
});
