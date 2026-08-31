<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

final class DatabaseAvailabilityResponseTest extends TestCase
{
    public function test_api_database_capacity_failure_returns_safe_503_contract(): void
    {
        Route::get('/api/test-database-capacity', static function (): never {
            throw new PDOException('SQLSTATE[53300]: remaining connection slots are reserved');
        });

        $response = $this->getJson('/api/test-database-capacity');

        $response
            ->assertStatus(503)
            ->assertHeader('Retry-After', '10')
            ->assertJson([
                'status' => 'error',
                'title' => 'Layanan sementara tidak tersedia',
                'message' => 'Server sedang padat. Silakan coba lagi beberapa saat.',
                'code' => 'DATABASE_CAPACITY_TEMPORARILY_UNAVAILABLE',
            ]);

        self::assertArrayNotHasKey('errors', $response->json());
    }

    public function test_api_http_503_failure_returns_safe_user_facing_message(): void
    {
        Route::get('/api/test-service-unavailable', static function (): never {
            abort(503, 'upstream technical detail');
        });

        $response = $this->getJson('/api/test-service-unavailable');

        $response
            ->assertStatus(503)
            ->assertHeader('Retry-After', '10')
            ->assertJson([
                'status' => 'error',
                'title' => 'Layanan sementara tidak tersedia',
                'message' => 'Layanan sedang tidak tersedia. Silakan coba lagi beberapa saat.',
                'code' => 'SERVICE_TEMPORARILY_UNAVAILABLE',
            ]);
    }
}
