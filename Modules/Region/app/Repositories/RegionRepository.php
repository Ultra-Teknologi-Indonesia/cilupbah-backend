<?php

namespace Modules\Region\Repositories;

use Modules\Region\Models\Country;
use Modules\Region\Models\Province;
use Modules\Region\Models\City;
use Modules\Region\Models\District;
use Modules\Region\Models\Village;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RegionRepository
{

    public function getCountries()
    {
        return QueryBuilder::for(Country::class)
            ->select('id', 'alpha2', 'alpha3', 'name')
            ->allowedSearch('name', 'alpha2', 'alpha3')
            ->orderBy('name', 'asc')
            ->get();
    }

    public function getProvinces()
    {
        return QueryBuilder::for(Province::class)
            ->select('id', 'nama')
            ->allowedSearch('nama')
            ->allowedSorts('id', 'nama')
            ->defaultSort('id')
            ->get();
    }

    public function getCities($provinceId)
    {
        return QueryBuilder::for(City::class)
            ->select('id', 'province_id', 'nama')
            ->where('province_id', $provinceId)
            ->allowedSearch('nama')
            ->allowedSorts('id', 'nama', 'province_id')
            ->defaultSort('id')
            ->get();
    }

    public function getDistricts($cityId)
    {
        return QueryBuilder::for(District::class)
            ->select('id', 'city_id', 'nama')
            ->where('city_id', $cityId)
            ->allowedSearch('nama')
            ->allowedSorts('id', 'nama', 'city_id')
            ->defaultSort('id')
            ->get();
    }

    public function getVillages($districtId)
    {
        return QueryBuilder::for(Village::class)
            ->select('id', 'district_id', 'nama', 'latitude', 'longitude')
            ->where('district_id', $districtId)
            ->allowedSearch('nama')
            ->allowedSorts('id', 'nama', 'district_id')
            ->defaultSort('id')
            ->get();
    }
}
