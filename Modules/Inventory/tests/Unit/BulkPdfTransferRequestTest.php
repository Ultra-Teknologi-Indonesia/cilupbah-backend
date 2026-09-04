<?php

namespace Modules\Inventory\Tests\Unit;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Http\Requests\BulkPdfTransferAsyncRequest;
use Modules\Inventory\Http\Requests\BulkPdfTransferRequest;
use Tests\TestCase;

class BulkPdfTransferRequestTest extends TestCase
{
    public function test_encoded_comma_ids_are_normalized_before_validation(): void
    {
        $request = BulkPdfTransferRequest::create('/', 'POST', [
            'ids' => [
                '01a037c9-1992-731e-852b-27d918d16441%2C01a037b6-e606-71e0-9666-827196a8e381',
            ],
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);

        $request->validateResolved();

        $this->assertSame([
            '01a037c9-1992-731e-852b-27d918d16441',
            '01a037b6-e606-71e0-9666-827196a8e381',
        ], $request->validated('ids'));
    }

    public function test_invalid_normalized_id_is_rejected(): void
    {
        $request = BulkPdfTransferRequest::create('/', 'POST', [
            'ids' => ['not-a-uuid'],
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);

        $this->expectException(ValidationException::class);

        $request->validateResolved();
    }

    public function test_async_request_accepts_more_than_fifty_ids(): void
    {
        $request = BulkPdfTransferAsyncRequest::create('/', 'POST', [
            'ids' => array_map(static fn (): string => (string) Str::uuid(), range(1, 59)),
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);

        $request->validateResolved();

        $this->assertCount(59, $request->validated('ids'));
    }
}
