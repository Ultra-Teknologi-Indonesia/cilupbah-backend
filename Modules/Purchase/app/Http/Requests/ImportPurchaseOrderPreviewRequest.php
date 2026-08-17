<?php

namespace Modules\Purchase\Http\Requests;

use App\Traits\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ImportPurchaseOrderPreviewRequest extends FormRequest
{
    use ApiResponse;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt',
                'max:10240', 
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File import wajib diunggah.',
            'file.file'     => 'Berkas yang diunggah harus berupa file yang valid.',
            'file.mimes'    => 'Format file tidak didukung. Harap unggah file berekstensi .xlsx, .xls, atau .csv.',
            'file.max'      => 'Ukuran file terlalu besar. Maksimal ukuran file adalah 10 MB.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            $this->errorResponse(
                $validator->errors()->first(),
                422,
                $validator->errors()->toArray(),
                'Validasi Gagal'
            )
        );
    }
}
