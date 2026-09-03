<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use InvalidArgumentException;

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

    public function successPaginatedResponse(mixed $paginator, ?string $message = null, int $code = 200, ?string $title = null): JsonResponse
    {
        [$paginator, $data] = $this->resolvePaginatedPayload($paginator);

        return $this->successResponse(
            $data,
            $message,
            $code,
            $this->paginationMeta($paginator),
            $title,
        );
    }

    public function successCursorPaginatedResponse(mixed $paginator, ?string $message = null, int $code = 200, ?string $title = null): JsonResponse
    {
        [$paginator, $data] = $this->resolvePaginatedPayload($paginator);

        if (! $paginator instanceof AbstractCursorPaginator) {
            throw new InvalidArgumentException('Cursor pagination requires a cursor paginator.');
        }

        return $this->successResponse(
            $data,
            $message,
            $code,
            $this->paginationMeta($paginator),
            $title,
        );
    }

    public function errorResponse(string $message, int $code = 400, $errors = null, ?string $title = null, ?string $errorCode = null, ?string $requestId = null): JsonResponse
    {
        $response = [
            'status' => 'error',
            'title' => $title,
            'message' => $message,
            'errors' => $errors,
            'code' => $errorCode,
            'request_id' => $requestId,
        ];

        return response()->json($response, $code);
    }

    private function resolvePaginatedPayload(mixed $value): array
    {
        $resourceCollection = $value instanceof ResourceCollection ? $value : null;
        $paginator = $resourceCollection?->resource ?? $value;

        if (! $paginator instanceof AbstractPaginator && ! $paginator instanceof AbstractCursorPaginator) {
            throw new InvalidArgumentException('A paginated response requires a Laravel paginator.');
        }

        $data = $resourceCollection !== null
            ? $resourceCollection->toArray(request())
            : $paginator->items();

        return [$paginator, array_values($data)];
    }

    private function paginationMeta(AbstractPaginator|AbstractCursorPaginator $paginator): array
    {
        if ($paginator instanceof AbstractCursorPaginator) {
            return [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ];
        }

        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
