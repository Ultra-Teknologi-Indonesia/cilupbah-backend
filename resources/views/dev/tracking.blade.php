<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dev Tracker — Cilupbah WMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-slate-100 text-slate-800">
<div x-data="tracker()" x-init="load()" x-cloak class="max-w-[1400px] mx-auto p-4">

    <header class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">📊 Dev Tracker — Parity Sistem Lama</h1>
            <p class="text-sm text-slate-500">Sumber: TASK-BREAKDOWN.md · {{ app()->environment() }}</p>
        </div>
        <div class="flex gap-2">
            <a :href="exportUrl('csv')" class="px-3 py-2 text-sm bg-white border rounded shadow-sm hover:bg-slate-50">⬇ CSV</a>
            <a :href="exportUrl('md')" class="px-3 py-2 text-sm bg-white border rounded shadow-sm hover:bg-slate-50">⬇ Markdown</a>
        </div>
    </header>

    <!-- Ringkasan -->
    <section class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4" x-show="summary.overall">
        <template x-for="card in overallCards()" :key="card.label">
            <div class="bg-white rounded-lg p-3 shadow-sm border">
                <div class="text-xs text-slate-500" x-text="card.label"></div>
                <div class="text-2xl font-bold" :class="card.color" x-text="card.value"></div>
            </div>
        </template>
    </section>

    <!-- Progress bar keseluruhan -->
    <section class="bg-white rounded-lg p-4 shadow-sm border mb-4" x-show="summary.overall">
        <div class="flex justify-between text-xs mb-1">
            <span>Progress keseluruhan</span>
            <span x-text="pct(summary.overall) + '% selesai (done)'"></span>
        </div>
        <div class="w-full h-4 rounded-full overflow-hidden flex bg-slate-200">
            <div class="bg-emerald-500" :style="`width:${bar(summary.overall,'done')}%`" title="done"></div>
            <div class="bg-amber-400" :style="`width:${bar(summary.overall,'in_progress')}%`" title="in progress"></div>
            <div class="bg-slate-300" :style="`width:${bar(summary.overall,'todo')}%`" title="todo"></div>
            <div class="bg-rose-500" :style="`width:${bar(summary.overall,'blocked')}%`" title="blocked"></div>
        </div>
        <!-- Per PIC -->
        <div class="grid md:grid-cols-2 gap-4 mt-4">
            <template x-for="(s,pic) in summary.per_pic" :key="pic">
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-medium" x-text="`👤 ${pic}`"></span>
                        <span x-text="`${s.done}/${s.total} done`"></span>
                    </div>
                    <div class="w-full h-3 rounded-full overflow-hidden flex bg-slate-200">
                        <div class="bg-emerald-500" :style="`width:${bar(s,'done')}%`"></div>
                        <div class="bg-amber-400" :style="`width:${bar(s,'in_progress')}%`"></div>
                        <div class="bg-slate-300" :style="`width:${bar(s,'todo')}%`"></div>
                        <div class="bg-rose-500" :style="`width:${bar(s,'blocked')}%`"></div>
                    </div>
                </div>
            </template>
        </div>
    </section>

    <!-- Filter -->
    <section class="flex flex-wrap gap-2 mb-3 items-center">
        <input type="search" x-model.debounce.300ms="filters.q" @input="load()" placeholder="🔍 cari endpoint / fungsi…"
               class="px-3 py-2 text-sm border rounded w-64">
        <select x-model="filters.domain" @change="load()" class="px-2 py-2 text-sm border rounded">
            <option value="">Semua domain</option>
            <template x-for="d in meta.domains" :key="d"><option :value="d" x-text="d"></option></template>
        </select>
        <select x-model="filters.status" @change="load()" class="px-2 py-2 text-sm border rounded">
            <option value="">Semua status</option>
            <template x-for="s in meta.statuses" :key="s"><option :value="s" x-text="statusLabel(s)"></option></template>
        </select>
        <select x-model="filters.pic" @change="load()" class="px-2 py-2 text-sm border rounded">
            <option value="">Semua PIC</option>
            <template x-for="p in meta.pics" :key="p"><option :value="p" x-text="p"></option></template>
        </select>
        <select x-model="filters.source" @change="load()" class="px-2 py-2 text-sm border rounded">
            <option value="">Semua source</option>
            <template x-for="s in meta.sources" :key="s"><option :value="s" x-text="s"></option></template>
        </select>
        <span class="text-sm text-slate-500" x-text="`${items.length} item`"></span>
    </section>

    <!-- Tabel -->
    <div class="bg-white rounded-lg shadow-sm border overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 sticky top-0">
                <tr>
                    <th class="p-2 w-10">#</th>
                    <th class="p-2">Domain</th>
                    <th class="p-2 w-16">Method</th>
                    <th class="p-2">Endpoint / Task</th>
                    <th class="p-2">Fungsi</th>
                    <th class="p-2 w-36">Status</th>
                    <th class="p-2 w-24">PIC</th>
                    <th class="p-2 w-64">Notes</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(it,idx) in items" :key="it.id">
                    <tr class="border-t hover:bg-slate-50" :class="rowClass(it.status)">
                        <td class="p-2 text-slate-400" x-text="idx+1"></td>
                        <td class="p-2">
                            <span class="text-xs px-2 py-0.5 rounded bg-slate-100" x-text="it.domain"></span>
                            <span x-show="it.priority" class="text-[10px] text-slate-500" x-text="it.priority"></span>
                        </td>
                        <td class="p-2 font-mono text-xs" x-text="it.method"></td>
                        <td class="p-2 font-mono text-xs break-all" x-text="it.endpoint"></td>
                        <td class="p-2 text-slate-600" x-text="it.function_id"></td>
                        <td class="p-2">
                            <select x-model="it.status" @change="updateField(it,'status',it.status)"
                                    class="text-xs border rounded px-1 py-1 w-full" :class="statusSelectClass(it.status)">
                                <option value="done">✅ Done</option>
                                <option value="in_progress">🔄 In Progress</option>
                                <option value="todo">⬜ Belum</option>
                                <option value="blocked">⛔ Blocked</option>
                            </select>
                        </td>
                        <td class="p-2">
                            <select x-model="it.pic" @change="updateField(it,'pic',it.pic)"
                                    class="text-xs border rounded px-1 py-1 w-full">
                                <option :value="null">—</option>
                                <option value="Darriel">Darriel</option>
                                <option value="Rasyid">Rasyid</option>
                            </select>
                        </td>
                        <td class="p-2">
                            <textarea rows="1" :value="it.notes || ''"
                                @blur="updateField(it,'notes',$event.target.value)"
                                placeholder="catatan…"
                                class="text-xs border rounded px-1 py-1 w-full resize-y"></textarea>
                        </td>
                    </tr>
                </template>
                <tr x-show="!items.length"><td colspan="8" class="p-6 text-center text-slate-400">Tidak ada item.</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Toast -->
    <div x-show="toast" x-transition class="fixed bottom-4 right-4 bg-slate-800 text-white text-sm px-4 py-2 rounded shadow-lg" x-text="toast"></div>
