<?php

namespace Modules\Supplier\Repositories;

use Modules\Supplier\Models\Contact;
use Modules\Supplier\Models\ContactCategory;
use Illuminate\Support\Collection;

class ContactImportRepository
{

    public function allCategories(): Collection
    {
        return ContactCategory::all();
    }

    public function existingContactNames(): Collection
    {
        return Contact::pluck('name');
    }

    public function createContact(array $data): Contact
    {
        return Contact::create($data);
    }

    public function categoryNamesOrdered(): array
    {
        return ContactCategory::orderBy('name')->pluck('name')->all();
    }

    public function firstCategoryName(): ?string
    {
        return ContactCategory::orderBy('name')->value('name');
    }
}
