<?php

namespace Modules\Channel\Exceptions;

use Exception;

/**
 * Ditandai saat marketplace tidak menyediakan label pengiriman via API untuk
 * jenis order tertentu (mis. Lazada SOF/DBS — seller harus ambil label dari
 * Seller Center secara manual). Berbeda dari "label belum siap": ini terminal,
 * tidak akan pernah siap lewat API sehingga tidak perlu di-retry.
 */
class ChannelLabelUnsupportedException extends Exception
{
    protected $code = 422;

    public function __construct(string $message = 'Label pengiriman tidak tersedia via API untuk order ini.')
    {
        parent::__construct($message);
    }
}
