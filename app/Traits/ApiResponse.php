<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    public function successResponse($data, ?string $message = null, int $code = 200, ?array $meta = null, ?string $title = null): JsonResponse
    {
        $response = [
            'status' => 'success',
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }

    public function successPaginatedResponse($paginator, ?string $message = null, int $code = 200, ?string $title = null): JsonResponse
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];

        return $this->successResponse($paginator->items(), $message, $code, $meta, $title);
    }

    public function errorResponse(string $message, int $code = 400, $errors = null, ?string $title = null, ?string $errorCode = null): JsonResponse
    {
        $response = [
            'status' => 'error',
            'title' => $title,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if ($errorCode !== null) {
            $response['code'] = $errorCode;
        }

        return response()->json($response, $code);
    }
}
