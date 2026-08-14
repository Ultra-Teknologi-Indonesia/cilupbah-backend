<?php

namespace Modules\Channel\Tests\Feature;

use Tests\TestCase;

class LimitWebhookPayloadSizeTest extends TestCase
{
    public function test_webhook_rejects_payload_larger_than_1mb(): void
    {
        $largeBody = str_repeat('A', 1048576 + 100);

        $response = $this->call(
            'POST',
            '/api/v1/tiktok/webhook',
            [],
            [],
            [],
            [
                'CONTENT_LENGTH' => strlen($largeBody),
                'CONTENT_TYPE' => 'application/json',
            ],
            $largeBody
        );

        $response->assertStatus(413)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Payload terlalu besar (maksimal 1MB).');
    }
}
