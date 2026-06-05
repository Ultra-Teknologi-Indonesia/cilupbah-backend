<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::get('/channels', [ChannelController::class, 'index'])->name('channel.index');
Route::post('/channels/shop/{id}/disconnect', [ChannelController::class, 'disconnectShop'])->name('channel.shop.disconnect');
Route::post('/channels/shop/{id}/refresh-token', [ChannelController::class, 'refreshShopToken'])->name('channel.shop.refresh-token');
