<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\TrackingItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackingController extends Controller
{
    public function index()
    {
        return view('dev.tracking');
    }

    /** Data tabel + ringkasan (JSON). */
    public function data(Request $request)
    {
        $items = $this->filtered($request)
            ->orderByRaw("CASE source WHEN 'jubelio' THEN 0 WHEN 'epic' THEN 1 ELSE 2 END")
            ->orderBy('domain')
            ->orderBy('id')
            ->get();

        return response()->json([
            'items' => $items,
            'summary' => $this->summary(),
            'meta' => [
                'domains' => TrackingItem::query()->distinct()->orderBy('domain')->pluck('domain'),
                'pics' => TrackingItem::query()->whereNotNull('pic')->distinct()->orderBy('pic')->pluck('pic'),
                'statuses' => TrackingItem::STATUSES,
                'sources' => ['jubelio', 'epic', 'omnichannel'],
            ],
        ]);
    }

    /** Update status / notes / pic satu item. */
    public function update(Request $request, TrackingItem $item)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', TrackingItem::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'pic' => ['sometimes', 'nullable', 'in:'.implode(',', TrackingItem::PICS)],
            'updated_by' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $item->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'item' => $item->fresh(),
            'summary' => $this->summary(),
        ]);
    }

    /** Export snapshot CSV / Markdown. */
    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format', 'csv');
        $items = $this->filtered($request)->orderBy('domain')->orderBy('id')->get();
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

    private function filtered(Request $request)
    {
        return TrackingItem::query()
            ->when($request->filled('domain'), fn ($q) => $q->where('domain', $request->domain))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('pic'), fn ($q) => $q->where('pic', $request->pic))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->q.'%';
                $q->where(fn ($w) => $w->where('endpoint', 'ilike', $term)->orWhere('function_id', 'ilike', $term));
            });
    }

    private function summary(): array
    {
        $base = fn () => TrackingItem::query();
        $byStatus = fn ($q) => [
            'total' => (clone $q)->count(),
            'done' => (clone $q)->where('status', 'done')->count(),
            'in_progress' => (clone $q)->where('status', 'in_progress')->count(),
            'todo' => (clone $q)->where('status', 'todo')->count(),
            'blocked' => (clone $q)->where('status', 'blocked')->count(),
        ];

        $perDomain = [];
        foreach (TrackingItem::query()->distinct()->orderBy('domain')->pluck('domain') as $dom) {
            $perDomain[$dom] = $byStatus($base()->where('domain', $dom));
        }

        $perPic = [];
        foreach (['Darriel', 'Rasyid'] as $pic) {
            $perPic[$pic] = $byStatus($base()->where('pic', $pic));
        }

        return [
            'overall' => $byStatus($base()),
            'per_domain' => $perDomain,
            'per_pic' => $perPic,
        ];
    }
}
