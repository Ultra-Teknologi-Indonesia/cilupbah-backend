<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\DTO\OrderAuditReportResult;

final class OrderAuditReportResource extends JsonResource
{
    public function __construct(OrderAuditReportResult $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {

        $result = $this->resource;

        return [
            'summary' => [
                'marketplace_total' => $result->summary->marketplaceTotal,
                'wms_total' => $result->summary->wmsTotal,
                'matched_total' => $result->summary->matchedTotal,
                'missing_total' => $result->summary->missingTotal,
                'status_mismatch_total' => $result->summary->statusMismatchTotal,
                'last_sync_at' => $result->summary->lastSyncAt,
                'last_checked_at' => $result->summary->lastCheckedAt,
            ],
            'items' => OrderAuditReportRowResource::collection(
                $result->paginator->getCollection(),
            )->resolve($request),
        ];
    }
}
