<?php

namespace Modules\IssueTracker\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\IssueTracker\Http\Requests\StoreIssueRequest;
use Modules\IssueTracker\Models\Issue;
use Modules\IssueTracker\Models\IssueCategory;
use Modules\IssueTracker\Models\IssueComment;
use Modules\IssueTracker\Services\IssueService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class IssueController extends Controller
{
    public function __construct(protected IssueService $service) {}

    public function index(Request $request)
    {
        $stats = [
            'total' => Issue::count(),
            'open' => Issue::where('status', 'open')->count(),
            'in_review' => Issue::where('status', 'in_review')->count(),
            'in_progress' => Issue::where('status', 'in_progress')->count(),
            'resolved' => Issue::where('status', 'resolved')->count(),
            'closed' => Issue::where('status', 'closed')->count(),
        ];

        $query = Issue::with('category')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('reporter_name', 'like', "%{$search}%")
                  ->orWhere('tracking_token', $search);
            });
        }

        $issues = $query->paginate(20)->withQueryString();

        return view('issue-tracker::index', compact('stats', 'issues'));
    }

    public function create()
    {
        $categories = IssueCategory::active()->orderBy('sort_order')->get();

        return view('issue-tracker::create', compact('categories'));
    }

    public function store(StoreIssueRequest $request)
    {
        $issue = $this->service->createIssue(
            $request->validated(),
            $request->file('attachments', [])
        );

        return redirect()
            ->route('issues.show', $issue)
            ->with('success', 'Issue berhasil dilaporkan! Simpan link ini untuk tracking.');
    }

    public function track(string $token)
    {
        $issue = Issue::where('tracking_token', $token)->firstOrFail();

        return redirect()->route('issues.show', $issue);
    }

    public function show(Issue $issue)
    {
        $issue->load(['category', 'comments.media', 'media', 'activities']);

        return view('issue-tracker::show', compact('issue'));
    }

    public function updateStatus(Request $request, Issue $issue)
    {
        $request->validate([
            'status' => ['required', 'in:open,in_review,in_progress,resolved,closed'],
            'actor_name' => ['required', 'string', 'max:100'],
        ]);

        $this->service->updateStatus($issue, $request->input('status'), $request->input('actor_name'));

        return back()->with('success', 'Status berhasil diubah.');
    }

    public function assign(Request $request, Issue $issue)
    {
        $request->validate([
            'assigned_to' => ['required', 'string', 'max:100'],
            'actor_name' => ['required', 'string', 'max:100'],
        ]);

        $this->service->assignTo($issue, $request->input('assigned_to'), $request->input('actor_name'));

        return back()->with('success', 'Issue berhasil di-assign.');
    }

    public function comment(Request $request, Issue $issue)
    {
        $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'author_name' => ['required', 'string', 'max:100'],
            'is_internal' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm,pdf'],
        ]);

        $this->service->addComment($issue, [
            'body' => $request->input('body'),
            'author_type' => $request->input('is_internal') ? 'internal' : 'client',
            'author_name' => $request->input('author_name'),
            'is_internal' => (bool) $request->input('is_internal', false),
        ], $request->file('attachments', []));

        return back()->with('success', 'Komentar berhasil ditambahkan.');
    }

    public function destroy(Request $request, Issue $issue)
    {
        $request->validate([
            'actor_name' => ['nullable', 'string', 'max:100'],
        ]);

        $this->service->deleteIssue($issue, $request->input('actor_name', 'Sistem'));

        return redirect()->route('issues.index')->with('success', 'Issue berhasil dihapus.');
    }

    public function attachment(Media $media)
    {
        if (!in_array($media->model_type, [Issue::class, IssueComment::class], true)) {
            abort(404);
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        if (!$disk->exists($path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return $disk->response(
            $path,
            $media->file_name,
            ['Content-Type' => $media->mime_type ?: 'application/octet-stream'],
            'inline'
        );
    }

    public function export(Request $request)
    {
        $query = Issue::with('category')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $issues = $query->get();

        $csv = "ID,Token,Judul,Platform,Kategori,Prioritas,Status,Pelapor,Kontak,Assigned,Dibuat\n";
        foreach ($issues as $issue) {
            $csv .= implode(',', [
                $issue->id,
                $issue->tracking_token,
                '"' . str_replace('"', '""', $issue->title) . '"',
                $issue->platform,
                $issue->category?->name ?? '-',
                $issue->priority,
                $issue->status,
                '"' . str_replace('"', '""', $issue->reporter_name) . '"',
                $issue->reporter_contact ?? '-',
                $issue->assigned_to ?? '-',
                $issue->created_at->format('Y-m-d H:i'),
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="issues-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
