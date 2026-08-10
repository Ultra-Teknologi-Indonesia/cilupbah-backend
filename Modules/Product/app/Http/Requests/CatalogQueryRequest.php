<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => 'nullable|in:all,merged,unmerged,hidden',
            'q' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:500',
        ];
    }

    public function filter(): string
    {
        return (string) ($this->validated()['filter'] ?? 'all');
    }

    public function search(): string
    {
        return (string) ($this->validated()['q'] ?? '');
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated()['page'] ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['limit'] ?? 50);
    }
}
