<?php

namespace Modules\Product\Imports;

use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Modules\Product\Services\ProductImportService;

/**
 * Basis importer berbasis baris: streaming (chunk) + validasi per-baris.
 * Tidak ShouldQueue — dijalankan di dalam ProcessProductImportJob agar hasil
 * (total/success/failed/errors) bisa dibaca balik & batch difinalisasi.
 */
abstract class BaseRowsImport implements OnEachRow, WithHeadingRow, WithChunkReading
{
    protected int $total = 0;
    protected int $success = 0;
    protected int $failed = 0;

    /** @var array<int, array{row_number:int, attribute:?string, message:string, row_snapshot:array}> */
    protected array $errors = [];

    public function __construct(protected ProductImportService $service) {}

    public function onRow(Row $row): void
    {
        $data = $row->toArray();

        if ($this->isEmptyRow($data)) {
            return;
        }

        $this->total++;
        $index = $row->getIndex();

        $validator = Validator::make($data, $this->rules());
        if ($validator->fails()) {
            $this->failed++;
            $this->errors[] = [
                'row_number' => $index,
                'attribute' => $validator->errors()->keys()[0] ?? null,
                'message' => $validator->errors()->first(),
                'row_snapshot' => $data,
            ];

            return;
        }

        try {
            $this->process($data);
            $this->success++;
        } catch (\Throwable $e) {
            $this->failed++;
            $this->errors[] = [
                'row_number' => $index,
                'attribute' => null,
                'message' => $e->getMessage(),
                'row_snapshot' => $data,
            ];
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * @return array{total:int, success:int, failed:int, errors:array}
     */
    public function result(): array
    {
        return [
            'total' => $this->total,
            'success' => $this->success,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }

    /** @return array<string, mixed> aturan validasi per kolom */
    abstract protected function rules(): array;

    abstract protected function isEmptyRow(array $data): bool;

    abstract protected function process(array $data): void;
}
