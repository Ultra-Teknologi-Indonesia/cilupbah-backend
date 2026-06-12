<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validasi postManualJournal (kontrak Jubelio saveManualJournalRequest — properti
 * sebenarnya; field 'required' di dokumen Jubelio salah tempel picklist).
 *
 * Aturan bisnis di lapisan validasi (→422, bukan 500):
 * - accounts min 2 baris; account_id bail|uuid|exists (non-uuid tidak menyentuh query cast).
 * - Per baris TEPAT SATU sisi terisi (debit>0 xor credit>0).
 * - Total seimbang: Σdebit = Σcredit (presisi 4 desimal).
 */
class SaveManualJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_id' => ['nullable'], // 0 = buat, uuid = ubah (diproses service)
            'notes' => ['nullable', 'string', 'max:1000'],
            'source_doc_no' => ['nullable', 'string', 'max:100'],
            'transaction_date' => ['nullable', 'date'],
            'accounts' => ['required', 'array', 'min:2'],
            'accounts.*.account_id' => ['required', 'bail', 'uuid', 'exists:accounts,id'],
            'accounts.*.debit' => ['required', 'numeric', 'min:0'],
            'accounts.*.credit' => ['required', 'numeric', 'min:0'],
            'accounts.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $lines = $this->input('accounts', []);
            if (! is_array($lines) || $v->errors()->hasAny(['accounts', 'accounts.*.debit', 'accounts.*.credit'])) {
                return;
            }

            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($lines as $i => $line) {
                $debit = round((float) ($line['debit'] ?? 0), 4);
                $credit = round((float) ($line['credit'] ?? 0), 4);

                if (($debit > 0) === ($credit > 0)) {
                    $v->errors()->add(
                        "accounts.{$i}",
                        'Tiap baris harus mengisi tepat satu sisi: debit ATAU kredit (> 0), tidak keduanya/kosong keduanya.'
                    );
                }

                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            if (round($totalDebit, 4) !== round($totalCredit, 4)) {
                $v->errors()->add(
                    'accounts',
                    sprintf('Jurnal tidak seimbang: total debit %.4f ≠ total kredit %.4f.', $totalDebit, $totalCredit)
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'accounts.min' => 'Jurnal minimal terdiri dari 2 baris (debit dan kredit).',
            'accounts.*.account_id.uuid' => 'account_id harus berupa UUID akun yang valid.',
            'accounts.*.account_id.exists' => 'Akun tidak ditemukan di Chart of Accounts.',
        ];
    }
}
