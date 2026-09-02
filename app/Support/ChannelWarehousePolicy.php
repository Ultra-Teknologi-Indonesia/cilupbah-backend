<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\UserFacingException;
use Modules\Warehouse\Models\Location;

final class ChannelWarehousePolicy
{
    public function isChannelSource(?string $source): bool
    {
        return ! in_array(strtolower(trim((string) $source)), ['', 'manual', 'offline'], true);
    }

    public function officialSmallWarehouseId(): string
    {
        $locationId = Location::getOfficialSmallWarehouseId();

        if (! $locationId) {
            throw new UserFacingException(
                'Gudang Kecil Belum Diatur',
                'Gudang Kecil resmi belum dikonfigurasi, proses pesanan channel dihentikan untuk mencegah pemotongan stok dari lokasi yang salah.',
                422,
            );
        }

        return (string) $locationId;
    }

    public function assertChannelLocation(?string $source, ?string $locationId, string $operation): void
    {
        if (! $this->isChannelSource($source)) {
            return;
        }

        $officialLocationId = $this->officialSmallWarehouseId();

        if ($locationId === null || (string) $locationId !== $officialLocationId) {
            throw new UserFacingException(
                'Lokasi Pesanan Channel Tidak Valid',
                "{$operation} pesanan channel hanya boleh dilakukan di Gudang Kecil.",
                422,
                [
                    'required_location_id' => $officialLocationId,
                    'actual_location_id' => $locationId,
                ],
            );
        }
    }

    public function assertOrderAndTargetLocation(
        ?string $source,
        ?string $orderLocationId,
        ?string $targetLocationId,
        string $operation,
    ): void {
        $this->assertChannelLocation($source, $orderLocationId, $operation);
        $this->assertChannelLocation($source, $targetLocationId, $operation);

        if ($this->isChannelSource($source)
            && (string) $orderLocationId !== (string) $targetLocationId
        ) {
            throw new UserFacingException(
                'Lokasi Order dan Proses Berbeda',
                "{$operation} pesanan channel harus memakai lokasi order yang sama dengan lokasi proses.",
                422,
                [
                    'order_location_id' => $orderLocationId,
                    'target_location_id' => $targetLocationId,
                ],
            );
        }
    }
}
