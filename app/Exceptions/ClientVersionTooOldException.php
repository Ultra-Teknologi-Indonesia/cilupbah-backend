<?php

namespace App\Exceptions;

use Throwable;

class ClientVersionTooOldException extends UserFacingException
{
    public function __construct(
        string $currentVersion,
        string $minimumVersion,
        ?string $upgradeUrl = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            title: 'Update aplikasi diperlukan',
            message: "Versi aplikasi kamu ({$currentVersion}) sudah tidak didukung. Silakan update ke minimal versi {$minimumVersion}.",
            status: 426,
            errors: [
                'code' => 'CLIENT_VERSION_TOO_OLD',
                'current_version' => $currentVersion,
                'minimum_version' => $minimumVersion,
                'upgrade_url' => $upgradeUrl,
            ],
            previous: $previous,
        );
    }
}
