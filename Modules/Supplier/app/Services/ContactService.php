<?php

namespace Modules\Supplier\Services;

use Modules\Supplier\Repositories\ContactRepository;
use Modules\Supplier\Models\Contact;
use Illuminate\Support\Str;

class ContactService
{
    public function __construct(
        protected ContactRepository $contactRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->contactRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?Contact
    {
        return $this->contactRepository->findById($id);
    }

    public function create(array $data): Contact
    {
        if (empty($data['code'])) {
            $prefix = match ($data['type'] ?? Contact::TYPE_CUSTOMER) {
                Contact::TYPE_SUPPLIER => 'SUP',
                Contact::TYPE_BOTH     => 'CS',
                default                => 'CUST',
            };
            $data['code'] = $prefix . '-' . Str::upper(Str::random(6));
        }

        return $this->contactRepository->create($data);
    }

    public function update(string $id, array $data): Contact
    {
        $contact = $this->contactRepository->findById($id);
        if (! $contact) {
            throw new \Exception('Contact tidak ditemukan.');
        }
        return $this->contactRepository->update($contact, $data);
    }

    public function delete(string $id): bool
    {
        $contact = $this->contactRepository->findById($id);
        if (! $contact) {
            throw new \Exception('Contact tidak ditemukan.');
        }
        return $this->contactRepository->delete($contact);
    }

    public function getCustomers(int $limit = 10)
    {
        return $this->contactRepository->getCustomers($limit);
    }

    public function getSuppliers(int $limit = 10)
    {
        return $this->contactRepository->getSuppliers($limit);
    }

    public function getCustomersAndSuppliers(int $limit = 10)
    {
        return $this->contactRepository->getCustomersAndSuppliers($limit);
    }

    public function getCategories()
    {
        return $this->contactRepository->getAllCategories();
    }
}
