<?php

namespace Modules\Region\Services;

use Modules\Region\Repositories\RegionRepository;

class RegionService
{
    protected RegionRepository $regionRepository;

    public function __construct(RegionRepository $regionRepository)
    {
        $this->regionRepository = $regionRepository;
    }

    public function getProvinces()
    {
        return $this->regionRepository->getProvinces();
    }

    public function getCities($provinceId)
    {
        return $this->regionRepository->getCities($provinceId);
    }

    public function getDistricts($cityId)
    {
        return $this->regionRepository->getDistricts($cityId);
    }

    public function getVillages($districtId)
    {
        return $this->regionRepository->getVillages($districtId);
    }
}
