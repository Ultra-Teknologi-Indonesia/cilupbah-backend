<?php

namespace Modules\Warehouse\Http\Requests;

use App\Traits\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PrintQrLocationBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bin_ids' => 'nullable|string',
            'paper' => 'nullable|string|in:thermal_50x40,thermal_80x40,a4_single,a4_multi',
        ];
    }

    public function paper(string $default): string
    {
        return $this->validated()['paper'] ?? $default;
    }

    public function binIds(): ?array
    {
        $raw = $this->validated()['bin_ids'] ?? null;
        if (empty($raw)) {
            return null;
        }

        $ids = collect(explode(',', (string) $raw))
            ->map(fn ($id) => trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($ids as $id) {
            if (! preg_match('/^[0-9a-f\-]{32,36}$/i', $id)) {
                $responder = new class { use ApiResponse; };
                throw new HttpResponseException(
                    $responder->errorResponse("ID bin tidak valid: {$id}", 422)
                );
            }
        }

        return $ids ?: null;
    }

    public function binIdsRaw(): ?array
    {
        $raw = $this->validated()['bin_ids'] ?? null;
        if (empty($raw)) {
            return null;
        }

        return array_map('trim', explode(',', (string) $raw));
    }
}
