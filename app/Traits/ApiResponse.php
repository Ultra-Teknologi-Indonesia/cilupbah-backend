<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function successResponse($data, ?string $message = null, int $code = 200, ?array $meta = null): JsonResponse
    {
        $response = [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }

    protected function successPaginatedResponse($paginator, ?string $message = null, int $code = 200): JsonResponse
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];

        return $this->successResponse($paginator->items(), $message, $code, $meta);
    }

    protected function errorResponse(string $message, int $code = 400, $errors = null): JsonResponse
    {
        $response = [
            'status' => 'error',
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }
}
