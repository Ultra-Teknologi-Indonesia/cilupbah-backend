<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok Shop Sync Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen">
    <!-- Navbar -->
    <nav class="bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <h1 class="text-2xl font-bold text-slate-900">TikTok Shop Command Center</h1>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <!-- Flash Messages -->
        @if(session('success'))
        <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-md shadow-sm flex items-center justify-between">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-emerald-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                <p class="text-emerald-700 font-medium">{{ session('success') }}</p>
            </div>
            <button onclick="this.parentElement.style.display='none'" class="text-emerald-500 hover:text-emerald-700">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
            </button>
        </div>
        @endif
        @if(session('error'))
        <div class="bg-rose-50 border-l-4 border-rose-500 p-4 rounded-md shadow-sm flex items-center justify-between">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-rose-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <p class="text-rose-700 font-medium">{{ session('error') }}</p>
            </div>
            <button onclick="this.parentElement.style.display='none'" class="text-rose-500 hover:text-rose-700">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
            </button>
        </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- LEFT COLUMN: Products -->
            <div class="lg:col-span-2 space-y-8">
                
                <!-- Product List Card -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                        <h2 class="text-lg font-semibold text-slate-800">Daftar Produk</h2>
                        <div class="flex items-center space-x-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Total: {{ count($products) }}</span>
                            <form action="{{ route('tiktok-sync.bulk-push') }}" method="POST" class="inline" onsubmit="this.querySelector('button').innerHTML='Memproses...'; this.querySelector('button').disabled=true;">
                                @csrf
                                <input type="hidden" name="shop_id" value="7494685794425930858">
                                <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <svg class="-ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                    Push Semua Produk
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Nama & SKU</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Harga</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Aksi (TikTok)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-slate-200">
                                @forelse($products as $p)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">#{{ $p->id }}</td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-slate-900">{{ $p->name }}</div>
                                        <div class="text-sm text-slate-500">{{ $p->sku }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-900 font-medium">Rp {{ number_format(DB::table('product_variants')->where('product_id', $p->id)->value('sell_price') ?? 0, 0, ',', '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        @if($p->tiktok_product_id)
                                            <div class="flex items-center justify-end space-x-3">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                                    <svg class="mr-1 h-3 w-3 text-emerald-600" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3" /></svg>
                                                    Tersinkron
                                                </span>
                                                <form action="{{ route('tiktok-sync.sync-product') }}" method="POST" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="shop_id" value="7494685794425930858">
                                                    <input type="hidden" name="product_id" value="{{ $p->id }}">
                                                    <button type="submit" class="text-emerald-600 hover:text-emerald-800 text-xs font-semibold bg-emerald-50 hover:bg-emerald-100 px-2 py-1 rounded" title="Update Harga & Stok ke TikTok">Sync Ulang</button>
                                                </form>
                                            </div>
                                        @else
                                            <form action="{{ route('tiktok-sync.push') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="shop_id" value="7494685794425930858">
                                                <input type="hidden" name="product_id" value="{{ $p->id }}">
                                                <button type="submit" class="text-blue-600 hover:text-blue-900 bg-blue-50 hover:bg-blue-100 px-3 py-1 rounded-md transition-colors">Push</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-center text-sm text-slate-500">Belum ada produk.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Orders List Card -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                        <h2 class="text-lg font-semibold text-slate-800">Pesanan TikTok</h2>
                        <form action="{{ route('tiktok-sync.pull') }}" method="POST" class="inline">
                            @csrf
                            <input type="hidden" name="shop_id" value="7494685794425930858">
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-slate-800 hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500">
                                <svg class="-ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                Tarik Terbaru
                            </button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Order ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Total</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-slate-200">
                                @forelse($orders as $o)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-slate-900">{{ $o->order_number }}</div>
                                        <div class="text-xs text-slate-500 mt-1 truncate max-w-xs" title="{{ $o->product_names }}">{{ $o->product_names }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $o->status === 'CANCELLED' ? 'bg-rose-100 text-rose-800' : ($o->status === 'AWAITING_SHIPMENT' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-800') }}">
                                            {{ $o->status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">Rp {{ number_format($o->total_amount, 0, ',', '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        @if($o->status === 'AWAITING_SHIPMENT')
                                        <div class="flex space-x-2 justify-end">
                                            <form action="{{ route('tiktok-sync.accept') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="shop_id" value="7494685794425930858">
                                                <input type="hidden" name="order_id" value="{{ $o->order_number }}">
                                                <button type="submit" class="text-emerald-600 hover:text-emerald-900 bg-emerald-50 hover:bg-emerald-100 px-3 py-1 rounded-md transition-colors" onclick="return confirm('Terima pesanan ini?')">Accept</button>
                                            </form>
                                            <form action="{{ route('tiktok-sync.decline') }}" method="POST" class="inline-flex items-center space-x-2">
                                                @csrf
                                                <input type="hidden" name="shop_id" value="7494685794425930858">
                                                <input type="hidden" name="order_id" value="{{ $o->order_number }}">
                                                <select name="reason" class="text-xs border-slate-300 rounded-md shadow-sm focus:border-rose-500 focus:ring-rose-500 py-1" required>
                                                    <option value="seller_cancel_reason_out_of_stock">Stok Habis</option>
                                                    <option value="seller_cancel_reason_wrong_price">Salah Harga</option>
                                                    <option value="seller_cancel_unable_to_deliver_to_buyer_address">Alamat Tidak Terjangkau</option>
                                                    <option value="seller_cancel_reason_logistics_issue">Kendala Logistik</option>
                                                </select>
                                                <button type="submit" class="text-rose-600 hover:text-rose-900 bg-rose-50 hover:bg-rose-100 px-3 py-1 rounded-md transition-colors" onclick="return confirm('Tolak pesanan ini?')">Decline</button>
                                            </form>
                                        </div>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-center text-sm text-slate-500">Belum ada pesanan ditarik.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Add Product Form -->
            <div class="space-y-8">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden sticky top-8">
                    <div class="p-5 bg-emerald-50 border-b border-emerald-100">
                        <h2 class="text-lg font-semibold text-emerald-900 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Tambah Produk Baru
                        </h2>
                    </div>
                    <div class="p-5">
                        <form action="{{ route('tiktok-sync.store-product') }}" method="POST" class="space-y-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Nama Produk</label>
                                <input type="text" name="name" placeholder="Cth: Sepatu Nike Air Force" class="w-full rounded-md border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm px-3 py-2 border outline-none" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">SKU</label>
                                <input type="text" name="sku" placeholder="Cth: NIKE-AF1-WHT" class="w-full rounded-md border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm px-3 py-2 border outline-none" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Harga (Rp)</label>
                                <input type="number" name="price" placeholder="Cth: 1500000" class="w-full rounded-md border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm px-3 py-2 border outline-none" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Deskripsi Singkat</label>
                                <textarea name="description" rows="3" placeholder="Sepatu kasual terbaik..." class="w-full rounded-md border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm px-3 py-2 border outline-none" required></textarea>
                            </div>
                            <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-colors">
                                Simpan ke Database
                            </button>
                            <p class="text-xs text-slate-500 mt-3 text-center">Gambar dan kategori akan diisi otomatis sebagai dummy.</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
