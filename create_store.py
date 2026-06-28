import re

with open('Modules/Inventory/app/Http/Controllers/PutawayController.php', 'r') as f:
    content = f.read()

store_method = """    #[OA\Post(
        path: '/api/v1/putaway',
        summary: 'Create a new putaway manually from an inbound document',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['inbound_id'],
                properties: [
                    new OA\Property(property: 'inbound_id', type: 'string'),
                    new OA\Property(property: 'assigned_to', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Putaway created successfully'),
            new OA\Response(response: 400, description: 'Bad request')
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'inbound_id' => 'required|string|exists:inbounds,id',
            'assigned_to' => 'nullable|string|exists:users,id',
        ]);

        try {
            $inbound = \Modules\Inbound\Models\Inbound::with('items')->findOrFail($request->inbound_id);
            $defaultBin = app(\Modules\Warehouse\Services\LocationBinService::class)->getDefaultBin($inbound->location_id);
            $userId = $request->user()->id ?? 'system';
            
            $items = $inbound->items
                ->filter(fn ($item) => $item->received_qty > 0)
                ->map(fn ($item) => [
                    'item_id'            => $item->item_id,
                    'source_bin_id'      => $defaultBin ? $defaultBin->id : null,
                    'destination_bin_id' => null,
                    'qty'                => $item->received_qty,
                    'batch_no'           => null,
                    'serial_no'          => null,
                ])
                ->values()
                ->toArray();

            if (empty($items)) {
                return $this->errorResponse('Tidak ada item untuk di-putaway.', 400);
            }

            $putaway = $this->putawayService->create([
                'location_id' => $inbound->location_id,
                'source_type' => 'INBOUND',
                'source_id'   => $inbound->id,
                'notes'       => "Manual Putaway from Inbound {$inbound->transaction_number}",
                'created_by'  => $userId,
                'items'       => $items,
            ]);
            
            if ($request->assigned_to) {
                app(\Modules\Inventory\Services\PutawayService::class)->assignStaff(
                    $putaway->id, 
                    $request->assigned_to, 
                    $userId
                );
            }

            return $this->successResponse($putaway, 'Penempatan barang berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

"""

content = content.replace(
    'public function index(Request $request): JsonResponse',
    store_method + '    public function index(Request $request): JsonResponse'
)

with open('Modules/Inventory/app/Http/Controllers/PutawayController.php', 'w') as f:
    f.write(content)

