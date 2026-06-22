@extends('issue-tracker::layouts.app')

@section('title', 'Issue Tracker')

@section('content')
{{-- Stats --}}
<div class="mb-6 grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
    <a href="{{ route('issues.index') }}" class="rounded-xl border border-gray-200 bg-white p-3 text-center shadow-sm hover:shadow {{ !request('status') ? 'ring-2 ring-brand-500' : '' }}">
        <div class="text-xl font-bold text-gray-800">{{ $stats['total'] }}</div>
        <div class="text-[11px] font-medium text-gray-500">Total</div>
    </a>
    @foreach(['open' => ['Open', 'yellow'], 'in_review' => ['In Review', 'purple'], 'in_progress' => ['In Progress', 'blue'], 'resolved' => ['Resolved', 'green'], 'closed' => ['Closed', 'gray']] as $key => [$label, $color])
    <a href="{{ route('issues.index', ['status' => $key]) }}" class="rounded-xl border border-{{ $color }}-200 bg-{{ $color }}-50 p-3 text-center shadow-sm hover:shadow {{ request('status') === $key ? 'ring-2 ring-brand-500' : '' }}">
        <div class="text-xl font-bold text-{{ $color }}-700">{{ $stats[$key] }}</div>
        <div class="text-[11px] font-medium text-{{ $color }}-600">{{ $label }}</div>
    </a>
    @endforeach
</div>

{{-- Header --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-bold text-gray-900">
        @if(request('status'))
            Issue — {{ ucfirst(str_replace('_', ' ', request('status'))) }}
        @else
            Semua Issue
        @endif
    </h1>
    <div class="flex gap-2">
        <a href="{{ route('issues.export', request()->query()) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-600 shadow-sm hover:bg-gray-50">
            <svg class="mr-1 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
            Export CSV
        </a>
        <a href="{{ route('issues.create') }}" class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700">
            <svg class="mr-1 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Laporkan Issue
        </a>
    </div>
</div>

{{-- Filters --}}
<form method="GET" action="{{ route('issues.index') }}" class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
    <div class="flex-1 min-w-[160px]">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari judul, nama, token..."
               class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
    </div>
    <select name="platform" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
        <option value="">Platform</option>
        <option value="website" {{ request('platform') === 'website' ? 'selected' : '' }}>Website</option>
        <option value="mobile_app" {{ request('platform') === 'mobile_app' ? 'selected' : '' }}>Mobile App</option>
    </select>
    <select name="priority" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
        <option value="">Prioritas</option>
        @foreach(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label)
            <option value="{{ $val }}" {{ request('priority') === $val ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    <button type="submit" class="rounded-lg bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900">Filter</button>
    @if(request()->hasAny(['search', 'status', 'platform', 'priority']))
        <a href="{{ route('issues.index') }}" class="px-2 text-xs text-red-500 hover:text-red-700">Reset</a>
    @endif
</form>

{{-- Table --}}
<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500">
                <tr>
                    <th class="px-4 py-2.5 font-medium">Issue</th>
                    <th class="px-3 py-2.5 font-medium">Platform</th>
                    <th class="px-3 py-2.5 font-medium">Prioritas</th>
                    <th class="px-3 py-2.5 font-medium">Status</th>
                    <th class="px-3 py-2.5 font-medium">Pelapor</th>
                    <th class="px-3 py-2.5 font-medium">Assigned</th>
                    <th class="px-3 py-2.5 font-medium">Waktu</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($issues as $issue)
                <tr class="hover:bg-gray-50/50 cursor-pointer" onclick="window.location='{{ route('issues.show', $issue) }}'">
                    <td class="px-4 py-3">
                        <div class="max-w-[260px] truncate font-medium text-gray-900">{{ $issue->title }}</div>
                        <div class="text-[11px] text-gray-400 font-mono">{{ $issue->tracking_token }}</div>
                    </td>
                    <td class="px-3 py-3 text-xs">
                        <span class="rounded-full bg-gray-100 px-2 py-0.5">{{ $issue->platform === 'website' ? 'Web' : 'Mobile' }}</span>
                    </td>
                    <td class="px-3 py-3">@include('issue-tracker::components.priority-badge', ['priority' => $issue->priority])</td>
                    <td class="px-3 py-3">@include('issue-tracker::components.status-badge', ['status' => $issue->status])</td>
                    <td class="px-3 py-3 text-xs text-gray-600">{{ $issue->reporter_name }}</td>
                    <td class="px-3 py-3 text-xs text-gray-600">{{ $issue->assigned_to ?? '—' }}</td>
                    <td class="whitespace-nowrap px-3 py-3 text-xs text-gray-400">{{ $issue->created_at->diffForHumans() }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-5 py-12 text-center">
                        <div class="text-gray-400">Belum ada issue.</div>
                        <a href="{{ route('issues.create') }}" class="mt-2 inline-block text-sm font-medium text-brand-600 hover:text-brand-800">Laporkan issue pertama &rarr;</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($issues->hasPages())
    <div class="border-t border-gray-100 px-5 py-3">
        {{ $issues->links() }}
    </div>
    @endif
</div>
@endsection
