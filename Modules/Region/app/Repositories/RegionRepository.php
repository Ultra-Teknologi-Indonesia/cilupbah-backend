<?php

namespace Modules\Region\Repositories;


use Modules\Region\Models\Province;
use Modules\Region\Models\City;
use Modules\Region\Models\District;
use Modules\Region\Models\Village;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RegionRepository
{


    public function getProvinces()
    {
        return QueryBuilder::for(Province::class)
            ->allowedSearch('nama')
            ->allowedSorts('id', 'nama')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getCities()
    {
        return QueryBuilder::for(City::class)
            ->allowedSearch('nama')
            ->allowedFilters(
                AllowedFilter::exact('province_id')
            )
            ->allowedSorts('id', 'nama', 'province_id')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getDistricts()
    {
        return QueryBuilder::for(District::class)
            ->allowedSearch('nama')
            ->allowedFilters(
                AllowedFilter::exact('city_id')
            )
            ->allowedSorts('id', 'nama', 'city_id')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getVillages()
    {
        return QueryBuilder::for(Village::class)
            ->allowedSearch('nama')
            ->allowedFilters(
                AllowedFilter::exact('district_id')
            )
            ->allowedSorts('id', 'nama', 'district_id')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }
}
