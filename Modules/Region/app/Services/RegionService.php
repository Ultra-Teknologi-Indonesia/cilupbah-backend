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

    public function getCities()
    {
        return $this->regionRepository->getCities();
    }

    public function getDistricts()
    {
        return $this->regionRepository->getDistricts();
    }

    public function getVillages()
    {
        return $this->regionRepository->getVillages();
    }
}
