<?php

declare(strict_types=1);

namespace Modules\Product\Exceptions;

use DomainException;
use Illuminate\Support\Collection;

final class ChannelSkuConflictException extends DomainException
{

    public function __construct(private readonly Collection $conflicts, ?\Throwable $previous = null)
    {
        parent::__construct(self::buildMessage($conflicts), 0, $previous);
    }

    public function conflicts(): Collection
    {
        return $this->conflicts;
    }

    private static function buildMessage(Collection $conflicts): string
    {
        $items = $conflicts
            ->map(function (object $conflict): string {
                $owner = trim((string) ($conflict->product_name ?? ''));
                $ownerSku = trim((string) ($conflict->product_sku ?? ''));
                $ownerLabel = $owner !== '' ? $owner : 'produk lama';

                if ($ownerSku !== '') {
                    $ownerLabel .= " (SKU {$ownerSku})";
                }

                if ($conflict->product_deleted_at !== null) {
                    $ownerLabel .= ' yang sudah dihapus';
                }

                return "{$conflict->sku} masih terdaftar pada {$ownerLabel}";
            })
            ->unique()
            ->take(10)
            ->implode('; ');

        $suffix = $conflicts->count() > 10 ? ' dan SKU lainnya' : '';

        return "Download dibatalkan karena SKU channel sudah digunakan: {$items}{$suffix}. "
            .'Hubungkan listing ke master yang benar atau selesaikan produk lama tersebut, lalu coba download lagi.';
    }
}
