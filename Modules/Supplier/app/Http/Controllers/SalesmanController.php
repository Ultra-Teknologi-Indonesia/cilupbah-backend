<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Supplier\Services\SalesmanService;

class SalesmanController extends Controller
{
    public function __construct(
        protected SalesmanService $salesmanService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 15);
        $data = $this->salesmanService->getAllPaginated($limit);
        return $this->successPaginatedResponse($data, 'success');
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'nullable|string|max:20|unique:salesmen,code',
                'phone' => 'nullable|string|max:30',
                'email' => 'nullable|email|max:255',
                'status' => 'nullable|string|in:active,inactive',
                'notes' => 'nullable|string',
            ]);
            $salesman = $this->salesmanService->create($request->all());
            return $this->successResponse($salesman, 'Salesman berhasil ditambahkan');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function show(string $id): JsonResponse
    {
        $salesman = $this->salesmanService->getById($id);
        if (! $salesman) {
            return $this->errorResponse('Salesman tidak ditemukan.', 404);
        }
        return $this->successResponse($salesman, 'success');
    }

    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'nullable|string|max:255',
                'code' => 'nullable|string|max:20|unique:salesmen,code,' . $id,
                'phone' => 'nullable|string|max:30',
                'email' => 'nullable|email|max:255',
                'status' => 'nullable|string|in:active,inactive',
                'notes' => 'nullable|string',
            ]);
            $salesman = $this->salesmanService->update($id, $request->all());
            return $this->successResponse($salesman, 'Salesman berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $request->validate(['id' => 'required|string']);
            $this->salesmanService->delete($request->input('id'));
            return $this->successResponse(null, 'Salesman berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function all(): JsonResponse
    {
        $data = $this->salesmanService->getAll();
        return $this->successResponse($data, 'success');
    }
}
