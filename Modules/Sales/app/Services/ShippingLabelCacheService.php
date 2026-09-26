<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Jobs\ArchiveShippingLabelCacheJob;
use Modules\Sales\Repositories\ShippingLabelCacheRepository;
use RuntimeException;
use Throwable;

final class ShippingLabelCacheService
{
    public function __construct(private readonly ShippingLabelCacheRepository $repository) {}

    public function store(string $path, string $bytes): void
    {
        $this->validatePath($path);
        $size = strlen($bytes);
        if ($size === 0 || $size > (int) config('bulk-labels.cache_max_bytes', 32 * 1024 * 1024)) {
            throw new RuntimeException('Ukuran file label kosong atau melebihi batas aman.');
        }

        $spoolName = (string) config('bulk-labels.spool_disk', 'print_spool');
        $spool = Storage::disk($spoolName);
        $spool->makeDirectory(dirname($path));
        $absolute = $spool->path($path);
        $spool->makeDirectory('shipping-label-cache/locks');
        $lock = fopen($spool->path('shipping-label-cache/locks/'.substr(hash('sha256', $path), 0, 2).'.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('File label sedang disiapkan; coba kembali.');
        }
        $temporary = null;
        try {
            $existing = $this->repository->byPath($path);
            if ($existing !== null) {

                if ($existing->archived_at !== null || (is_file($absolute) && hash_file('sha256', $absolute) === $existing->sha256)) {
                    return;
                }
                if (! hash_equals($existing->sha256, hash('sha256', $bytes))) {
                    throw new RuntimeException('Isi file label pemulihan tidak cocok.');
                }
            }
            $free = disk_free_space(dirname($absolute));
            if ($free === false || $free < $size * 2 + (int) config('bulk-labels.cache_free_reserve_bytes', 512 * 1024 * 1024)) {
                throw new RuntimeException('Ruang penyimpanan sementara label hampir penuh. File belum dihapus atau dipotong.');
            }
            $temporary = tempnam(dirname($absolute), '.label-');
            if ($temporary === false || file_put_contents($temporary, $bytes) !== $size || ! rename($temporary, $absolute)) {
                throw new RuntimeException('File label tidak berhasil disimpan secara lengkap.');
            }
            $temporary = null;
            $artifact = $this->repository->stage([
                'path' => $path, 'spool_disk' => $spoolName,
                'archive_disk' => (string) config('bulk-labels.archive_disk', 'documents'),
                'sha256' => hash('sha256', $bytes), 'bytes' => $size, 'next_attempt_at' => now(),
            ]);
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        try {

            ArchiveShippingLabelCacheJob::dispatch($artifact->id)->afterCommit();
        } catch (Throwable $e) {
            Log::warning('Label archive dispatch deferred to durable recovery', ['artifact_id' => $artifact->id, 'exception' => $e::class]);
        }
    }

    public function read(string $path, ?string $expectedHash = null, bool $legacyFallback = true): ?string
    {
        $this->validatePath($path);
        $artifact = $this->repository->byPath($path);
        $hash = $expectedHash ?? $artifact?->sha256;
        $disks = [$artifact?->spool_disk ?? config('bulk-labels.spool_disk', 'print_spool')];

        if (($artifact === null && $legacyFallback) || $artifact?->archived_at !== null) {
            $disks[] = $artifact?->archive_disk ?? 'documents';
        }
        foreach (array_unique($disks) as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if (! $disk->exists($path) || $disk->size($path) > (int) config('bulk-labels.cache_max_bytes', 32 * 1024 * 1024)) {
                    continue;
                }
                $bytes = $disk->get($path);
                if (is_string($bytes) && $bytes !== '' && ($hash === null || hash_equals($hash, hash('sha256', $bytes)))) {
                    return $bytes;
                }
            } catch (Throwable $e) {
                Log::warning('Label cache read failed', ['path' => $path, 'exception' => $e::class]);
            }
        }

        return null;
    }

    public function archive(string $id): void
    {
        $lock = Cache::lock('label-cache-archive:'.$id, 300);
        if (! $lock->get()) {
            return;
        }
        try {
            $artifact = $this->repository->find($id);
            if ($artifact === null || $artifact->archived_at !== null) {
                return;
            }
            $this->validatePath($artifact->path);
            $localRoot = config('filesystems.disks.'.$artifact->spool_disk.'.root');
            $archiveRoot = config('filesystems.disks.'.$artifact->archive_disk.'.root');
            if ($artifact->spool_disk === $artifact->archive_disk
                || ($localRoot !== null && $archiveRoot !== null && realpath($localRoot) !== false && realpath($localRoot) === realpath($archiveRoot))) {
                throw new RuntimeException('Storage arsip harus berbeda dari print spool. File sumber dipertahankan.');
            }
            $local = Storage::disk($artifact->spool_disk);
            $remote = Storage::disk($artifact->archive_disk);
            $stream = $local->readStream($artifact->path);
            if (! is_resource($stream)) {
                throw new RuntimeException('File label sementara tidak tersedia; arsip belum selesai.');
            }
            try {
                if (! $remote->writeStream($artifact->path, $stream)) {
                    throw new RuntimeException('Upload arsip label gagal.');
                }
            } finally {
                fclose($stream);
            }
            $verify = $remote->readStream($artifact->path);
            if (! is_resource($verify)) {
                throw new RuntimeException('Arsip label tidak dapat diverifikasi.');
            }
            try {
                $hash = hash_init('sha256');
                $size = hash_update_stream($hash, $verify, $artifact->bytes + 1);
                if ($size !== $artifact->bytes || ! hash_equals($artifact->sha256, hash_final($hash))) {
                    throw new RuntimeException('Checksum arsip label tidak cocok. File sementara dipertahankan.');
                }
            } finally {
                fclose($verify);
            }
            $this->repository->update($artifact, ['archived_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);
        } catch (Throwable $e) {
            if (isset($artifact) && $artifact !== null) {
                $this->repository->update($artifact, ['attempts' => $artifact->attempts + 1,
                    'next_attempt_at' => now()->addMinutes(min(60, 2 ** min(6, $artifact->attempts))),
                    'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function reconcile(int $limit): array
    {
        $dispatched = $deleted = 0;
        foreach ($this->repository->due($limit) as $artifact) {
            try {
                $this->repository->update($artifact, ['next_attempt_at' => now()->addMinutes(15)]);
                ArchiveShippingLabelCacheJob::dispatch($artifact->id)->afterCommit();
                $dispatched++;
            } catch (Throwable $e) {
                Log::warning('Label archive recovery dispatch failed', ['artifact_id' => $artifact->id, 'exception' => $e::class]);
                break;
            }
        }
        foreach ($this->repository->expiredLocal($limit) as $artifact) {

            if ($artifact->spool_disk === $artifact->archive_disk) {
                continue;
            }
            $spool = Storage::disk($artifact->spool_disk);
            if ($spool->delete($artifact->path)) {
                $this->repository->update($artifact, ['local_deleted_at' => now()]);
                $deleted++;
            }
        }

        return compact('dispatched', 'deleted');
    }

    public function health(): array
    {
        return $this->repository->health();
    }

    private function validatePath(string $path): void
    {
        if (! preg_match('~^shipping-label-cache/[a-zA-Z0-9-]+/[a-f0-9]{64}(?:\.[a-zA-Z0-9_-]+)?\.pdf$~D', $path)) {
            throw new RuntimeException('Path cache label tidak valid.');
        }
    }
}
