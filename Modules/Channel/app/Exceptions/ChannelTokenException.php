<?php

namespace Modules\Channel\Exceptions;

use RuntimeException;

/**
 * Kegagalan refresh token channel yang membawa pesan ramah-pengguna sekaligus
 * klasifikasi permanen/sementara.
 *
 * - getMessage() = pesan ramah (dipakai controller → errors.detail & disimpan ke last_error).
 * - $permanent   = true bila butuh otorisasi ulang (token dicabut/kedaluwarsa),
 *                  false bila hanya gangguan sementara (jaringan/marketplace down).
 * - $rawMessage  = pesan mentah dari marketplace, untuk log/diagnostik saja.
 */
class ChannelTokenException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        public readonly bool $permanent,
        public readonly ?string $channelCode = null,
        public readonly string $rawMessage = '',
    ) {
        parent::__construct($userMessage);
    }
}
