<?php

namespace Modules\Warehouse\Http\Resources;

use App\Services\QrCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BinQrItemResource extends JsonResource
{
    public function __construct($resource, protected int $qrSize)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'bin_final_code' => (string) $this->bin_final_code,
            'qr_data_uri' => app(QrCodeGenerator::class)->svgDataUri((string) $this->bin_final_code, $this->qrSize),
        ];
    }

    public static function mapCollection(iterable $bins, int $qrSize): array
    {
        $items = [];
        foreach ($bins as $bin) {
            $items[] = (new self($bin, $qrSize))->resolve();
        }

        return $items;
    }
}
