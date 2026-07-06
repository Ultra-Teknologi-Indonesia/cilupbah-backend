@extends('issue-tracker::layouts.app')

@section('title', $issue->title)

@section('content')
<div class="mb-4">
    <a href="{{ route('issues.index') }}" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700">
        <svg class="mr-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        Kembali ke daftar
    </a>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    {{-- Main content --}}
    <div class="lg:col-span-2 space-y-5">
        {{-- Header --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h1 class="text-lg font-bold text-gray-900">{{ $issue->title }}</h1>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <span class="font-mono bg-gray-100 rounded px-1.5 py-0.5">{{ $issue->tracking_token }}</span>
                        <span>&middot;</span>
                        <span>{{ $issue->created_at->format('d M Y H:i') }}</span>
                        <span>&middot;</span>
                        <span>{{ $issue->reporter_name }}</span>
                        @if($issue->reporter_contact)
                            <span>&middot;</span>
                            <span>{{ $issue->reporter_contact }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @include('issue-tracker::components.priority-badge', ['priority' => $issue->priority])
                    @include('issue-tracker::components.status-badge', ['status' => $issue->status])
                </div>
            </div>

            <div class="prose prose-sm max-w-none text-gray-700">
                {!! nl2br(e($issue->description)) !!}
            </div>

            @if($issue->url)
                <div class="mt-3">
                    <a href="{{ $issue->url }}" target="_blank" rel="noopener" class="inline-flex items-center text-sm text-brand-600 hover:text-brand-800">
                        <svg class="mr-1 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101"/><path stroke-linecap="round" stroke-linejoin="round" d="M10.172 13.828a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        {{ $issue->url }}
                    </a>
                </div>
            @endif

            @if($issue->getMedia(\Modules\IssueTracker\Models\Issue::MEDIA_COLLECTION)->count())
                <div class="mt-4 border-t border-gray-100 pt-3">
                    <p class="mb-2 text-xs font-medium text-gray-500">Lampiran:</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($issue->getMedia(\Modules\IssueTracker\Models\Issue::MEDIA_COLLECTION) as $att)
                            <a href="{{ route('issues.attachments.show', $att) }}" target="_blank" rel="noopener"
                               class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100">
                                <svg class="mr-1 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                <span class="max-w-[220px] truncate">{{ $att->file_name }}</span>
                                <span class="ml-1.5 text-[10px] text-gray-400">{{ number_format($att->size / 1024, 0) }} KB</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Timeline / Comments --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-bold text-gray-800">Timeline & Komentar</h2>

            <div class="space-y-4">
                @foreach($issue->activities->sortBy('created_at') as $activity)
                    <div class="flex gap-3">
                        <div class="mt-1 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-gray-100">
                            <svg class="h-3 w-3 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="text-sm">
                            <span class="font-medium text-gray-700">{{ $activity->actor_name }}</span>
                            <span class="text-gray-500">
                                @if($activity->action === 'status_changed')
                                    mengubah status ke <span class="font-medium">{{ $activity->metadata['to'] ?? '' }}</span>
                                @elseif($activity->action === 'assigned')
                                    meng-assign ke <span class="font-medium">{{ $activity->metadata['to'] ?? '' }}</span>
                                @elseif($activity->action === 'created')
                                    membuat issue ini
                                @else
                                    {{ $activity->action }}
                                @endif
                            </span>
                            <span class="ml-2 text-xs text-gray-400">{{ $activity->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                @endforeach

                @foreach($issue->comments->sortBy('created_at') as $comment)
                    <div class="flex gap-3 {{ $comment->is_internal ? 'bg-amber-50/50 -mx-2 px-2 py-2 rounded-lg border border-amber-100' : '' }}">
                        <div class="mt-1 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full {{ $comment->author_type === 'internal' ? 'bg-brand-100' : 'bg-green-100' }}">
                            <svg class="h-3 w-3 {{ $comment->author_type === 'internal' ? 'text-brand-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-700">{{ $comment->author_name }}</span>
                                @if($comment->is_internal)
                                    <span class="rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">INTERNAL</span>
                                @endif
                                <span class="text-xs text-gray-400">{{ $comment->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="mt-1 text-sm text-gray-600 whitespace-pre-wrap">{{ $comment->body }}</div>
                            @if($comment->getMedia(\Modules\IssueTracker\Models\Issue::MEDIA_COLLECTION)->count())
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach($comment->getMedia(\Modules\IssueTracker\Models\Issue::MEDIA_COLLECTION) as $att)
                                        <a href="{{ route('issues.attachments.show', $att) }}" target="_blank" rel="noopener"
                                           class="inline-flex items-center rounded border border-gray-200 bg-gray-50 px-2 py-1 text-[11px] text-gray-500 hover:bg-gray-100">
                                            <span class="max-w-[180px] truncate">{{ $att->file_name }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if($issue->activities->isEmpty() && $issue->comments->isEmpty())
                <p class="text-center text-sm text-gray-400 py-4">Belum ada aktivitas.</p>
            @endif
        </div>

        {{-- Add Comment --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-bold text-gray-800">Tambah Komentar</h2>
            <form method="POST" action="{{ route('issues.comment', $issue) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <input type="text" name="author_name" required placeholder="Nama Anda"
                           class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" name="is_internal" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span>Internal note (tidak terlihat client)</span>
                    </label>
                </div>
                <textarea name="body" rows="3" required placeholder="Tulis komentar..."
                          class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"></textarea>
                <div class="flex items-center justify-between">
                    <label class="flex cursor-pointer items-center text-xs text-gray-500 hover:text-gray-700">
                        <svg class="mr-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                        Lampirkan file
                        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf" class="sr-only">
                    </label>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700">Kirim</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Sidebar actions --}}
    <div class="space-y-5">
        {{-- Info card --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Detail</h3>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Platform</dt>
                    <dd class="font-medium text-gray-800">{{ $issue->platform === 'website' ? 'Website' : 'Mobile App' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Kategori</dt>
                    <dd class="font-medium text-gray-800">{{ $issue->category?->name ?? '-' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Assigned</dt>
                    <dd class="font-medium text-gray-800">{{ $issue->assigned_to ?? 'Belum di-assign' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Dibuat</dt>
                    <dd class="text-gray-700">{{ $issue->created_at->format('d M Y H:i') }}</dd>
                </div>
                @if($issue->resolved_at)
                <div class="flex justify-between">
                    <dt class="text-gray-500">Resolved</dt>
                    <dd class="text-gray-700">{{ $issue->resolved_at->format('d M Y H:i') }}</dd>
                </div>
                @endif
            </dl>
        </div>

        {{-- Tracking link --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Link Tracking</h3>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ route('issues.track', $issue->tracking_token) }}"
                       class="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 font-mono">
                <button onclick="navigator.clipboard.writeText('{{ route('issues.track', $issue->tracking_token) }}')"
                        class="rounded-lg border border-gray-200 bg-white px-2.5 py-2 text-xs text-gray-500 hover:bg-gray-50" title="Copy">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </button>
            </div>
        </div>

        {{-- Update Status --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Ubah Status</h3>
            <form method="POST" action="{{ route('issues.status', $issue) }}" class="space-y-2.5">
                @csrf
                @method('PUT')
                <input type="text" name="actor_name" required placeholder="Nama Anda"
                       class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                <select name="status" required
                        class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                    @foreach(['open' => 'Open', 'in_review' => 'In Review', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $val => $label)
                        <option value="{{ $val }}" {{ $issue->status === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="w-full rounded-lg bg-gray-800 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-900">Update Status</button>
            </form>
        </div>

        {{-- Assign --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Assign</h3>
            <form method="POST" action="{{ route('issues.assign', $issue) }}" class="space-y-2.5">
                @csrf
                @method('PUT')
                <input type="text" name="actor_name" required placeholder="Nama Anda"
                       class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                <input type="text" name="assigned_to" required placeholder="Assign ke..." value="{{ $issue->assigned_to }}"
                       class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                <button type="submit" class="w-full rounded-lg bg-gray-800 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-900">Assign</button>
            </form>
        </div>

        {{-- Danger zone --}}
        <div class="rounded-xl border border-red-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-red-400">Hapus Issue</h3>
            <form method="POST" action="{{ route('issues.destroy', $issue) }}" class="space-y-2.5"
                  onsubmit="return confirm('Yakin hapus issue ini? Komentar, lampiran, dan riwayat aktivitas akan ikut terhapus dan tidak bisa dikembalikan.');">
                @csrf
                @method('DELETE')
                <input type="text" name="actor_name" required placeholder="Nama Anda"
                       class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-500/20">
                <button type="submit" class="w-full rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700">Hapus Issue</button>
            </form>
        </div>
    </div>
</div>
@endsection
