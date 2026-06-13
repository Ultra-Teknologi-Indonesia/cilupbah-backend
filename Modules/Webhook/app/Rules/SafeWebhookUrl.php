<?php

namespace Modules\Webhook\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Webhook\Support\WebhookUrlGuard;

class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! (new WebhookUrlGuard())->isSafe($value)) {
            $fail('URL webhook harus memakai skema yang diizinkan (https) dan tidak boleh menunjuk ke alamat internal/privat.');
        }
    }
}
