<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Outbound\Http\Controllers\AdHocPickController;
use Modules\Outbound\Http\Controllers\CourierController;
use Modules\Outbound\Http\Controllers\OutboundFulfillmentController;
use Modules\Outbound\Http\Controllers\PacklistController;
use Modules\Outbound\Http\Controllers\PicklistController;
use Modules\Outbound\Http\Controllers\ShipmentController;
use Modules\Outbound\Http\Controllers\WmsController;

Route::middleware(['auth:sanctum'])->prefix('v1/outbound')->group(function () {

    Route::post('orders/get-by-no', [OutboundFulfillmentController::class, 'getOrderByNo'])->name('outbound.orders.get-by-no');
    Route::post('orders/move-to-ready-to-pick', [OutboundFulfillmentController::class, 'moveToReadyToPick'])->name('outbound.orders.move-to-ready-to-pick');
    Route::post('orders/move-to-ready-to-process', [OutboundFulfillmentController::class, 'moveToReadyToProcess'])->name('outbound.orders.move-to-ready-to-process');
    Route::post('orders/change-location', [OutboundFulfillmentController::class, 'changeLocation'])->name('outbound.orders.change-location');
    Route::post('orders/request-cancel', [OutboundFulfillmentController::class, 'requestCancelOrder'])->name('outbound.orders.request-cancel');
    Route::post('orders/ready-to-ship', [OutboundFulfillmentController::class, 'readyToShip'])->name('outbound.orders.ready-to-ship');
    Route::post('orders/ad-hoc-pick', [AdHocPickController::class, 'complete'])->name('outbound.orders.ad-hoc-pick');
    Route::post('orders/ad-hoc-pick/scan', [AdHocPickController::class, 'scan'])->name('outbound.orders.ad-hoc-pick.scan');
    Route::get('pickers', [OutboundFulfillmentController::class, 'pickers'])->name('outbound.pickers.index');
    Route::get('orders/{stage}', [OutboundFulfillmentController::class, 'ordersByStage'])->name('outbound.orders.stage');

    Route::get('picklists/on-picking', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('on-picking', $request))->name('outbound.picklists.on-picking');
    Route::get('packlists/on-packing', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('on-packing', $request))->name('outbound.packlists.on-packing');
    Route::get('packlists/finish-pack', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('finish-pack', $request))->name('outbound.packlists.finish-pack');

    Route::get('picklists', [PicklistController::class, 'index'])->name('outbound.picklists.index');
    Route::post('picklists', [PicklistController::class, 'store'])->name('outbound.picklists.store');
    Route::get('picklists/{id}', [PicklistController::class, 'show'])->name('outbound.picklists.show');
    Route::get('picklists/{id}/items', [PicklistController::class, 'items'])->name('outbound.picklists.items');
    Route::get('picklists/{id}/pdf', [PicklistController::class, 'pdf'])->name('outbound.picklists.pdf');
    Route::post('picklists/{id}/assign-picker', [PicklistController::class, 'assignPicker'])->name('outbound.picklists.assign-picker');
    Route::post('picklists/{id}/start', [PicklistController::class, 'start'])->name('outbound.picklists.start');
    Route::post('picklists/{id}/items/{itemId}/pick', [PicklistController::class, 'pickItem'])->name('outbound.picklists.pick-item');
    Route::post('picklists/{id}/complete', [PicklistController::class, 'complete'])->name('outbound.picklists.complete');
    Route::post('picklists/{id}/fail', [PicklistController::class, 'fail'])->name('outbound.picklists.fail');
    Route::post('picklists/{id}/cancel', [PicklistController::class, 'cancel'])->name('outbound.picklists.cancel');
    Route::delete('picklists/{id}', [PicklistController::class, 'destroy'])->name('outbound.picklists.destroy');

    Route::get('packlists/scan-order', [PacklistController::class, 'scanOrder'])->name('outbound.packlists.scan-order');
    Route::get('packlists', [PacklistController::class, 'index'])->name('outbound.packlists.index');
    Route::post('packlists', [PacklistController::class, 'store'])->name('outbound.packlists.store');
    Route::get('packlists/{id}', [PacklistController::class, 'show'])->name('outbound.packlists.show');
    Route::get('packlists/{id}/items', [PacklistController::class, 'items'])->name('outbound.packlists.items');
    Route::post('packlists/{id}/assign-packer', [PacklistController::class, 'assignPacker'])->name('outbound.packlists.assign-packer');
    Route::post('packlists/{id}/start', [PacklistController::class, 'start'])->name('outbound.packlists.start');
    Route::post('packlists/{id}/items/{itemId}/pack', [PacklistController::class, 'packItem'])->name('outbound.packlists.pack-item');
    Route::post('packlists/{id}/verify-barcode', [PacklistController::class, 'verifyBarcode'])->name('outbound.packlists.verify-barcode');
    Route::post('packlists/{id}/complete', [PacklistController::class, 'complete'])->name('outbound.packlists.complete');
    Route::post('packlists/{id}/cancel', [PacklistController::class, 'cancel'])->name('outbound.packlists.cancel');
    Route::delete('packlists/{id}', [PacklistController::class, 'destroy'])->name('outbound.packlists.destroy');

    Route::get('shipments', [ShipmentController::class, 'index'])->name('outbound.shipments.index');
    Route::post('shipments', [ShipmentController::class, 'store'])->name('outbound.shipments.store');
    Route::post('shipments/scan', [ShipmentController::class, 'scan'])->name('outbound.shipments.scan');
    Route::get('shipments/instant', [ShipmentController::class, 'instantAll'])->name('outbound.shipments.instant');
    Route::post('shipments/instant', [ShipmentController::class, 'storeInstant'])->name('outbound.shipments.instant.store');
    Route::get('shipments/completed/{type}/{courierIds}', [ShipmentController::class, 'completed'])->name('outbound.shipments.completed');
    Route::get('shipments/by-courier/{courierCode}', [ShipmentController::class, 'byCourier'])->name('outbound.shipments.by-courier');
    Route::get('shipments/{id}', [ShipmentController::class, 'show'])->name('outbound.shipments.show');
    Route::post('shipments/{id}/add-orders', [ShipmentController::class, 'addOrders'])->name('outbound.shipments.add-orders');
    Route::post('shipments/{id}/remove-orders', [ShipmentController::class, 'removeOrders'])->name('outbound.shipments.remove-orders');
    Route::post('shipments/{id}/save-awb', [ShipmentController::class, 'saveAwb'])->name('outbound.shipments.save-awb');
    Route::post('shipments/{id}/hand-over', [ShipmentController::class, 'handOver'])->name('outbound.shipments.hand-over');
    Route::post('shipments/{id}/update-handover-qty', [ShipmentController::class, 'updateHandoverQty'])->name('outbound.shipments.update-handover-qty');
    Route::post('shipments/{id}/cancel', [ShipmentController::class, 'cancel'])->name('outbound.shipments.cancel');
    Route::delete('shipments/{id}', [ShipmentController::class, 'destroy'])->name('outbound.shipments.destroy');

    Route::get('couriers', [CourierController::class, 'index'])->name('outbound.couriers.index');
    Route::get('couriers/all', [CourierController::class, 'all'])->name('outbound.couriers.all');
    Route::get('couriers/tenant/{tenantId}', [CourierController::class, 'byTenant'])->name('outbound.couriers.by-tenant');
    Route::post('couriers', [CourierController::class, 'store'])->name('outbound.couriers.store');
    Route::get('couriers/{id}', [CourierController::class, 'show'])->name('outbound.couriers.show');
    Route::put('couriers/{id}', [CourierController::class, 'update'])->name('outbound.couriers.update');
    Route::delete('couriers/{id}', [CourierController::class, 'destroy'])->name('outbound.couriers.destroy');

    Route::get('wms/employee/{identifier}', [WmsController::class, 'employee'])->name('outbound.wms.employee');
    Route::get('wms/default-bin/{locationId}', [WmsController::class, 'defaultBin'])->name('outbound.wms.default-bin');
    Route::put('wms/default-bin/{locationId}', [WmsController::class, 'setDefaultBin'])->name('outbound.wms.set-default-bin');
});
