<?php

namespace Modules\Outbound\Exceptions;

use Exception;

/**
 * Pelanggaran aturan bisnis outbound (mis. complete picklist saat masih ada
 * item belum di-pick). Dipetakan ke HTTP 422 di bootstrap/app.php, bukan 500,
 * karena ini kesalahan input/keadaan yang bisa diperbaiki user, bukan bug server.
 */
class OutboundValidationException extends Exception
{
    protected $code = 422;
}