</div>

<script>
function tracker() {
    return {
        items: [], summary: {}, meta: {domains:[],pics:[],statuses:[],sources:[]},
        filters: {q:'', domain:'', status:'', pic:'', source:''},
        toast: '',
        saved: {}, // snapshot nilai tersimpan per id, untuk rollback
        csrf: document.querySelector('meta[name=csrf-token]').content,

        qs() { return new URLSearchParams(Object.entries(this.filters).filter(([,v])=>v)).toString(); },
        async load() {
            const r = await fetch(`{{ route('dev.tracking.data') }}?${this.qs()}`, {headers:{'Accept':'application/json'}});
            const d = await r.json();
            this.items = d.items; this.summary = d.summary; this.meta = d.meta;
            this.saved = {};
            for (const it of this.items) this.saved[it.id] = {status:it.status, pic:it.pic, notes:it.notes};
        },
        async updateField(it, field, value) {
            const prev = this.saved[it.id] ? this.saved[it.id][field] : it[field];
            if (value === prev) return;              // tidak ada perubahan
            it[field] = value;                       // optimistic (untuk notes)
            const r = await fetch(`{{ url('dev/tracking/items') }}/${it.id}`, {
                method:'PATCH',
                headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf,'Accept':'application/json'},
                body: JSON.stringify({[field]: value})
            });
            if (r.ok) {
                const d = await r.json();
                this.summary = d.summary;
                if (this.saved[it.id]) this.saved[it.id][field] = value; // update snapshot
                this.showToast(`✓ ${field} tersimpan`);
            } else {
                it[field] = prev; // rollback ke nilai tersimpan terakhir
                this.showToast('✗ gagal menyimpan');
            }
        },
        showToast(m){ this.toast=m; clearTimeout(this._t); this._t=setTimeout(()=>this.toast='',1800); },
        exportUrl(fmt){ return `{{ route('dev.tracking.export') }}?format=${fmt}&${this.qs()}`; },

        statusLabel(s){ return {done:'✅ Done',in_progress:'🔄 In Progress',todo:'⬜ Belum',blocked:'⛔ Blocked'}[s]||s; },
        pct(s){ return s.total ? Math.round(s.done/s.total*100) : 0; },
        bar(s,k){ return s.total ? (s[k]/s.total*100) : 0; },
        overallCards(){ const o=this.summary.overall||{}; return [
            {label:'Total',value:o.total,color:'text-slate-700'},
            {label:'✅ Done',value:o.done,color:'text-emerald-600'},
            {label:'🔄 In Progress',value:o.in_progress,color:'text-amber-600'},
            {label:'⬜ Belum',value:o.todo,color:'text-slate-500'},
            {label:'⛔ Blocked',value:o.blocked,color:'text-rose-600'},
        ]; },
        rowClass(s){ return {done:'bg-emerald-50/40',in_progress:'bg-amber-50/40',todo:'',blocked:'bg-rose-50/40'}[s]||''; },
        statusSelectClass(s){ return {done:'bg-emerald-100',in_progress:'bg-amber-100',todo:'bg-slate-100',blocked:'bg-rose-100'}[s]||''; },
    }
}
</script>
</body>
</html>
