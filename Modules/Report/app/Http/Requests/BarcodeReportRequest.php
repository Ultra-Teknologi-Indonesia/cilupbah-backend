<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class BarcodeReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis'     => ['required', 'in:sku,sku_induk'],
            'ids'       => ['required', 'array', 'min:1'],
            'ids.*'     => ['required', 'uuid'],
            'harga'     => ['required', 'in:tanpa_harga,default,online'],
            'download'  => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = $this->input('ids', []);
            if (! is_array($ids) || empty($ids)) {
                return;
            }

            $table = $this->input('jenis') === 'sku_induk' ? 'products' : 'product_variants';
            $found = DB::table($table)->whereIn('id', $ids)->count();

            if ($found !== count($ids)) {
                $validator->errors()->add('ids', "Sebagian ID tidak ditemukan pada tabel {$table}.");
            }
        });
    }
}
