<?php

namespace Modules\Region\Repositories;

use App\Filters\FuzzyFilter;
use Modules\Region\Models\Province;
use Modules\Region\Models\City;
use Modules\Region\Models\District;
use Modules\Region\Models\Village;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RegionRepository
{
    private function mergeSearchFilter()
    {
        $request = request();
        if ($request->has('search')) {
            $request->merge([
                'filter' => array_merge($request->input('filter', []), ['search' => $request->input('search')])
            ]);
        }
    }

    public function getProvinces()
    {
        $this->mergeSearchFilter();
        return QueryBuilder::for(Province::class)
            ->allowedFilters(
                AllowedFilter::custom('search', new FuzzyFilter(), 'nama')
            )
            ->allowedSorts('id', 'nama')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getCities()
    {
        $this->mergeSearchFilter();
        return QueryBuilder::for(City::class)
            ->allowedFilters(
                AllowedFilter::custom('search', new FuzzyFilter(), 'nama'),
                AllowedFilter::exact('province_id')
            )
            ->allowedSorts('id', 'nama', 'province_id')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getDistricts()
    {
        $this->mergeSearchFilter();
        return QueryBuilder::for(District::class)
            ->allowedFilters(
                AllowedFilter::custom('search', new FuzzyFilter(), 'nama'),
                AllowedFilter::exact('city_id')
            )
            ->allowedSorts('id', 'nama', 'city_id')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getVillages()
    {
        $this->mergeSearchFilter();
        return QueryBuilder::for(Village::class)
            ->allowedFilters(
                AllowedFilter::custom('search', new FuzzyFilter(), 'nama'),
                AllowedFilter::exact('district_id')
            )
            ->allowedSorts('id', 'nama', 'district_id')
            ->defaultSort('id')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }
}
