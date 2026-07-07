<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCourierPickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi = izin edit/proses pesanan (via middleware auth pada grup route).
        // Tidak ada permission khusus untuk bukti pickup.
        return true;
    }

    public function rules(): array
    {
        // Telepon = teks bebas (angka/+/-/spasi), TIDAK dipaksa E.164.
        return [
            'courier_name'  => ['nullable', 'string', 'max:255'],
            'courier_phone' => ['nullable', 'string', 'max:32'],
            'pickup_code'   => ['nullable', 'string', 'max:64'],
        ];
    }
}
