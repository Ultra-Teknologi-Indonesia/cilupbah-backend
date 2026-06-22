<?php

namespace Modules\Sales\Exceptions;

use Exception;

class ProductNotMappableException extends Exception
{
    protected $code = 422;

    public function __construct(?string $sku = null)
    {
        $label = $sku ? "SKU '{$sku}'" : 'Produk';

        parent::__construct("{$label} belum ada di Master Produk. Download/buat produknya terlebih dahulu, atau pilih produk untuk dipetakan secara manual.");
    }
}
