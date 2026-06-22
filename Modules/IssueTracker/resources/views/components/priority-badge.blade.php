@php
$styles = [
    'low' => 'bg-gray-100 text-gray-600',
    'medium' => 'bg-blue-50 text-blue-700',
    'high' => 'bg-orange-100 text-orange-700',
    'critical' => 'bg-red-100 text-red-700',
];
$labels = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'critical' => 'Critical',
];
@endphp
<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $styles[$priority] ?? 'bg-gray-100 text-gray-600' }}">
    {{ $labels[$priority] ?? ucfirst($priority) }}
</span>
