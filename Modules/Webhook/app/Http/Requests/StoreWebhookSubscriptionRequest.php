<?php

namespace Modules\Webhook\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Webhook\Support\WebhookEvent;

class StoreWebhookSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'event' => "$required|in:".implode(',', WebhookEvent::subscriptionValues()),
            'target_url' => "$required|url|max:2048",
            'secret' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ];
    }
}
