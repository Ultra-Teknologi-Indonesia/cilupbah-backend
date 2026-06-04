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
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen text-slate-800">

    <!-- Navbar -->
    <nav class="bg-white/80 backdrop-blur-md border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-xl">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <h1 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-violet-600">
                        Omnichannel Hub
                    </h1>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8" x-data="{ addModalOpen: false }">
        
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="text-3xl font-bold text-slate-900 tracking-tight">Channels & Stores</h2>
                <p class="text-slate-500 mt-1">Manage your connected sales channels and marketplace stores.</p>
            </div>
            
            <button @click="addModalOpen = true" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-200 transform hover:-translate-y-0.5">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                Bind New Store
            </button>
        </div>

        <!-- Channels Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse($channels as $channel)
                <div class="glass-card rounded-2xl overflow-hidden transition-all duration-300 hover:shadow-lg hover:border-indigo-200 flex flex-col h-full">
                    
                    <!-- Channel Header -->
                    <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-white/50">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center {{ strtolower($channel->name) == 'tiktok' ? 'bg-black text-white' : 'bg-indigo-100 text-indigo-600' }}">
                                @if(strtolower($channel->name) == 'tiktok')
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1.04-.1z"/></svg>
                                @else
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                                @endif
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ $channel->name }}</h3>
                                <p class="text-xs text-slate-500 font-medium tracking-wide uppercase">{{ $channel->code }}</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $channel->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-800' }}">
                            {{ $channel->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>

                    <!-- Bound Shops List -->
                    <div class="p-5 flex-1 bg-white/30">
                        <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4">Connected Stores</h4>
                        
                        <div class="space-y-3">
                            @forelse($channel->shops as $shop)
                                <div class="flex items-center justify-between p-3 rounded-xl bg-white border border-slate-100 shadow-sm hover:border-indigo-100 transition-colors group">
                                    <div class="flex items-center space-x-3 overflow-hidden">
                                        <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center shrink-0">
                                            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                                        </div>
                                        <div class="truncate">
                                            <p class="text-sm font-medium text-slate-900 truncate">{{ $shop->shop_name }}</p>
                                            <p class="text-xs text-slate-500 truncate">ID: {{ $shop->shop_id }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2 shrink-0">
                                        <span class="w-2 h-2 rounded-full {{ $shop->is_active ? 'bg-emerald-500' : 'bg-rose-500' }}" title="{{ $shop->is_active ? 'Active' : 'Disconnected' }}"></span>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-6">
                                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-100 mb-2">
                                        <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                    </div>
                                    <p class="text-sm text-slate-500">No stores bound yet.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                    
                    @if($channel->is_active)
                    <div class="p-4 border-t border-slate-100 bg-white/40">
                        <button @click="addModalOpen = true" class="w-full inline-flex justify-center items-center px-4 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors">
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Add {{ $channel->name }} Store
                        </button>
                    </div>
                    @endif
                </div>
            @empty
                <div class="col-span-full">
                    <div class="bg-white rounded-2xl border border-dashed border-slate-300 p-12 text-center">
                        <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-slate-900">No Channels Configured</h3>
                        <p class="mt-1 text-sm text-slate-500">Get started by creating a new channel in the database.</p>
                    </div>
                </div>
            @endforelse
        </div>
        
        <!-- Add Store Modal -->
        <div x-show="addModalOpen" class="fixed inset-0 z-[100] overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true" style="display: none;">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                
                <div x-show="addModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" aria-hidden="true" @click="addModalOpen = false"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div x-show="addModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-200">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-semibold text-slate-900" id="modal-title">Bind a New Store</h3>
                                <div class="mt-2">
                                    <p class="text-sm text-slate-500 mb-4">Select the marketplace or channel you want to integrate with.</p>
                                    
                                    <div class="space-y-3">
                                        <!-- TikTok Shop Connect -->
                                        <a href="/api/v1/tiktok/auth" class="flex items-center p-3 rounded-xl border-2 border-slate-100 hover:border-black hover:bg-slate-50 transition-all group">
                                            <div class="w-10 h-10 rounded-full bg-black flex items-center justify-center text-white mr-4 shadow-sm group-hover:scale-105 transition-transform">
                                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1.04-.1z"/></svg>
                                            </div>
                                            <div class="flex-1">
                                                <h4 class="text-sm font-semibold text-slate-900">TikTok Shop</h4>
                                                <p class="text-xs text-slate-500">Connect via TikTok Developer Portal</p>
                                            </div>
                                            <svg class="w-5 h-5 text-slate-400 group-hover:text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                        </a>

                                        <!-- Shopee Connect (Placeholder) -->
                                        <div class="flex items-center p-3 rounded-xl border-2 border-slate-100 opacity-60 bg-slate-50 relative overflow-hidden">
                                            <div class="absolute inset-0 bg-white/40 backdrop-blur-[1px] z-10 flex items-center justify-end pr-4">
                                                <span class="text-xs font-semibold px-2 py-1 bg-slate-200 text-slate-600 rounded">Coming Soon</span>
                                            </div>
                                            <div class="w-10 h-10 rounded-full bg-[#ee4d2d] flex items-center justify-center text-white mr-4 shadow-sm">
                                                <span class="font-bold text-lg">S</span>
                                            </div>
                                            <div class="flex-1">
                                                <h4 class="text-sm font-semibold text-slate-900">Shopee</h4>
                                                <p class="text-xs text-slate-500">Shopee Open Platform</p>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-slate-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse rounded-b-2xl border-t border-slate-100">
                        <button type="button" @click="addModalOpen = false" class="mt-3 w-full inline-flex justify-center rounded-lg border border-slate-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</body>
</html>
