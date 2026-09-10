<?php

declare(strict_types=1);

namespace Modules\Channel\Exceptions;

final class ChannelOrderPullIncompleteException extends \RuntimeException
{

    public static function forOrders(string $channel, string $shopId, array $orderReferences): self
    {
        $references = array_values(array_unique(array_filter($orderReferences)));
        $sample = implode(', ', array_slice($references, 0, 10));

        return new self(sprintf(
            '%s order pull untuk toko %s belum lengkap: %d order gagal diproses%s.',
            ucfirst($channel),
            $shopId,
            count($references),
            $sample === '' ? '' : ' (contoh: '.$sample.')',
        ));
    }

    public static function pageLimitReached(string $channel, string $shopId, int $pages): self
    {
        return new self(sprintf(
            '%s order pull untuk toko %s mencapai batas %d halaman sebelum selesai.',
            ucfirst($channel),
            $shopId,
            $pages,
        ));
    }
}
