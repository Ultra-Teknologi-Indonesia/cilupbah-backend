<?php

namespace Modules\Product\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Product\Observers\ProductObserver;
use Modules\Product\Observers\ProductVariantChannelMappingObserver;
use Modules\Product\Observers\ProductVariantObserver;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    protected static $shouldDiscoverEvents = true;

    protected function configureEmailVerification(): void {}

    public function boot()
    {
        parent::boot();

        Product::observe(ProductObserver::class);
        ProductVariant::observe(ProductVariantObserver::class);
        ProductVariantChannelMapping::observe(ProductVariantChannelMappingObserver::class);
    }
}
