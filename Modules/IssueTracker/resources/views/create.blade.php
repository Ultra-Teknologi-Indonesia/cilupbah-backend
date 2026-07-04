@extends('issue-tracker::layouts.app')

@section('title', 'Laporkan Issue')

@section('content')
<div class="mx-auto max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('issues.index') }}" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700">
            <svg class="mr-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Kembali
        </a>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="mb-1 text-xl font-bold text-gray-900">Laporkan Issue</h1>
        <p class="mb-6 text-sm text-gray-500">Isi form di bawah untuk melaporkan masalah. Semua kolom bertanda <span class="text-red-500">*</span> wajib diisi.</p>

        <form method="POST" action="{{ route('issues.store') }}" enctype="multipart/form-data" class="space-y-5"
              x-data="{ submitting: false }"
              @submit="submitting = true">
            @csrf

            {{-- Judul --}}
            <div>
                <label for="title" class="mb-1 block text-sm font-medium text-gray-700">Judul Issue <span class="text-red-500">*</span></label>
                <input type="text" name="title" id="title" value="{{ old('title') }}" required
                       class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                       placeholder="Contoh: Halaman checkout error saat klik bayar">
                @error('title') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            {{-- Deskripsi --}}
            <div>
                <label for="description" class="mb-1 block text-sm font-medium text-gray-700">Deskripsi <span class="text-red-500">*</span></label>
                <textarea name="description" id="description" rows="4" required
                          class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                          placeholder="Jelaskan masalah secara detail: langkah untuk reproduce, apa yang terjadi vs yang diharapkan">{{ old('description') }}</textarea>
                @error('description') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Platform --}}
                <div>
                    <label for="platform" class="mb-1 block text-sm font-medium text-gray-700">Platform <span class="text-red-500">*</span></label>
                    <select name="platform" id="platform" required
                            class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                        <option value="">Pilih platform</option>
                        <option value="website" {{ old('platform') === 'website' ? 'selected' : '' }}>Website</option>
                        <option value="mobile_app" {{ old('platform') === 'mobile_app' ? 'selected' : '' }}>Mobile App</option>
                    </select>
                    @error('platform') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                {{-- Kategori --}}
                <div>
                    <label for="category_id" class="mb-1 block text-sm font-medium text-gray-700">Kategori <span class="text-red-500">*</span></label>
                    <select name="category_id" id="category_id" required
                            class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                        <option value="">Pilih kategori</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Priority --}}
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Prioritas <span class="text-red-500">*</span></label>
                <div class="flex flex-wrap gap-2">
                    @foreach(['low' => ['Low', 'gray'], 'medium' => ['Medium', 'yellow'], 'high' => ['High', 'orange'], 'critical' => ['Critical', 'red']] as $val => [$label, $color])
                    <label class="cursor-pointer">
                        <input type="radio" name="priority" value="{{ $val }}" class="peer sr-only" {{ old('priority') === $val ? 'checked' : '' }} required>
                        <span class="inline-block rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 peer-checked:border-{{ $color }}-400 peer-checked:bg-{{ $color }}-50 peer-checked:text-{{ $color }}-700 hover:bg-gray-50">
                            {{ $label }}
                        </span>
                    </label>
                    @endforeach
                </div>
                @error('priority') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Nama --}}
                <div>
                    <label for="reporter_name" class="mb-1 block text-sm font-medium text-gray-700">Nama Anda <span class="text-red-500">*</span></label>
                    <input type="text" name="reporter_name" id="reporter_name" value="{{ old('reporter_name') }}" required
                           class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                    @error('reporter_name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                {{-- Kontak --}}
                <div>
                    <label for="reporter_contact" class="mb-1 block text-sm font-medium text-gray-700">Email / WhatsApp</label>
                    <input type="text" name="reporter_contact" id="reporter_contact" value="{{ old('reporter_contact') }}"
                           class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                           placeholder="Opsional, untuk follow-up">
                    @error('reporter_contact') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- URL --}}
            <div>
                <label for="url" class="mb-1 block text-sm font-medium text-gray-700">URL Halaman (opsional)</label>
                <input type="url" name="url" id="url" value="{{ old('url') }}"
                       class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                       placeholder="https://...">
                @error('url') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            {{-- Lampiran --}}
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Lampiran (maks 5 file, 10MB/file)</label>
                <div x-data="attachmentPicker()" class="space-y-2">
                    <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 px-4 py-6 text-center hover:border-brand-400 hover:bg-brand-50/30"
                           :class="files.length >= max ? 'opacity-50 cursor-not-allowed' : ''">
                        <div>
                            <svg class="mx-auto mb-1 h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                            <span class="text-sm text-gray-500" x-text="files.length >= max ? `Maksimal ${max} file` : 'Klik untuk upload gambar/video/pdf'"></span>
                            <span class="mt-0.5 block text-[11px] text-gray-400" x-text="`${files.length}/${max} file`"></span>
                        </div>
                        <input type="file" name="attachments[]" multiple x-ref="input"
                               accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf" class="sr-only"
                               :disabled="files.length >= max"
                               @change="addFiles($event)">
                    </label>

                    <p x-show="error" x-text="error" class="text-xs text-red-500"></p>

                    <template x-if="files.length > 0">
                        <ul class="space-y-1">
                            <template x-for="(f, idx) in files" :key="f._key">
                                <li class="flex items-center justify-between rounded bg-gray-50 px-3 py-1.5 text-xs text-gray-600">
                                    <span class="flex min-w-0 items-center">
                                        <svg class="mr-1.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                        <span class="truncate" x-text="f.name"></span>
                                        <span class="ml-2 shrink-0 text-[11px] text-gray-400" x-text="formatSize(f.size)"></span>
                                    </span>
                                    <button type="button" @click="removeFile(idx)" class="ml-2 shrink-0 text-red-500 hover:text-red-700" title="Hapus">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
                @error('attachments') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                @error('attachments.*') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="pt-2">
                <button type="submit" :disabled="submitting"
                        class="inline-flex w-full items-center justify-center rounded-lg bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-70">
                    <svg x-show="submitting" class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span x-text="submitting ? 'Mengirim laporan...' : 'Kirim Laporan Issue'"></span>
                </button>
                <p x-show="submitting" class="mt-2 text-center text-xs text-gray-500">Mohon tunggu, jangan tutup halaman. File sedang diupload.</p>
            </div>
        </form>
    </div>
</div>

<script>
    function attachmentPicker() {
        return {
            files: [],
            max: 5,
            maxSize: 10 * 1024 * 1024,
            error: '',
            addFiles(event) {
                this.error = '';
                const incoming = Array.from(event.target.files || []);
                for (const f of incoming) {
                    if (this.files.length >= this.max) {
                        this.error = `Maksimal ${this.max} file. Sisanya diabaikan.`;
                        break;
                    }
                    if (f.size > this.maxSize) {
                        this.error = `"${f.name}" melebihi 10MB, dilewati.`;
                        continue;
                    }
                    if (this.files.some(x => x.name === f.name && x.size === f.size)) {
                        continue;
                    }
                    f._key = `${f.name}-${f.size}-${f.lastModified}-${Math.random()}`;
                    this.files.push(f);
                }
                this.syncInput();
            },
            removeFile(idx) {
                this.files.splice(idx, 1);
                this.error = '';
                this.syncInput();
            },
            syncInput() {
                const dt = new DataTransfer();
                this.files.forEach(f => dt.items.add(f));
                this.$refs.input.files = dt.files;
            },
            formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1024 / 1024).toFixed(1) + ' MB';
            },
        };
    }
</script>
@endsection
