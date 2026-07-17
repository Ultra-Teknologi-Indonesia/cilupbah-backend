<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Outbound\Http\Controllers\AdHocPickController;
use Modules\Outbound\Http\Controllers\CourierController;
use Modules\Outbound\Http\Controllers\OutboundFulfillmentController;
use Modules\Outbound\Http\Controllers\PacklistController;
use Modules\Outbound\Http\Controllers\PicklistController;
use Modules\Outbound\Http\Controllers\PreManifestCancelController;
use Modules\Outbound\Http\Controllers\ShipmentController;
use Modules\Outbound\Http\Controllers\WmsController;

Route::middleware(['auth:sanctum'])->prefix('v1/outbound')->group(function () {

    Route::post('orders/get-by-no', [OutboundFulfillmentController::class, 'getOrderByNo'])->name('outbound.orders.get-by-no');
    Route::post('orders/move-to-ready-to-pick', [OutboundFulfillmentController::class, 'moveToReadyToPick'])->name('outbound.orders.move-to-ready-to-pick')->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('orders/move-to-ready-to-process', [OutboundFulfillmentController::class, 'moveToReadyToProcess'])->name('outbound.orders.move-to-ready-to-process')->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('orders/change-location', [OutboundFulfillmentController::class, 'changeLocation'])->name('outbound.orders.change-location')->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('orders/request-cancel', [OutboundFulfillmentController::class, 'requestCancelOrder'])->name('outbound.orders.request-cancel')->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('orders/ready-to-ship', [OutboundFulfillmentController::class, 'readyToShip'])->name('outbound.orders.ready-to-ship')->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('orders/retry-pickup', [OutboundFulfillmentController::class, 'retryPickup'])->name('outbound.orders.retry-pickup')->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('orders/instant-driver-call', [OutboundFulfillmentController::class, 'bulkInstantDriverCall'])->name('outbound.orders.instant-driver-call')->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('orders/ad-hoc-pick', [AdHocPickController::class, 'complete'])->name('outbound.orders.ad-hoc-pick')->middleware('role_or_permission:owner|edit-picking');
    Route::post('orders/ad-hoc-pick/scan', [AdHocPickController::class, 'scan'])->name('outbound.orders.ad-hoc-pick.scan')->middleware('role_or_permission:owner|edit-picking');
    Route::get('pickers', [OutboundFulfillmentController::class, 'pickers'])->name('outbound.pickers.index');
    Route::get('orders/{stage}', [OutboundFulfillmentController::class, 'ordersByStage'])->name('outbound.orders.stage');
    Route::delete('orders/{orderId}', [OutboundFulfillmentController::class, 'destroyOrder'])->name('outbound.orders.destroy')->middleware('role_or_permission:owner|delete-pesanan');

    Route::get('picklists/on-picking', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('on-picking', $request))->name('outbound.picklists.on-picking');
    Route::get('packlists/on-packing', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('on-packing', $request))->name('outbound.packlists.on-packing');
    Route::get('packlists/finish-pack', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('finish-pack', $request))->name('outbound.packlists.finish-pack');

    Route::get('picklists/counts', [PicklistController::class, 'counts'])->name('outbound.picklists.counts')->middleware('role_or_permission:owner|view-picking');
    Route::get('picklists', [PicklistController::class, 'index'])->name('outbound.picklists.index')->middleware('role_or_permission:owner|view-picking');
    Route::post('picklists', [PicklistController::class, 'store'])->name('outbound.picklists.store')->middleware('role_or_permission:owner|create-picking');
    Route::get('picklists/{id}', [PicklistController::class, 'show'])->name('outbound.picklists.show')->middleware('role_or_permission:owner|view-picking');
    Route::get('picklists/{id}/items', [PicklistController::class, 'items'])->name('outbound.picklists.items')->middleware('role_or_permission:owner|view-picking');
    Route::get('picklists/{id}/pdf', [PicklistController::class, 'pdf'])->name('outbound.picklists.pdf')->middleware('role_or_permission:owner|export-picking');
    Route::post('picklists/documents/bulk/pdf', [PicklistController::class, 'bulkPdf'])->name('outbound.picklists.bulk-pdf')->middleware('role_or_permission:owner|export-picking');
    Route::post('picklists/{id}/assign-picker', [PicklistController::class, 'assignPicker'])->name('outbound.picklists.assign-picker')->middleware('role_or_permission:owner|edit-picking');

    Route::delete('picklists/{id}/assignment', [PicklistController::class, 'unassign'])->name('outbound.picklists.unassign')->middleware('role_or_permission:owner|edit-picking');
    Route::post('picklists/{id}/assignment/reset', [PicklistController::class, 'resetAssignment'])->name('outbound.picklists.resetAssignment')->middleware('role_or_permission:owner|delete-picking');
    Route::post('picklists/{id}/start', [PicklistController::class, 'start'])->name('outbound.picklists.start');
    Route::post('picklists/{id}/scan', [PicklistController::class, 'scan'])->name('outbound.picklists.scan');
    Route::post('picklists/{id}/items/{itemId}/pick', [PicklistController::class, 'pickItem'])->name('outbound.picklists.pick-item');
    Route::delete('picklists/{id}/pick', [PicklistController::class, 'unpickItems'])->name('outbound.picklists.unpick-items')->middleware('role_or_permission:owner|delete-picking');
    Route::delete('picklists/{id}/items/{itemId}/pick', [PicklistController::class, 'unpickItem'])->name('outbound.picklists.unpick-item');
    Route::post('picklists/{id}/items/{itemId}/fail', [PicklistController::class, 'failItem'])->name('outbound.picklists.fail-item');
    Route::post('picklists/{id}/items/{itemId}/unfail', [PicklistController::class, 'unfailItem'])->name('outbound.picklists.unfail-item');

    Route::post('picklists/{id}/complete', [PicklistController::class, 'complete'])->name('outbound.picklists.complete')->middleware('role_or_permission:owner|edit-picking');
    Route::post('picklists/{id}/fail', [PicklistController::class, 'fail'])->name('outbound.picklists.fail')->middleware('role_or_permission:owner|edit-picking');
    Route::post('picklists/{id}/cancel', [PicklistController::class, 'cancel'])->name('outbound.picklists.cancel')->middleware('role_or_permission:owner|edit-picking');
    Route::delete('picklists/{id}', [PicklistController::class, 'destroy'])->name('outbound.picklists.destroy')->middleware('role_or_permission:owner|delete-picking');
    Route::post('picklists/{id}/revert', [PicklistController::class, 'revert'])->name('outbound.picklists.revert')->middleware('role_or_permission:owner|edit-picking');

    Route::get('packlists/scan-order', [PacklistController::class, 'scanOrder'])->name('outbound.packlists.scan-order');
    Route::get('packlists', [PacklistController::class, 'index'])->name('outbound.packlists.index')->middleware('role_or_permission:owner|view-packing');
    Route::post('packlists', [PacklistController::class, 'store'])->name('outbound.packlists.store')->middleware('role_or_permission:owner|create-packing');
    Route::get('packlists/{id}', [PacklistController::class, 'show'])->name('outbound.packlists.show')->middleware('role_or_permission:owner|view-packing');
    Route::get('packlists/{id}/items', [PacklistController::class, 'items'])->name('outbound.packlists.items')->middleware('role_or_permission:owner|view-packing');
    Route::post('packlists/{id}/assign-packer', [PacklistController::class, 'assignPacker'])->name('outbound.packlists.assign-packer')->middleware('role_or_permission:owner|edit-packing');
    Route::post('packlists/{id}/start', [PacklistController::class, 'start'])->name('outbound.packlists.start');
    Route::post('packlists/{id}/items/{itemId}/pack', [PacklistController::class, 'packItem'])->name('outbound.packlists.pack-item');
    Route::delete('packlists/{id}/pack', [PacklistController::class, 'unpackItems'])->name('outbound.packlists.unpack-items');
    Route::delete('packlists/{id}/items/{itemId}/pack', [PacklistController::class, 'unpackItem'])->name('outbound.packlists.unpack-item');
    Route::post('packlists/{id}/verify-barcode', [PacklistController::class, 'verifyBarcode'])->name('outbound.packlists.verify-barcode');
    Route::post('packlists/{id}/complete', [PacklistController::class, 'complete'])->name('outbound.packlists.complete')->middleware('role_or_permission:owner|edit-packing');
    Route::post('packlists/{id}/cancel', [PacklistController::class, 'cancel'])->name('outbound.packlists.cancel')->middleware('role_or_permission:owner|edit-packing');
    Route::delete('packlists/{id}', [PacklistController::class, 'destroy'])->name('outbound.packlists.destroy')->middleware('role_or_permission:owner|delete-packing');
    Route::post('packlists/{id}/revert', [PacklistController::class, 'revert'])->name('outbound.packlists.revert')->middleware('role_or_permission:owner|edit-packing');

    Route::get('shipments', [ShipmentController::class, 'index'])->name('outbound.shipments.index')->middleware('role_or_permission:owner|view-pengiriman');
    Route::post('shipments', [ShipmentController::class, 'store'])->name('outbound.shipments.store')->middleware('role_or_permission:owner|create-pengiriman');
    Route::post('shipments/scan', [ShipmentController::class, 'scan'])->name('outbound.shipments.scan');
    Route::get('shipments/instant', [ShipmentController::class, 'instantAll'])->name('outbound.shipments.instant')->middleware('role_or_permission:owner|view-pengiriman');
    Route::post('shipments/instant', [ShipmentController::class, 'storeInstant'])->name('outbound.shipments.instant.store')->middleware('role_or_permission:owner|create-pengiriman');
    Route::get('shipments/completed/{type}/{courierIds}', [ShipmentController::class, 'completed'])->name('outbound.shipments.completed')->middleware('role_or_permission:owner|view-pengiriman');
    Route::get('shipments/by-courier/{courierCode}', [ShipmentController::class, 'byCourier'])->name('outbound.shipments.by-courier')->middleware('role_or_permission:owner|view-pengiriman');
    Route::post('shipments/{id}/driver-call', [ShipmentController::class, 'driverCall'])->name('outbound.shipments.driver-call')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::patch('shipments/{id}/driver-call', [ShipmentController::class, 'updateDriverCall'])->name('outbound.shipments.update-driver-call')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::post('shipments/{id}/mark-delivered', [ShipmentController::class, 'markDelivered'])->name('outbound.shipments.mark-delivered')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::post('shipments/{id}/reconcile', [ShipmentController::class, 'reconcile'])->name('outbound.shipments.reconcile')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::post('shipments/{id}/refresh-tracking', [ShipmentController::class, 'refreshTracking'])->name('outbound.shipments.refresh-tracking')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::get('shipments/{id}/tracking-events', [ShipmentController::class, 'trackingEvents'])->name('outbound.shipments.tracking-events')->middleware('role_or_permission:owner|view-pengiriman');
    Route::get('shipments/{id}', [ShipmentController::class, 'show'])->name('outbound.shipments.show')->middleware('role_or_permission:owner|view-pengiriman');
    Route::get('shipments/{id}/orders', [ShipmentController::class, 'orders'])->name('outbound.shipments.orders')->middleware('role_or_permission:owner|view-pengiriman');
    Route::post('shipments/{id}/scan-order', [ShipmentController::class, 'scanOrder'])->name('outbound.shipments.scan-order');
    Route::post('shipments/{id}/add-orders', [ShipmentController::class, 'addOrders'])->name('outbound.shipments.add-orders')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::post('shipments/{id}/remove-orders', [ShipmentController::class, 'removeOrders'])->name('outbound.shipments.remove-orders')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::post('shipments/{id}/save-awb', [ShipmentController::class, 'saveAwb'])->name('outbound.shipments.save-awb')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::post('shipments/{id}/hand-over', [ShipmentController::class, 'handOver'])->name('outbound.shipments.hand-over')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::post('shipments/{id}/update-handover-qty', [ShipmentController::class, 'updateHandoverQty'])->name('outbound.shipments.update-handover-qty')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::get('shipments/{id}/manifest-pdf', [ShipmentController::class, 'manifestPdf'])->name('outbound.shipments.manifest-pdf')->middleware('role_or_permission:owner|export-pengiriman');
    Route::get('shipments/{id}/manifest-excel', [ShipmentController::class, 'manifestExcel'])->name('outbound.shipments.manifest-excel')->middleware('role_or_permission:owner|export-pengiriman');
    Route::post('shipments/documents/bulk/manifest-pdf', [ShipmentController::class, 'bulkManifestPdf'])->name('outbound.shipments.bulk-manifest-pdf')->middleware('role_or_permission:owner|export-pengiriman');
    Route::post('shipments/{id}/cancel', [ShipmentController::class, 'cancel'])->name('outbound.shipments.cancel')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::delete('shipments/{id}', [ShipmentController::class, 'destroy'])->name('outbound.shipments.destroy')->middleware('role_or_permission:owner|delete-pengiriman');

    Route::get('pre-manifest/cancelled', [PreManifestCancelController::class, 'index'])->name('outbound.pre-manifest.cancelled.index')->middleware('role_or_permission:owner|view-pengiriman');
    Route::get('pre-manifest/cancelled/count', [PreManifestCancelController::class, 'count'])->name('outbound.pre-manifest.cancelled.count')->middleware('role_or_permission:owner|view-pengiriman');
    Route::get('pre-manifest/cancelled/export', [PreManifestCancelController::class, 'export'])->name('outbound.pre-manifest.cancelled.export')->middleware('role_or_permission:owner|export-pengiriman');
    Route::patch('pre-manifest/cancelled/{orderId}/dismiss', [PreManifestCancelController::class, 'dismiss'])->name('outbound.pre-manifest.cancelled.dismiss')->middleware('role_or_permission:owner|edit-pengiriman');
    Route::patch('pre-manifest/cancelled/{orderId}/undismiss', [PreManifestCancelController::class, 'undismiss'])->name('outbound.pre-manifest.cancelled.undismiss')->middleware('role_or_permission:owner|edit-pengiriman');

    Route::get('couriers', [CourierController::class, 'index'])->name('outbound.couriers.index')->middleware('role_or_permission:owner|view-kurir');
    Route::get('couriers/all', [CourierController::class, 'all'])->name('outbound.couriers.all');
    Route::get('couriers/tenant/{tenantId}', [CourierController::class, 'byTenant'])->name('outbound.couriers.by-tenant')->middleware('role_or_permission:owner|view-kurir');
    Route::post('couriers', [CourierController::class, 'store'])->name('outbound.couriers.store')->middleware('role_or_permission:owner|create-kurir');
    Route::get('couriers/{id}', [CourierController::class, 'show'])->name('outbound.couriers.show')->middleware('role_or_permission:owner|view-kurir');
    Route::put('couriers/{id}', [CourierController::class, 'update'])->name('outbound.couriers.update')->middleware('role_or_permission:owner|edit-kurir');
    Route::delete('couriers/{id}', [CourierController::class, 'destroy'])->name('outbound.couriers.destroy')->middleware('role_or_permission:owner|delete-kurir');

    Route::get('wms/employee/{identifier}', [WmsController::class, 'employee'])->name('outbound.wms.employee');
    Route::get('wms/default-bin/{locationId}', [WmsController::class, 'defaultBin'])->name('outbound.wms.default-bin');
    Route::put('wms/default-bin/{locationId}', [WmsController::class, 'setDefaultBin'])->name('outbound.wms.set-default-bin')->middleware('role_or_permission:owner|edit-manajemen-rak');
});
