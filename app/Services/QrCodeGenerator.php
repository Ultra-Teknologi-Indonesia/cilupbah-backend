<?php

namespace App\Services;

use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

class QrCodeGenerator
{
    public function svgDataUri(string $content, int $size = 160, int $margin = 0, string $errorCorrection = 'M'): ?string
    {
        try {
            $svg = QrCode::format('svg')
                ->size($size)
                ->margin($margin)
                ->errorCorrection($errorCorrection)
                ->generate($content);

            return 'data:image/svg+xml;base64,' . base64_encode((string) $svg);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function mapDataUris(iterable $items, callable $keyResolver, callable $contentResolver, int $size = 160): array
    {
        $map = [];

        foreach ($items as $item) {
            $key = $keyResolver($item);
            $content = $contentResolver($item);

            if ($key === null || $content === null || $content === '') {
                continue;
            }

            $map[$key] = $this->svgDataUri((string) $content, $size);
        }

        return $map;
    }
}
