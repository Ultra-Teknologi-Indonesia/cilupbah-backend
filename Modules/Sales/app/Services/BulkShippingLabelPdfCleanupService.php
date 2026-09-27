<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Repositories\BulkShippingLabelCleanupRepository;

final class BulkShippingLabelPdfCleanupService
{
    public function __construct(
        private readonly BulkShippingLabelCleanupRepository $repository,
    ) {}

    public function cleanup(): int
    {
        $disk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
        $count = 0;

        $this->repository->eachCompletedWithPdf(function (Collection $items) use ($disk, &$count): void {
            foreach ($items as $item) {
                foreach (array_filter([$item->ready_pdf_path, $item->raw_pdf_path]) as $path) {
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                }

                $this->repository->clearPdfPaths($item);
                $count++;
            }
        });

        return $count;
    }
}
