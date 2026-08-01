<?php

namespace Modules\Report\Http\Requests;

class PutawayListPdfRequest extends PutawayListLookupRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'putaway_ids' => ['nullable', 'array'],
            'putaway_ids.*' => ['uuid'],
            'download' => ['nullable', 'boolean'],
        ]);
    }
}
