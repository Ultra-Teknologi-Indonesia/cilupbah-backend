<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\OrderCutoverLookupService;
use RuntimeException;

final class OrderAuditController extends Controller
{
    public function lookup(Request $request, OrderCutoverLookupService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:128'],
        ]);

        return $this->successResponse(
            $service->lookup((string) $validated['reference']),
            'Audit pesanan berhasil diambil.',
        );
    }

    public function replay(Request $request, OrderCutoverLookupService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:128'],
            'confirmation' => ['required', 'in:REPLAY-ORDER'],
        ]);

        try {
            return $this->successResponse(
                $service->include((string) $validated['reference']),
                'Permintaan order diproses.',
            );
        } catch (RuntimeException $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                422,
                null,
                'Order belum dapat ditarik',
            );
        }
    }

    public function delete(Request $request, OrderCutoverLookupService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:128'],
            'confirmation' => ['required', 'in:DELETE-ORDER'],
        ]);

        try {
            return $this->successResponse(
                $service->delete((string) $validated['reference']),
                'Order berhasil dihapus.',
            );
        } catch (RuntimeException $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                422,
                null,
                'Order tidak dapat dihapus',
            );
        }
    }
}
