<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Issue Tracker') — Cilupbah</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#eff6ff', 100: '#dbeafe', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a5f' }
                    }
                }
            }
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    <nav class="sticky top-0 z-50 border-b border-gray-200 bg-white/80 backdrop-blur-lg">
        <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4">
            <a href="{{ route('issues.index') }}" class="flex items-center gap-2 font-semibold text-brand-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                </svg>
                Issue Tracker
            </a>
            <div class="flex items-center gap-3">
                <a href="{{ route('issues.index') }}" class="text-sm text-gray-600 hover:text-brand-600">Dashboard</a>
                <a href="{{ route('issues.create') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Laporkan Issue</a>
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-5xl px-4 py-8">
        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" x-data="{ show: true }" x-show="show" x-transition>
                <div class="flex items-center justify-between">
                    <span>{{ session('success') }}</span>
                    <button @click="show = false" class="text-green-600 hover:text-green-800">&times;</button>
                </div>
            </div>
        @endif

        @if($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="border-t border-gray-200 bg-white py-6 text-center text-xs text-gray-500">
        &copy; {{ date('Y') }} Cilupbah — Issue Tracker
    </footer>

    @stack('scripts')
</body>
</html>
