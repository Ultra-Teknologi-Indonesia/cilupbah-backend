<x-channel::layouts.master>
<div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-900 py-12 px-4 sm:px-6 lg:px-8 font-sans text-slate-200">
    <div class="max-w-5xl mx-auto space-y-8">
        
        <!-- Header -->
        <div class="text-center space-y-2">
            <h1 class="text-4xl font-extrabold tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-500 drop-shadow-sm">
                TikTok Shop Synchronization
            </h1>
            <p class="text-slate-400 text-lg">Push products to TikTok or Pull updates from your shop effortlessly.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            
            <!-- PULL CARD -->
            <div class="relative group">
                <div class="absolute -inset-0.5 bg-gradient-to-r from-teal-400 to-emerald-500 rounded-2xl blur opacity-25 group-hover:opacity-50 transition duration-500"></div>
                <div class="relative bg-slate-800/80 backdrop-blur-xl border border-slate-700/50 rounded-2xl p-8 shadow-2xl flex flex-col h-full">
                    <div class="flex items-center space-x-4 mb-6">
                        <div class="p-3 bg-teal-500/20 rounded-xl">
                            <svg class="w-8 h-8 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        </div>
                        <h2 class="text-2xl font-bold text-white">Pull Products</h2>
                    </div>
                    
                    <p class="text-slate-400 mb-6 flex-grow">Download and sync the latest 100 products from your TikTok Shop. Existing products will be updated, and new ones will be created.</p>

                    <form id="pull-form" class="space-y-5">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Shop ID</label>
                            <input type="text" id="pull_shop_id" name="shop_id" value="{{ env('TIKTOK_DEFAULT_SHOP_ID') }}" class="w-full bg-slate-900/50 border border-slate-600 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition" placeholder="e.g. 7643300999967131393" required>
                        </div>
                        
                        <button type="submit" id="pull-btn" class="w-full relative inline-flex items-center justify-center px-8 py-3.5 text-base font-semibold text-white bg-gradient-to-r from-teal-500 to-emerald-500 rounded-xl overflow-hidden hover:from-teal-400 hover:to-emerald-400 transition-all shadow-[0_0_20px_rgba(20,184,166,0.3)] hover:shadow-[0_0_25px_rgba(20,184,166,0.5)] group">
                            <span id="pull-btn-text">Sync from TikTok</span>
                            <svg id="pull-spinner" class="animate-spin ml-3 h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>

            <!-- PUSH CARD -->
            <div class="relative group">
                <div class="absolute -inset-0.5 bg-gradient-to-r from-blue-500 to-purple-600 rounded-2xl blur opacity-25 group-hover:opacity-50 transition duration-500"></div>
                <div class="relative bg-slate-800/80 backdrop-blur-xl border border-slate-700/50 rounded-2xl p-8 shadow-2xl flex flex-col h-full">
                    <div class="flex items-center space-x-4 mb-6">
                        <div class="p-3 bg-blue-500/20 rounded-xl">
                            <svg class="w-8 h-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        </div>
                        <h2 class="text-2xl font-bold text-white">Push Product</h2>
                    </div>
                    
                    <p class="text-slate-400 mb-6 flex-grow">Upload a local product to TikTok Shop. Images will be automatically converted and uploaded via TikTok API.</p>

                    <form id="push-form" class="space-y-5">
                        @csrf
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-1">Product ID</label>
                                <input type="number" id="push_product_id" name="product_id" class="w-full bg-slate-900/50 border border-slate-600 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition" placeholder="e.g. 1" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-1">Shop ID</label>
                                <input type="text" id="push_shop_id" name="shop_id" value="{{ env('TIKTOK_DEFAULT_SHOP_ID') }}" class="w-full bg-slate-900/50 border border-slate-600 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition" placeholder="e.g. 764..." required>
                            </div>
                        </div>
                        
                        <button type="submit" id="push-btn" class="w-full relative inline-flex items-center justify-center px-8 py-3.5 text-base font-semibold text-white bg-gradient-to-r from-blue-500 to-purple-600 rounded-xl overflow-hidden hover:from-blue-400 hover:to-purple-500 transition-all shadow-[0_0_20px_rgba(59,130,246,0.3)] hover:shadow-[0_0_25px_rgba(59,130,246,0.5)] group">
                            <span id="push-btn-text">Push to TikTok</span>
                            <svg id="push-spinner" class="animate-spin ml-3 h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Terminal Output Log -->
        <div class="mt-8 relative group">
            <div class="absolute -inset-0.5 bg-gradient-to-b from-slate-700 to-slate-800 rounded-2xl blur opacity-20"></div>
            <div class="relative bg-[#0f172a] border border-slate-700 rounded-2xl overflow-hidden shadow-xl">
                <div class="flex items-center px-4 py-3 border-b border-slate-700/60 bg-slate-800/50">
                    <div class="flex space-x-2">
                        <div class="w-3 h-3 rounded-full bg-red-500/80"></div>
                        <div class="w-3 h-3 rounded-full bg-yellow-500/80"></div>
                        <div class="w-3 h-3 rounded-full bg-green-500/80"></div>
                    </div>
                    <div class="mx-auto text-xs font-medium text-slate-400 tracking-widest uppercase">System Output Log</div>
                </div>
                <div class="p-6 h-64 overflow-y-auto font-mono text-sm">
                    <pre id="terminal-log" class="text-green-400 whitespace-pre-wrap leading-relaxed">Waiting for commands...</pre>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="fixed bottom-5 right-5 space-y-3 z-50"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        
        const bgColor = type === 'success' ? 'bg-emerald-500/90' : 'bg-rose-500/90';
        const icon = type === 'success' 
            ? '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>'
            : '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';

        toast.className = `flex items-center p-4 rounded-xl shadow-lg text-white transform transition-all duration-300 translate-y-10 opacity-0 ${bgColor} backdrop-blur-md`;
        toast.innerHTML = `${icon} <span>${message}</span>`;
        
        container.appendChild(toast);
        
        // Animate in
        requestAnimationFrame(() => {
            toast.classList.remove('translate-y-10', 'opacity-0');
        });

        // Animate out after 4 seconds
        setTimeout(() => {
            toast.classList.add('opacity-0', 'translate-x-10');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    function appendLog(message, isError = false) {
        const log = document.getElementById('terminal-log');
        const timestamp = new Date().toLocaleTimeString();
        const color = isError ? 'text-red-400' : 'text-slate-300';
        
        if(log.innerText === 'Waiting for commands...') log.innerText = '';
        
        log.innerHTML += `\n<span class="text-slate-500">[${timestamp}]</span> <span class="${color}">${message}</span>`;
        log.scrollTop = log.scrollHeight;
    }

    async function handleFormSubmit(e, type) {
        e.preventDefault();
        
        const form = e.target;
        const btnText = document.getElementById(`${type}-btn-text`);
        const spinner = document.getElementById(`${type}-spinner`);
        const btn = document.getElementById(`${type}-btn`);
        
        // UI Loading state
        const originalText = btnText.innerText;
        btnText.innerText = 'Processing...';
        spinner.classList.remove('hidden');
        btn.disabled = true;
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        
        appendLog(`Executing ${type} command...`, false);

        try {
            const formData = new FormData(form);
            const response = await fetch(`{{ url('tiktok-sync') }}/${type}`, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': formData.get('_token')
                }
            });

            const result = await response.json();

            if (response.ok && result.status === 'success') {
                showToast(result.message, 'success');
                if (result.output) {
                    appendLog(result.output);
                }
            } else {
                throw new Error(result.message || 'An error occurred');
            }
        } catch (error) {
            showToast(error.message, 'error');
            appendLog(`Error: ${error.message}`, true);
        } finally {
            // Restore UI
            btnText.innerText = originalText;
            spinner.classList.add('hidden');
            btn.disabled = false;
            btn.classList.remove('opacity-75', 'cursor-not-allowed');
        }
    }

    document.getElementById('pull-form').addEventListener('submit', (e) => handleFormSubmit(e, 'pull'));
    document.getElementById('push-form').addEventListener('submit', (e) => handleFormSubmit(e, 'push'));
});
</script>
</x-channel::layouts.master>
