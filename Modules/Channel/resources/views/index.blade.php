<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Channels & Shops - Command Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes slide-in { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }
        .slide-in { animation: slide-in 0.35s ease both; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen text-slate-800">

    <!-- Navbar -->
    <nav class="bg-white/80 backdrop-blur-md border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <h1 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-violet-600">
                        Omnichannel Hub
                    </h1>
                </div>
                <div class="flex items-center">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200">
                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                        Testing Mode — Auth Disabled
                    </span>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6" x-data="{ addModalOpen: false }">

        <!-- Flash: Success -->
        @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-transition class="slide-in bg-emerald-50 border border-emerald-200 rounded-xl p-4 flex items-start justify-between shadow-sm">
            <div class="flex items-start space-x-3">
                <div class="w-9 h-9 rounded-full bg-emerald-100 flex items-center justify-center shrink-0 mt-0.5">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                </div>
                <div>
                    <p class="font-semibold text-emerald-900 text-sm">Binding Berhasil!</p>
                    <p class="text-emerald-700 text-sm mt-0.5">{{ session('success') }}</p>

                    @if(session('new_shops'))
                    <div class="mt-3 space-y-1.5">
                        @foreach(session('new_shops') as $ns)
                        <div class="inline-flex items-center gap-2 bg-emerald-100 text-emerald-800 text-xs font-medium px-3 py-1 rounded-full mr-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            {{ $ns['shop_name'] }} <span class="opacity-60">(ID: {{ $ns['shop_id'] }})</span>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
            <button @click="show = false" class="text-emerald-400 hover:text-emerald-700 ml-4 shrink-0 p-1">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
            </button>
        </div>
        @endif

        <!-- Flash: Error -->
        @if(session('error'))
        <div x-data="{ show: true }" x-show="show" x-transition class="slide-in bg-rose-50 border border-rose-200 rounded-xl p-4 flex items-start justify-between shadow-sm">
            <div class="flex items-start space-x-3">
                <div class="w-9 h-9 rounded-full bg-rose-100 flex items-center justify-center shrink-0 mt-0.5">
                    <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div>
                    <p class="font-semibold text-rose-900 text-sm">Terjadi Kesalahan</p>
                    <p class="text-rose-700 text-sm mt-0.5">{{ session('error') }}</p>
                </div>
            </div>
            <button @click="show = false" class="text-rose-400 hover:text-rose-700 ml-4 shrink-0 p-1">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
            </button>
        </div>
        @endif

        <!-- Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="text-3xl font-bold text-slate-900 tracking-tight">Channels & Stores</h2>
                <p class="text-slate-500 mt-1 text-sm">Manage your connected sales channels and marketplace stores.</p>
            </div>
            <button @click="addModalOpen = true"
                class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-200 hover:-translate-y-0.5">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                Bind New Store
            </button>
        </div>

        <!-- Channels Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            @forelse($channels as $channel)
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md hover:border-indigo-200 transition-all duration-300 flex flex-col">

                <!-- Channel Header -->
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0
                            {{ strtolower($channel->code) === 'tiktok' ? 'bg-black text-white' : 'bg-indigo-100 text-indigo-600' }} shadow-sm">
                            @if(strtolower($channel->code) === 'tiktok')
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1.04-.1z"/></svg>
                            @else
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                            @endif
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-900">{{ $channel->name }}</h3>
                            <p class="text-xs text-slate-400 font-mono uppercase tracking-widest">{{ $channel->code }}</p>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1.5">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold
                            {{ $channel->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-slate-100 text-slate-600' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $channel->is_active ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                            {{ $channel->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        <span class="text-xs text-slate-400">{{ $channel->shops->count() }} store{{ $channel->shops->count() !== 1 ? 's' : '' }}</span>
                    </div>
                </div>

                <!-- Shops List -->
                <div class="flex-1 p-4 space-y-3 overflow-y-auto" style="max-height: 440px;">

                    @forelse($channel->shops as $shop)
                    @php
                        $tokenStatus = 'active';
                        if (!$shop->is_active || !$shop->access_token) {
                            $tokenStatus = 'disconnected';
                        } elseif ($shop->token_expires_at && $shop->token_expires_at->isPast()) {
                            $tokenStatus = 'expired';
                        } elseif ($shop->token_expires_at && $shop->token_expires_at->diffInHours(now()) < 24) {
                            $tokenStatus = 'expiring_soon';
                        }
                        $statusConfig = [
                            'active'        => ['bg' => 'bg-emerald-500', 'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-100', 'label' => 'Token Active'],
                            'expiring_soon' => ['bg' => 'bg-amber-500',   'badge' => 'bg-amber-50 text-amber-700 border-amber-100',     'label' => 'Expiring Soon'],
                            'expired'       => ['bg' => 'bg-rose-500',    'badge' => 'bg-rose-50 text-rose-700 border-rose-100',         'label' => 'Token Expired'],
                            'disconnected'  => ['bg' => 'bg-slate-400',   'badge' => 'bg-slate-100 text-slate-600 border-slate-200',     'label' => 'Disconnected'],
                        ];
                        $cfg = $statusConfig[$tokenStatus];
                    @endphp

                    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden transition-shadow hover:shadow-md"
                         x-data="{ menuOpen: false, expanded: false }">

                        <!-- Shop Row -->
                        <div class="flex items-center gap-2 p-3">
                            <!-- Expand toggle -->
                            <button @click="expanded = !expanded"
                                class="w-8 h-8 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center shrink-0 hover:bg-slate-100 transition-colors">
                                <svg class="w-4 h-4 text-slate-500 transition-transform duration-200"
                                     :class="expanded ? 'rotate-90' : ''"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>

                            <!-- Shop Name & Status -->
                            <div class="flex-1 min-w-0 cursor-pointer" @click="expanded = !expanded">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full shrink-0 {{ $cfg['bg'] }}"></span>
                                    <p class="text-sm font-semibold text-slate-900 truncate">{{ $shop->shop_name }}</p>
                                </div>
                                <p class="text-xs text-slate-400 font-mono mt-0.5 truncate pl-4">{{ $shop->shop_id }}</p>
                            </div>

                            <!-- Action Menu -->
                            <div class="relative shrink-0" @click.outside="menuOpen = false">
                                <button @click="menuOpen = !menuOpen"
                                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg>
                                </button>
                                <div x-show="menuOpen"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-95"
                                     class="absolute right-0 mt-1 w-52 bg-white rounded-xl shadow-xl border border-slate-200 py-1.5 z-30"
                                     style="display:none;">
                                    <form action="{{ route('channel.shop.refresh-token', $shop->id) }}" method="POST">
                                        @csrf
                                        <button type="submit"
                                            class="w-full flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50 transition-colors text-left"
                                            onclick="return confirm('Refresh token untuk toko \'{{ addslashes($shop->shop_name) }}\'?')">
                                            <svg class="w-4 h-4 mr-3 text-indigo-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                            Refresh Token
                                        </button>
                                    </form>
                                    <div class="border-t border-slate-100 my-1"></div>
                                    <form action="{{ route('channel.shop.disconnect', $shop->id) }}" method="POST">
                                        @csrf
                                        <button type="submit"
                                            class="w-full flex items-center px-4 py-2.5 text-sm text-rose-600 hover:bg-rose-50 transition-colors text-left"
                                            onclick="return confirm('Putuskan toko \'{{ addslashes($shop->shop_name) }}\' dari channel ini?')">
                                            <svg class="w-4 h-4 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                                            Disconnect Store
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Expanded: Detail Data -->
                        <div x-show="expanded"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="border-t border-slate-100 bg-slate-50 px-4 py-3 space-y-3"
                             style="display:none;">

                            <!-- Token Status -->
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Token Status</span>
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full border {{ $cfg['badge'] }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $cfg['bg'] }}"></span>
                                    {{ $cfg['label'] }}
                                </span>
                            </div>

                            <!-- Data Fields -->
                            <dl class="grid grid-cols-1 gap-2 text-xs">
                                <div class="flex justify-between items-start py-1.5 border-b border-slate-200/70">
                                    <dt class="font-medium text-slate-500 shrink-0">Shop ID</dt>
                                    <dd class="text-slate-800 font-mono text-right break-all ml-4">{{ $shop->shop_id }}</dd>
                                </div>

                                @if($shop->shop_cipher)
                                <div class="flex justify-between items-start py-1.5 border-b border-slate-200/70">
                                    <dt class="font-medium text-slate-500 shrink-0">Cipher</dt>
                                    <dd class="text-slate-800 font-mono text-right break-all ml-4 max-w-[180px] truncate" title="{{ $shop->shop_cipher }}">{{ $shop->shop_cipher }}</dd>
                                </div>
                                @endif

                                <div class="flex justify-between items-start py-1.5 border-b border-slate-200/70">
                                    <dt class="font-medium text-slate-500 shrink-0">Token Expires</dt>
                                    <dd class="text-slate-800 text-right ml-4">
                                        @if($shop->token_expires_at)
                                            <span class="{{ $shop->token_expires_at->isPast() ? 'text-rose-600' : ($shop->token_expires_at->diffInHours(now()) < 24 ? 'text-amber-600' : 'text-slate-800') }} font-medium">
                                                {{ $shop->token_expires_at->format('d M Y, H:i') }}
                                            </span>
                                            <span class="block text-slate-400 mt-0.5">
                                                @if($shop->token_expires_at->isPast())
                                                    Expired {{ $shop->token_expires_at->diffForHumans() }}
                                                @else
                                                    Expires {{ $shop->token_expires_at->diffForHumans() }}
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-slate-400 italic">N/A</span>
                                        @endif
                                    </dd>
                                </div>

                                <div class="flex justify-between items-start py-1.5 border-b border-slate-200/70">
                                    <dt class="font-medium text-slate-500 shrink-0">Refresh Token Expires</dt>
                                    <dd class="text-slate-800 text-right ml-4">
                                        @if($shop->refresh_token_expires_at)
                                            {{ $shop->refresh_token_expires_at->format('d M Y') }}
                                            <span class="block text-slate-400 mt-0.5">{{ $shop->refresh_token_expires_at->diffForHumans() }}</span>
                                        @else
                                            <span class="text-slate-400 italic">N/A</span>
                                        @endif
                                    </dd>
                                </div>

                                <div class="flex justify-between items-start py-1.5">
                                    <dt class="font-medium text-slate-500 shrink-0">Connected At</dt>
                                    <dd class="text-slate-800 text-right ml-4">
                                        {{ $shop->created_at->format('d M Y, H:i') }}
                                    </dd>
                                </div>
                            </dl>

                            <!-- Quick Actions -->
                            <div class="flex gap-2 pt-1">
                                <form action="{{ route('channel.shop.refresh-token', $shop->id) }}" method="POST" class="flex-1">
                                    @csrf
                                    <button type="submit"
                                        class="w-full py-2 text-xs font-semibold text-indigo-600 bg-white border border-indigo-200 hover:bg-indigo-50 rounded-lg transition-colors"
                                        onclick="return confirm('Refresh token untuk toko \'{{ addslashes($shop->shop_name) }}\'?')">
                                        <svg class="w-3.5 h-3.5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                        Refresh Token
                                    </button>
                                </form>
                                <form action="{{ route('channel.shop.disconnect', $shop->id) }}" method="POST" class="flex-1">
                                    @csrf
                                    <button type="submit"
                                        class="w-full py-2 text-xs font-semibold text-rose-600 bg-white border border-rose-200 hover:bg-rose-50 rounded-lg transition-colors"
                                        onclick="return confirm('Putuskan toko \'{{ addslashes($shop->shop_name) }}\' dari channel ini?')">
                                        <svg class="w-3.5 h-3.5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                        Disconnect
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>
                    @empty
                    <div class="text-center py-8 rounded-xl border-2 border-dashed border-slate-200 bg-slate-50/50">
                        <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-2">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                        </div>
                        <p class="text-sm font-medium text-slate-500">No stores bound yet.</p>
                        <p class="text-xs text-slate-400 mt-0.5">Click "Bind New Store" to get started.</p>
                    </div>
                    @endforelse
                </div>

                <!-- Footer: Add Store -->
                @if($channel->is_active)
                <div class="p-4 border-t border-slate-100 bg-slate-50/50">
                    <button @click="addModalOpen = true"
                        class="w-full inline-flex justify-center items-center px-3 py-2 text-sm font-semibold text-indigo-600 bg-white border border-indigo-200 hover:bg-indigo-50 rounded-lg transition-colors">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Add {{ $channel->name }} Store
                    </button>
                </div>
                @endif
            </div>
            @empty
            <div class="col-span-full">
                <div class="bg-white rounded-2xl border-2 border-dashed border-slate-200 p-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <h3 class="mt-3 text-base font-semibold text-slate-700">No Channels Configured</h3>
                    <p class="mt-1 text-sm text-slate-400">No channel records found in the database.</p>
                </div>
            </div>
            @endforelse
        </div>

        <!-- Add Store Modal -->
        <div x-show="addModalOpen" class="fixed inset-0 z-[100] overflow-y-auto" role="dialog" aria-modal="true" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center">
                <div x-show="addModalOpen"
                     x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                     x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"
                     @click="addModalOpen = false"></div>

                <div x-show="addModalOpen"
                     x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="relative bg-white rounded-2xl shadow-2xl text-left w-full max-w-md mx-auto border border-slate-200 z-10">

                    <!-- Modal Header -->
                    <div class="flex items-center justify-between p-6 border-b border-slate-100">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center">
                                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">Bind a New Store</h3>
                                <p class="text-xs text-slate-500">You will be redirected to authorize</p>
                            </div>
                        </div>
                        <button @click="addModalOpen = false" class="p-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>

                    <!-- Modal Body -->
                    <div class="p-6 space-y-3">
                        <!-- Step indicator -->
                        <div class="flex items-center gap-2 text-xs text-slate-500 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 mb-4">
                            <svg class="w-4 h-4 text-indigo-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Anda akan diarahkan ke halaman otorisasi marketplace, lalu otomatis kembali ke sini setelah selesai.
                        </div>

                        <!-- TikTok Shop -->
                        <a href="/api/v1/tiktok/auth"
                           class="flex items-center p-4 rounded-xl border-2 border-slate-100 hover:border-black hover:bg-slate-50 transition-all group cursor-pointer">
                            <div class="w-11 h-11 rounded-xl bg-black flex items-center justify-center text-white mr-4 shadow-sm group-hover:scale-105 transition-transform shrink-0">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1.04-.1z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="text-sm font-semibold text-slate-900">TikTok Shop</h4>
                                <p class="text-xs text-slate-500 mt-0.5">Authorize via TikTok Developer Portal</p>
                            </div>
                            <svg class="w-4 h-4 text-slate-400 group-hover:text-black group-hover:translate-x-0.5 transition-all shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </a>

                        <!-- Shopee (Coming Soon) -->
                        <div class="flex items-center p-4 rounded-xl border-2 border-slate-100 bg-slate-50/80 opacity-50 cursor-not-allowed">
                            <div class="w-11 h-11 rounded-xl bg-[#ee4d2d] flex items-center justify-center text-white mr-4 shadow-sm shrink-0">
                                <span class="font-bold text-base">S</span>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-sm font-semibold text-slate-900">Shopee</h4>
                                <p class="text-xs text-slate-500 mt-0.5">Shopee Open Platform</p>
                            </div>
                            <span class="text-xs font-semibold px-2 py-1 bg-slate-200 text-slate-500 rounded-lg shrink-0">Coming Soon</span>
                        </div>

                        <!-- Tokopedia (Coming Soon) -->
                        <div class="flex items-center p-4 rounded-xl border-2 border-slate-100 bg-slate-50/80 opacity-50 cursor-not-allowed">
                            <div class="w-11 h-11 rounded-xl bg-[#42b549] flex items-center justify-center text-white mr-4 shadow-sm shrink-0">
                                <span class="font-bold text-base">T</span>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-sm font-semibold text-slate-900">Tokopedia</h4>
                                <p class="text-xs text-slate-500 mt-0.5">Tokopedia Open API</p>
                            </div>
                            <span class="text-xs font-semibold px-2 py-1 bg-slate-200 text-slate-500 rounded-lg shrink-0">Coming Soon</span>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="px-6 pb-6">
                        <button @click="addModalOpen = false"
                            class="w-full py-2.5 text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</body>
</html>
