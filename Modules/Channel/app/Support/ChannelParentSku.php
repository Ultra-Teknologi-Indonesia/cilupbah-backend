<?php

namespace Modules\Channel\Support;

class ChannelParentSku
{
    public static function fromSingleVariant(array $variants): ?string
    {
        if (count($variants) !== 1) {
            return null;
        }

        $sku = $variants[0]['sku'] ?? null;

        return is_string($sku) && trim($sku) !== '' ? $sku : null;
    }
}
