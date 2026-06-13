<?php

namespace Modules\Product\Services;

use Modules\Product\Models\Product;

class ProductLifecycleService
{

    public function submitForReview(Product $product): Product
    {
        if ($product->status !== Product::STATUS_DOWNLOAD) {
            throw new \DomainException('Hanya produk dengan status Download yang bisa diajukan ke Review');
        }

        $product->update(['status' => Product::STATUS_IN_REVIEW]);

        return $product;
    }

    public function approve(Product $product, ?string $userId = null): Product
    {
        if ($product->status !== Product::STATUS_IN_REVIEW) {
            throw new \DomainException('Produk tidak dalam status In Review');
        }

        $this->assertReadyForMaster($product);

        $product->update([
            'status' => Product::STATUS_MASTER,
            'verified_at' => now(),
            'verified_by' => $userId,
        ]);

        return $product;
    }

    public function reject(Product $product): Product
    {
        if ($product->status !== Product::STATUS_IN_REVIEW) {
            throw new \DomainException('Produk tidak dalam status In Review');
        }

        $product->update(['status' => Product::STATUS_DOWNLOAD]);

        return $product;
    }

    public function archive(Product $product, ?string $reason = null, ?string $userId = null): Product
    {
        if ($product->status === Product::STATUS_ARCHIVED) {
            throw new \DomainException('Produk sudah dalam status Arsip');
        }

        if ($product->status !== Product::STATUS_MASTER) {
            throw new \DomainException('Hanya produk Master yang bisa diarsipkan');
        }

        $product->update([
            'status' => Product::STATUS_ARCHIVED,
            'archived_at' => now(),
            'archived_by' => $userId,
            'archive_reason' => $reason,
        ]);

        return $product;
    }

    public function restore(Product $product): Product
    {
        if ($product->status !== Product::STATUS_ARCHIVED) {
            throw new \DomainException('Produk tidak dalam status Arsip');
        }

        $product->update([
            'status' => Product::STATUS_MASTER,
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
        ]);

        return $product;
    }

    protected function assertReadyForMaster(Product $product): void
    {
        $product->loadMissing(['variants', 'media']);

        if (empty($product->name)) {
            throw new \DomainException('Nama produk tidak boleh kosong');
        }

        if ($product->variants->isEmpty()) {
            throw new \DomainException('Produk harus memiliki minimal 1 variant');
        }

        foreach ($product->variants as $variant) {
            if (empty($variant->sku) || $variant->sell_price === null) {
                throw new \DomainException('Setiap variant harus memiliki SKU dan harga');
            }
        }

        if ($product->media->isEmpty()) {
            throw new \DomainException('Produk harus memiliki minimal 1 gambar');
        }
    }
}
