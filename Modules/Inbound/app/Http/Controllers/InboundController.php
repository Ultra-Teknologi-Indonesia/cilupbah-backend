<?php

namespace Modules\Inbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inbound\Services\InboundService;
use Modules\Inbound\Http\Requests\StoreInboundRequest;
use Modules\Inbound\Http\Requests\ReceiveInboundRequest;

class InboundController extends Controller
{
    public function __construct(
        protected InboundService $inboundService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $inbounds = $this->inboundService->getAllPaginated($limit);

        return $this->successPaginatedResponse($inbounds, 'Daftar inbound berhasil diambil');
    }

    public function show(int $id): JsonResponse
    {
        $inbound = $this->inboundService->getById($id);

        if (!$inbound) {
            return $this->errorResponse('Dokumen Inbound tidak ditemukan', 404);
        }

        return $this->successResponse($inbound, 'Detail Inbound berhasil diambil');
    }

    public function store(StoreInboundRequest $request): JsonResponse
    {
        try {
            $inbound = $this->inboundService->createDraft($request->validated());
            return $this->successResponse($inbound, 'Draft Inbound berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function receive(int $id, ReceiveInboundRequest $request): JsonResponse
    {
        try {
            $inbound = $this->inboundService->receive($id, $request->validated());
            return $this->successResponse($inbound, 'Penerimaan Inbound berhasil diproses');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function receivedItems(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $items = $this->inboundService->getReceivedItemsPaginated($limit);

        return $this->successPaginatedResponse($items, 'Daftar barang diterima berhasil diambil');
    }

    public function autoPutaway(\Modules\Inbound\Http\Requests\AutoPutawayRequest $request): JsonResponse
    {
        try {
            $results = $this->inboundService->autoPutaway($request->validated());
            return $this->successResponse($results, 'Auto-putaway berhasil dieksekusi', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
