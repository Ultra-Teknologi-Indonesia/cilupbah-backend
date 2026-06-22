@php
$styles = [
    'open' => 'bg-yellow-100 text-yellow-800',
    'in_review' => 'bg-purple-100 text-purple-800',
    'in_progress' => 'bg-blue-100 text-blue-800',
    'resolved' => 'bg-green-100 text-green-800',
    'closed' => 'bg-gray-100 text-gray-600',
];
$labels = [
    'open' => 'Open',
    'in_review' => 'In Review',
    'in_progress' => 'In Progress',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];
@endphp
<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $styles[$status] ?? 'bg-gray-100 text-gray-600' }}">
    {{ $labels[$status] ?? ucfirst($status) }}
</span>
