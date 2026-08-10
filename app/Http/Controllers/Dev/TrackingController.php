<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\TrackingItem;
use App\Services\TrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackingController extends Controller
{
    public function __construct(private TrackingService $tracking) {}

    private const FILTER_KEYS = ['domain', 'status', 'pic', 'source', 'q'];

    public function index()
    {
        return view('dev.tracking');
    }

    public function data(Request $request)
    {
        return $this->successResponse([
            'items' => $this->tracking->list($request->only(self::FILTER_KEYS)),
            'summary' => $this->tracking->summary(),
        ], null, 200, $this->tracking->filterOptions());
    }

    public function update(Request $request, TrackingItem $item)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', TrackingItem::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'pic' => ['sometimes', 'nullable', 'in:'.implode(',', TrackingItem::PICS)],
            'updated_by' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        return $this->successResponse([
            'ok' => true,
            'item' => $this->tracking->update($item, $validated),
            'summary' => $this->tracking->summary(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format', 'csv');
        $items = $this->tracking->exportList($request->only(self::FILTER_KEYS));
        $stamp = now()->format('Ymd_His');

        if ($format === 'md') {
            $md = "# Snapshot Dev Tracker — {$stamp}\n\n| Domain | Method | Endpoint | Fungsi | Status | PIC | Notes |\n|---|---|---|---|---|---|---|\n";
            foreach ($items as $i) {
                $notes = str_replace(["\n", '|'], [' ', '\\|'], (string) $i->notes);
                $md .= "| {$i->domain} | {$i->method} | {$i->endpoint} | {$i->function_id} | {$i->status} | {$i->pic} | {$notes} |\n";
            }

            return Response::streamDownload(fn () => print($md), "dev-tracker-{$stamp}.md", ['Content-Type' => 'text/markdown']);
        }

        return Response::streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['domain', 'method', 'endpoint', 'function_id', 'status', 'pic', 'priority', 'source', 'notes', 'updated_by', 'updated_at']);
            foreach ($items as $i) {
                fputcsv($out, [$i->domain, $i->method, $i->endpoint, $i->function_id, $i->status, $i->pic, $i->priority, $i->source, $i->notes, $i->updated_by, $i->updated_at]);
            }
            fclose($out);
        }, "dev-tracker-{$stamp}.csv", ['Content-Type' => 'text/csv']);
    }

}
