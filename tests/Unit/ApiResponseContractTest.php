<?php

namespace Tests\Unit;

use App\Traits\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class ApiResponseContractTest extends TestCase
{
    public function test_length_aware_pagination_is_flattened_into_data_and_meta(): void
    {
        $responder = new class
        {
            use ApiResponse;
        };

        $response = $responder->successPaginatedResponse(
            new LengthAwarePaginator([['id' => 1]], 1, 20, 1),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'status',
            'title',
            'message',
            'data',
            'meta',
        ], array_keys($response->getData(true)));
        $this->assertSame([['id' => 1]], $response->getData(true)['data']);
        $this->assertSame(1, $response->getData(true)['meta']['total']);
    }
}
