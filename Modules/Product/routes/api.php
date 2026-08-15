<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ChannelProductController;
use Modules\Product\Http\Controllers\MediaController;
use Modules\Product\Http\Controllers\CategoryController;
use Modules\Product\Http\Controllers\AttributeController;
use Modules\Product\Http\Controllers\ChannelCategoryController;
use Modules\Product\Http\Controllers\ProductChannelDraftController;
use Modules\Product\Http\Controllers\ProductSyncLogController;
use Modules\Product\Http\Controllers\ChannelMonitorController;
use Modules\Product\Http\Controllers\ProductMergeController;
use Modules\Product\Http\Controllers\MasterFeedController;
use Modules\Product\Http\Controllers\ReviewFeedController;
use Modules\Product\Http\Controllers\ArchiveFeedController;
use Modules\Product\Http\Controllers\ChannelProductListingController;
use Modules\Product\Http\Controllers\ProductPantauanController;
use Modules\Product\Http\Controllers\ProductUploadListingController;
use Modules\Product\Http\Controllers\RaiseProductController;
use Modules\Product\Http\Controllers\VariantController;
use Modules\Product\Http\Controllers\PriceListController;
use Modules\Product\Http\Controllers\CatalogController;
use Modules\Product\Http\Controllers\ProductMasterDataController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('products/master-data/sales-taxes', [ProductMasterDataController::class, 'salesTaxes'])->middleware('role_or_permission:owner|view-produk');
    Route::get('products/master-data/purchase-taxes', [ProductMasterDataController::class, 'purchaseTaxes'])->middleware('role_or_permission:owner|view-produk');
    Route::get('products/master-data/sales-accounts', [ProductMasterDataController::class, 'salesAccounts'])->middleware('role_or_permission:owner|view-produk');
    Route::get('products/master-data/sales-return-accounts', [ProductMasterDataController::class, 'salesReturnAccounts'])->middleware('role_or_permission:owner|view-produk');
    Route::get('products/master-data/inventory-accounts', [ProductMasterDataController::class, 'inventoryAccounts'])->middleware('role_or_permission:owner|view-produk');
    Route::get('products/master-data/cogs-accounts', [ProductMasterDataController::class, 'cogsAccounts'])->middleware('role_or_permission:owner|view-produk');

    Route::middleware('role_or_permission:owner|view-produk-naik')->group(function () {
        Route::get('products/uploadable', [ProductController::class, 'uploadable']);
        Route::get('products/channel-drafts', [ProductChannelDraftController::class, 'list']);
    });

    Route::middleware('role_or_permission:owner|edit-produk-naik')->group(function () {
        Route::post('products/channel-drafts/bulk-upload', [ProductChannelDraftController::class, 'bulkUpload']);
        Route::post('products/channel-drafts/{draft}/upload', [ProductChannelDraftController::class, 'upload'])->whereUuid('draft');
    });

    Route::middleware('role_or_permission:owner|view-produk')->group(function () {
        Route::get('products/master', [MasterFeedController::class, 'index']);
        Route::get('products/master/{id}', [MasterFeedController::class, 'show'])->whereUuid('id');

        Route::get('products/downloaded', [MasterFeedController::class, 'downloaded']);

        Route::get('products/reviews', [ReviewFeedController::class, 'index']);

        Route::get('products/archives', [ArchiveFeedController::class, 'index']);
        Route::get('products/archives/{id}', [ArchiveFeedController::class, 'show'])->whereUuid('id');

        Route::get('products/channel-products', [ChannelProductListingController::class, 'index']);
        Route::get('products/channel-products/{id}', [ChannelProductListingController::class, 'show'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-pantauan-produk')->group(function () {
        Route::get('products/pantauan', [ProductPantauanController::class, 'index']);
    });

    Route::middleware('role_or_permission:owner|view-produk-naik')->group(function () {
        Route::get('raise-products', [RaiseProductController::class, 'index']);
        Route::get('raise-products/{id}', [RaiseProductController::class, 'show'])->whereUuid('id');
        Route::get('raise-products/{id}/history', [RaiseProductController::class, 'history'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|create-produk-naik')->group(function () {
        Route::post('raise-products', [RaiseProductController::class, 'store']);
    });

    Route::middleware('role_or_permission:owner|edit-produk-naik')->group(function () {
        Route::post('raise-products/{id}/raise', [RaiseProductController::class, 'raise'])->whereUuid('id');
        Route::post('raise-products/{id}/products', [RaiseProductController::class, 'addProduct'])->whereUuid('id');
        Route::patch('raise-products/{id}/products/{detailId}', [RaiseProductController::class, 'updateProduct'])->whereUuid('id')->whereUuid('detailId');
    });

    Route::middleware('role_or_permission:owner|delete-produk-naik')->group(function () {
        Route::delete('raise-products/{id}/products/{detailId}', [RaiseProductController::class, 'removeProduct'])->whereUuid('id')->whereUuid('detailId');
        Route::delete('raise-products/{id}', [RaiseProductController::class, 'destroy'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-product-merge')->group(function () {
        Route::get('products/merge/catalog', [ProductMergeController::class, 'catalog']);
        Route::get('products/merge/suggestions', [ProductMergeController::class, 'suggestions']);
        Route::get('products/merge/applied', [ProductMergeController::class, 'applied']);
    });

    Route::middleware('role_or_permission:owner|auto-merge-product')->group(function () {
        Route::post('products/merge/auto', [ProductMergeController::class, 'auto']);
    });

    Route::middleware('role_or_permission:owner|merge-product')->group(function () {
        Route::post('products/merge/apply', [ProductMergeController::class, 'apply']);
        Route::post('products/merge/bulk', [ProductMergeController::class, 'bulk']);
    });

    Route::middleware('role_or_permission:owner|unmerge-product')->group(function () {
        Route::post('products/merge/bulk-unmerge', [ProductMergeController::class, 'bulkUnmerge']);

        Route::delete('products/merge/master', [ProductMergeController::class, 'unmergeMaster']);
        Route::delete('products/merge/{product}', [ProductMergeController::class, 'unmerge'])->whereUuid('product');
    });

    Route::middleware('role_or_permission:owner|hide-product')->group(function () {
        Route::post('products/merge/hide', [ProductMergeController::class, 'hide']);
        Route::post('products/merge/unhide', [ProductMergeController::class, 'unhide']);
    });

    Route::middleware('role_or_permission:owner|edit-produk')->group(function () {
        Route::post('products/bulk-archive', [ProductController::class, 'bulkArchive']);
        Route::post('products/bulk-restore', [ProductController::class, 'bulkRestore']);
    });

    Route::middleware('role_or_permission:owner|delete-produk')->group(function () {
        Route::post('products/bulk-delete', [ProductController::class, 'bulkDelete']);
    });

    Route::middleware('role_or_permission:owner|view-produk')->group(function () {
        Route::get('products', [ProductController::class, 'index'])->name('product.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('product.show')
            ->where('product', '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}');
    });

    Route::middleware('role_or_permission:owner|create-produk')->group(function () {
        Route::post('products', [ProductController::class, 'store'])->name('product.store');
    });

    Route::middleware('role_or_permission:owner|edit-produk')->group(function () {
        Route::match(['PUT', 'PATCH'], 'products/{product}', [ProductController::class, 'update'])->name('product.update')
            ->where('product', '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}');
    });

    Route::middleware('role_or_permission:owner|delete-produk')->group(function () {
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('product.destroy')
            ->where('product', '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}');
    });

    Route::middleware('role_or_permission:owner|edit-produk')->group(function () {
        Route::post('products/{id}/approve', [ProductController::class, 'approve'])->whereUuid('id');
        Route::post('products/{id}/archive', [ProductController::class, 'archive'])->whereUuid('id');
        Route::post('products/{id}/restore', [ProductController::class, 'restore'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-produk')->group(function () {
        Route::get('products/{id}/variants', [ProductController::class, 'variants'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|edit-produk')->group(function () {
        Route::post('products/{id}/variants/bulk', [ProductController::class, 'bulkVariants'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-produk')->group(function () {
        Route::get('products/{id}/channel-listings', [ProductController::class, 'channelListings'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-produk-naik')->group(function () {
        Route::get('products/{id}/upload-listing', [ProductUploadListingController::class, 'index'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|edit-produk-naik')->group(function () {
        Route::post('products/{id}/upload-listing/match', [ProductUploadListingController::class, 'match'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-produk')->group(function () {
        Route::get('products/{id}/price-book', [ProductController::class, 'priceBook'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-produk-naik')->group(function () {
        Route::get('products/{id}/channel-drafts/required-attributes', [ProductChannelDraftController::class, 'requiredAttributes'])->whereUuid('id');
        Route::get('products/{id}/channel-drafts', [ProductChannelDraftController::class, 'index'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|create-produk-naik')->group(function () {
        Route::post('products/{id}/channel-drafts', [ProductChannelDraftController::class, 'store'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|edit-produk-naik')->group(function () {
        Route::put('products/{id}/channel-drafts/{draft}', [ProductChannelDraftController::class, 'update'])->whereUuid('id')->whereUuid('draft');
    });

    Route::middleware('role_or_permission:owner|delete-produk-naik')->group(function () {
        Route::delete('products/{id}/channel-drafts/{draft}', [ProductChannelDraftController::class, 'destroy'])->whereUuid('id')->whereUuid('draft');
    });

    Route::middleware('role_or_permission:owner|view-produk-naik')->group(function () {
        Route::get('upload-histories', [ProductSyncLogController::class, 'uploadHistories']);
    });

    Route::middleware('role_or_permission:owner|delete-produk-naik')->group(function () {
        Route::post('upload-histories/bulk-delete', [ProductSyncLogController::class, 'bulkDestroy']);
    });

    Route::middleware('role_or_permission:owner|edit-produk-naik')->group(function () {
        Route::post('upload-histories/{id}/re-upload', [ProductSyncLogController::class, 'reupload'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|delete-produk-naik')->group(function () {
        Route::delete('upload-histories/{id}', [ProductSyncLogController::class, 'destroy'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-produk-naik')->group(function () {
        Route::get('download-histories', [ProductSyncLogController::class, 'downloadHistories']);
    });

    Route::middleware('role_or_permission:owner|view-pantauan-produk')->group(function () {
        Route::post('channel-monitor/refresh', [ChannelMonitorController::class, 'refresh']);
        Route::get('channel-monitor', [ChannelMonitorController::class, 'index']);
        Route::get('channel-monitor/summary', [ChannelMonitorController::class, 'summary']);
        Route::get('channel-monitor/{shop_id}', [ChannelMonitorController::class, 'detail']);
        Route::get('channel-monitor/{shop_id}/products', [ChannelMonitorController::class, 'products']);
    });

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('categories/system', [CategoryController::class, 'systemCategories']);
    });

    Route::middleware('role_or_permission:owner|edit-kategori')->group(function () {
        Route::post('categories/enable', [CategoryController::class, 'enableCategories']);
        Route::post('categories/disable', [CategoryController::class, 'disableCategories']);
    });

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('categories/mapping', [CategoryController::class, 'mappingList']);
    });

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('categories', [CategoryController::class, 'index'])->name('category.index');
        Route::get('categories/{category}', [CategoryController::class, 'show'])->name('category.show')->where('category', '[0-9]+');
    });

    Route::middleware('role_or_permission:owner|create-kategori')->group(function () {
        Route::post('categories', [CategoryController::class, 'store'])->name('category.store');
    });

    Route::middleware('role_or_permission:owner|edit-kategori')->group(function () {
        Route::match(['PUT', 'PATCH'], 'categories/{category}', [CategoryController::class, 'update'])->name('category.update')->where('category', '[0-9]+');
    });

    Route::middleware('role_or_permission:owner|delete-kategori')->group(function () {
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('category.destroy')->where('category', '[0-9]+');
    });

    Route::middleware('role_or_permission:owner|edit-kategori')->group(function () {
        Route::post('categories/{category}/map-channel', [CategoryController::class, 'mapChannel'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('categories/{category}/attribute-mapping', [CategoryController::class, 'attributeMapping'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|create-kategori')->group(function () {
        Route::post('categories/{category}/attribute-mapping', [CategoryController::class, 'storeAttributeMapping'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|delete-kategori')->group(function () {
        Route::delete('categories/{category}/attribute-mapping', [CategoryController::class, 'removeAttributeMapping'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('categories/{category}/variation-mapping', [CategoryController::class, 'variationMapping'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|create-kategori')->group(function () {
        Route::post('categories/{category}/variation-mapping', [CategoryController::class, 'storeAttributeMapping'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|delete-kategori')->group(function () {
        Route::delete('categories/{category}/variation-mapping', [CategoryController::class, 'removeAttributeMapping'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('categories/{category}/available-channel-attributes', [CategoryController::class, 'availableChannelAttributes'])->whereNumber('category');

        Route::get('categories/{category}/form-attributes', [\Modules\Product\Http\Controllers\CategoryFormAttributeController::class, 'show'])->whereNumber('category')->name('category.form-attributes');
    });

    Route::middleware('role_or_permission:owner|create-kategori')->group(function () {
        Route::post('categories/{category}/attributes', [\Modules\Product\Http\Controllers\CategoryFormAttributeController::class, 'store'])->whereNumber('category');
    });

    Route::middleware('role_or_permission:owner|delete-kategori')->group(function () {
        Route::delete('categories/{category}/attributes/{attribute}', [\Modules\Product\Http\Controllers\CategoryFormAttributeController::class, 'destroy'])->whereNumber('category')->whereNumber('attribute');
    });

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('attributes', [AttributeController::class, 'index'])->name('attribute.index');
        Route::get('attributes/{attribute}', [AttributeController::class, 'show'])->name('attribute.show')->where('attribute', '[0-9]+');
    });

    Route::middleware('role_or_permission:owner|create-kategori')->group(function () {
        Route::post('attributes', [AttributeController::class, 'store'])->name('attribute.store');
    });

    Route::middleware('role_or_permission:owner|edit-kategori')->group(function () {
        Route::match(['PUT', 'PATCH'], 'attributes/{attribute}', [AttributeController::class, 'update'])->name('attribute.update')->where('attribute', '[0-9]+');
    });

    Route::middleware('role_or_permission:owner|delete-kategori')->group(function () {
        Route::delete('attributes/{attribute}', [AttributeController::class, 'destroy'])->name('attribute.destroy')->where('attribute', '[0-9]+');
    });

    Route::middleware('role_or_permission:owner|edit-kategori')->group(function () {
        Route::post('attributes/{attribute}/map-channel', [AttributeController::class, 'mapChannel'])->whereNumber('attribute');
        Route::post('attributes/options/{option}/map-channel', [AttributeController::class, 'mapOptionChannel'])->whereNumber('option');
    });

    Route::post('media/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('media/upload/{uuid}', [MediaController::class, 'show'])->whereUuid('uuid')->name('media.show');
    Route::put('media/upload/{uuid}', [MediaController::class, 'replace'])->whereUuid('uuid')->name('media.replace');
    Route::delete('media/upload/{uuid}', [MediaController::class, 'destroy'])->whereUuid('uuid')->name('media.destroy');

    Route::middleware('role_or_permission:owner|import-produk')->group(function () {
        Route::get('products/import/template/single', [\Modules\Product\Http\Controllers\ProductImportController::class, 'downloadSingleTemplate']);
        Route::get('products/import/template/bundle', [\Modules\Product\Http\Controllers\ProductImportController::class, 'downloadBundleTemplate']);
        Route::post('products/import/single', [\Modules\Product\Http\Controllers\ProductImportController::class, 'importSingle']);
        Route::post('products/import/bundle', [\Modules\Product\Http\Controllers\ProductImportController::class, 'importBundle']);
        Route::get('products/import/batches', [\Modules\Product\Http\Controllers\ProductImportController::class, 'batches']);
        Route::get('products/import/batches/{batch}', [\Modules\Product\Http\Controllers\ProductImportController::class, 'show'])->whereUuid('batch');
        Route::get('products/import/batches/{batch}/rows', [\Modules\Product\Http\Controllers\ProductImportController::class, 'rows'])->whereUuid('batch');
        Route::post('products/import/batches/{batch}/confirm', [\Modules\Product\Http\Controllers\ProductImportController::class, 'confirm'])->whereUuid('batch');
        Route::get('products/import/batches/{batch}/errors', [\Modules\Product\Http\Controllers\ProductImportController::class, 'errors'])->whereUuid('batch');
        Route::get('products/import/batches/{batch}/errors/download', [\Modules\Product\Http\Controllers\ProductImportController::class, 'downloadErrors'])->whereUuid('batch');
    });

    Route::get('inventory/items/by-sku/{sku}', [ProductController::class, 'showBySku'])->middleware('role_or_permission:owner|view-produk');
    Route::get('inventory/item-bundles', [ProductController::class, 'bundles'])->middleware('role_or_permission:owner|view-bundle');

    Route::post('inventory/items/variant-stocks', [ProductController::class, 'allStocks'])->middleware('role_or_permission:owner|view-posisi-stok');
    Route::post('inventory/items/prices', [ProductController::class, 'prices'])->middleware('role_or_permission:owner|view-harga-jual');
    Route::post('inventory/items', [ProductController::class, 'storeBundle'])->middleware('role_or_permission:owner|create-bundle');

    Route::get('inventory/items/channel-category-attributes', [\Modules\Product\Http\Controllers\ChannelAttributeController::class, 'all'])->middleware('role_or_permission:owner|view-kategori');

    Route::get('inventory/categories/category-map/{id}', [CategoryController::class, 'channelMap'])->whereNumber('id')->middleware('role_or_permission:owner|view-kategori');

    Route::middleware('role_or_permission:owner|view-kategori')->group(function () {
        Route::get('variations', [VariantController::class, 'index']);
    });

    Route::middleware('role_or_permission:owner|delete-kategori')->group(function () {
        Route::delete('inventory/items/item-variant', [VariantController::class, 'destroy']);
    });

    Route::get('inventory/internal-price-list', [PriceListController::class, 'index'])->middleware('role_or_permission:owner|view-harga-jual');
    Route::post('inventory/price-list', [PriceListController::class, 'update'])->middleware('role_or_permission:owner|edit-harga-jual');

    Route::middleware('role_or_permission:owner|create-produk-naik')->group(function () {
        Route::post('inventory/catalog/listing', [ProductChannelDraftController::class, 'catalogListing']);
    });

    Route::middleware('role_or_permission:owner|view-produk-naik')->group(function () {
        Route::get('inventory/catalog/for-listing/{id}', [CatalogController::class, 'forListing'])->whereUuid('id');
        Route::get('inventory/items/group/{id}', [CatalogController::class, 'forListing'])->whereUuid('id');
        Route::get('inventory/catalog/{group_id}', [CatalogController::class, 'group']);
    });

    Route::middleware('role_or_permission:owner|edit-produk-naik')->group(function () {
        Route::post('inventory/catalog/upload', [ProductChannelDraftController::class, 'bulkUpload']);
    });

    Route::get('inventory/items/errors', [ProductSyncLogController::class, 'errors'])->middleware('role_or_permission:owner|view-produk');
});

Route::middleware(['auth:sanctum'])->prefix('v1/{channel}')->group(function () {

    Route::get('categories', [ChannelCategoryController::class, 'index'])->middleware('role_or_permission:owner|view-kategori');
    Route::get('categories/{categoryId}/attributes', [\Modules\Product\Http\Controllers\ChannelAttributeController::class, 'index'])->middleware('role_or_permission:owner|view-kategori');

    Route::middleware('role_or_permission:owner|view-produk')->group(function () {
        Route::get('products/categories', [ChannelProductController::class, 'categories']);
    });

    Route::middleware('role_or_permission:owner|edit-produk')->group(function () {
        Route::put('products/{id}/activate', [ChannelProductController::class, 'activate']);
        Route::put('products/{id}/deactivate', [ChannelProductController::class, 'deactivate']);
        Route::put('products/{id}/stock', [ChannelProductController::class, 'updateStock']);
        Route::put('products/{id}/price', [ChannelProductController::class, 'updatePrice']);
    });

    Route::middleware('role_or_permission:owner|delete-produk')->group(function () {
        Route::delete('products/{id}/link', [ChannelProductController::class, 'unlink']);
    });

    Route::apiResource('products', ChannelProductController::class)->names('channel.product')
        ->middlewareFor(['index', 'show'], 'role_or_permission:owner|view-produk')
        ->middlewareFor('store', 'role_or_permission:owner|create-produk')
        ->middlewareFor('update', 'role_or_permission:owner|edit-produk')
        ->middlewareFor('destroy', 'role_or_permission:owner|delete-produk');
});
