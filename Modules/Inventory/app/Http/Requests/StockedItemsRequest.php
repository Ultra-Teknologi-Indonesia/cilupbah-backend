<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockedItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|string',
            'search' => 'nullable|string',
            'per_page' => 'nullable',
            'include_zero' => 'nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'Parameter location_id wajib.',
        ];
    }

    public function locationId(): string
    {
        return (string) $this->query('location_id');
    }

    public function searchTerm(): string
    {
        return trim((string) $this->query('search', ''));
    }

    public function perPage(): int
    {
        $perPage = (int) $this->query('per_page', 20);

        if ($perPage <= 0 || $perPage > 200) {
            return 20;
        }

        return $perPage;
    }

    public function includeZero(): bool
    {
        return $this->boolean('include_zero');
    }
}
