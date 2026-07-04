<?php

namespace Modules\IssueTracker\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\IssueTracker\Models\Issue;
use Modules\IssueTracker\Models\IssueAttachment;
use Modules\IssueTracker\Models\IssueComment;
use Throwable;

class MigrateAttachmentsToMediaLibraryCommand extends Command
{
    protected $signature = 'issue-tracker:migrate-attachments
        {--dry-run : Tampilkan yang akan dimigrasi tanpa mengeksekusi}
        {--delete-source : Hapus file sumber setelah berhasil dimigrasi}';

    protected $description = 'Migrasikan attachment lama issue tracker ke Spatie Media Library (R2)';

    protected const SOURCE_DISKS = ['public', 's3'];

    public function handle(): int
    {
        $migrated = 0;
        $missing = 0;
        $failed = 0;

        foreach (IssueAttachment::query()->cursor() as $attachment) {
            $target = $attachment->comment_id
                ? IssueComment::find($attachment->comment_id)
                : Issue::find($attachment->issue_id);

            if (!$target) {
                $this->warn("Induk tidak ditemukan untuk attachment #{$attachment->id} ({$attachment->file_path})");
                $missing++;
                continue;
            }

            $sourceDisk = $this->findSourceDisk($attachment->file_path);

            if ($sourceDisk === null) {
                $this->warn("File sumber hilang: {$attachment->file_path} (attachment #{$attachment->id})");
                $missing++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Akan dimigrasi: {$attachment->file_path} [{$sourceDisk}] -> " . class_basename($target) . " {$target->getKey()}");
                $migrated++;
                continue;
            }

            try {
                $stream = Storage::disk($sourceDisk)->readStream($attachment->file_path);

                $media = $target
                    ->addMediaFromStream($stream)
                    ->usingFileName($attachment->file_name)
                    ->usingName(pathinfo($attachment->file_name, PATHINFO_FILENAME))
                    ->toMediaCollection(Issue::MEDIA_COLLECTION);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $media->created_at = $attachment->created_at;
                $media->save();

                if ($this->option('delete-source')) {
                    Storage::disk($sourceDisk)->delete($attachment->file_path);
                }

                $attachment->delete();
                $migrated++;
            } catch (Throwable $e) {
                $this->error("Gagal migrasi attachment #{$attachment->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $label = $this->option('dry-run') ? 'akan dimigrasi' : 'dimigrasi';
        $this->info("Selesai: {$migrated} {$label}, {$missing} hilang/tanpa induk, {$failed} gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function findSourceDisk(string $path): ?string
    {
        foreach (self::SOURCE_DISKS as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }
}
