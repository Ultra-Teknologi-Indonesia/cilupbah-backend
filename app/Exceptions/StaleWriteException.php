<?php

namespace App\Exceptions;

use Throwable;

class StaleWriteException extends UserFacingException
{
    public function __construct(
        ?string $lastEditorName = null,
        ?string $lastEditedAt = null,
        ?Throwable $previous = null,
    ) {
        $editorPart = $lastEditorName ? "oleh {$lastEditorName}" : 'oleh pengguna lain';
        $timePart = $lastEditedAt ? " pada {$lastEditedAt}" : '';

        parent::__construct(
            title: 'Data sudah berubah',
            message: "Data telah diubah {$editorPart}{$timePart}. Silakan refresh halaman sebelum menyimpan.",
            status: 412,
            errors: [
                'code' => 'STALE_WRITE',
                'last_editor_name' => $lastEditorName,
                'last_edited_at' => $lastEditedAt,
            ],
            previous: $previous,
        );
    }
}
