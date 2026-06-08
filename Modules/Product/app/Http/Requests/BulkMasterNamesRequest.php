<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dipakai oleh bulk-unmerge, hide, dan unhide.
 */
class BulkMasterNamesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'master_names' => 'required|array|min:1',
            'master_names.*' => 'required|string',
        ];
    }
}
