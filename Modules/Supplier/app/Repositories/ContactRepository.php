<?php

namespace Modules\Supplier\Repositories;

use Modules\Supplier\Models\Contact;
use Modules\Supplier\Models\ContactCategory;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class ContactRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Contact::class)
            ->with('category:id,name')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('category_id'),
                AllowedFilter::exact('is_system'),
                AllowedFilter::custom('search', new FuzzyFilter('name,company_name,code,email'))
            )
            ->allowedSorts('name', 'code', 'type', 'created_at')
            ->defaultSort('name')
            ->paginate($limit);
    }

    public function findById(string $id): ?Contact
    {
        return Contact::with('category')->find($id);
    }

    public function create(array $data): Contact
    {
        return Contact::create($data);
    }

    public function update(Contact $contact, array $data): Contact
    {
        $contact->update($data);
        return $contact->fresh(['category']);
    }

    public function delete(Contact $contact): bool
    {
        return $contact->delete();
    }

    public function getCustomers(int $limit = 10)
    {
        return QueryBuilder::for(Contact::class)
            ->customers()
            ->with('category:id,name')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::custom('search', new FuzzyFilter('name,company_name,code,email'))
            )
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('name')
            ->paginate($limit);
    }

    public function getSuppliers(int $limit = 10)
    {
        return QueryBuilder::for(Contact::class)
            ->suppliers()
            ->with('category:id,name')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::custom('search', new FuzzyFilter('name,company_name,code,email'))
            )
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('name')
            ->paginate($limit);
    }

    public function getCustomersAndSuppliers(int $limit = 10)
    {
        return QueryBuilder::for(Contact::class)
            ->customersAndSuppliers()
            ->with('category:id,name')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::custom('search', new FuzzyFilter('name,company_name,code,email'))
            )
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('name')
            ->paginate($limit);
    }

    public function getAllCategories()
    {
        return ContactCategory::orderBy('name')->get();
    }
}
