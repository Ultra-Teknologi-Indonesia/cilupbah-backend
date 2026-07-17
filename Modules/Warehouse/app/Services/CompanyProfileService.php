<?php

namespace Modules\Warehouse\Services;

use App\Services\UploadService;
use Modules\Warehouse\Models\CompanyProfile;
use Modules\Warehouse\Repositories\CompanyProfileRepository;

class CompanyProfileService
{
    private ?array $pdfMemo = null;

    public function __construct(
        private readonly CompanyProfileRepository $repository,
        private readonly UploadService $uploads,
    ) {}

    public function get(): CompanyProfile
    {
        return $this->repository->current();
    }

    public function save(array $data): CompanyProfile
    {
        $this->pdfMemo = null;

        return $this->repository->update($data);
    }

    public function mediaUrl(?string $uuid): ?string
    {
        if (! $uuid) {
            return null;
        }

        return $this->uploads->findByUuid($uuid)?->getUrl();
    }

    public function forPdf(): array
    {
        if ($this->pdfMemo !== null) {
            return $this->pdfMemo;
        }

        $profile = $this->get();

        return $this->pdfMemo = [
            'name' => $profile->legal_name,
            'address' => $profile->address,
            'city' => $profile->city,
            'postal_code' => $profile->postal_code,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'logo_url' => $this->mediaUrl($profile->logo_media_id),
            'signature_url' => $this->mediaUrl($profile->signature_media_id),
        ];
    }
}
