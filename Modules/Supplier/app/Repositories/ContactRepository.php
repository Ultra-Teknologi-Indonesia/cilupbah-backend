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
            ->with(['category:id,name,code', 'salesman:id,name,code'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::callback('type', function ($query, $value) {
                    $val = strtoupper((string) $value);
                    if ($val === Contact::TYPE_CUSTOMER) {
                        $query->whereIn('type', [Contact::TYPE_CUSTOMER, Contact::TYPE_BOTH]);
                    } elseif ($val === Contact::TYPE_SUPPLIER) {
                        $query->whereIn('type', [Contact::TYPE_SUPPLIER, Contact::TYPE_BOTH]);
                    } elseif ($val === Contact::TYPE_BOTH) {
                        $query->where('type', Contact::TYPE_BOTH);
                    }
                }),
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
        return Contact::with(['category', 'salesman'])->find($id);
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
            ->with(['category:id,name,code', 'salesman:id,name,code'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('category_id'),
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

    public function findCategoryById(string $id): ?\Modules\Supplier\Models\ContactCategory
    {
        return ContactCategory::find($id);
    }

    public function createCategory(array $data): ContactCategory
    {
        return ContactCategory::create($data);
    }

    public function updateCategory(ContactCategory $category, array $data): ContactCategory
    {
        $category->update($data);
        return $category->fresh();
    }

    public function deleteCategory(ContactCategory $category): bool
    {
        return $category->delete();
    }
}
