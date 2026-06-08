<?php

namespace Modules\Product\Services;

use Modules\Product\Models\Product;

/**
 * Transisi state machine produk: download -> in_review -> master -> archived.
 * Melempar \DomainException untuk pelanggaran aturan transisi (dipetakan ke HTTP 422).
 */
class ProductLifecycleService
{
    /**
     * in_review -> master
     */
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

    /**
     * in_review -> download
     */
    public function reject(Product $product): Product
    {
        if ($product->status !== Product::STATUS_IN_REVIEW) {
            throw new \DomainException('Produk tidak dalam status In Review');
        }

        $product->update(['status' => Product::STATUS_DOWNLOAD]);

        return $product;
    }

    /**
     * master -> archived
     */
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

    /**
     * archived -> master
     */
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

    /**
     * Validasi kelengkapan data sebelum produk boleh menjadi Master.
     */
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
