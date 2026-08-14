<?php

namespace Modules\Product\Imports;

use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Modules\Product\Services\ProductImportService;

abstract class BaseRowsImport implements OnEachRow, WithHeadingRow, WithChunkReading
{

    protected const MAX_ERROR_DETAILS = 500;

    protected int $total = 0;
    protected int $success = 0;
    protected int $failed = 0;

    protected array $errors = [];

    public function __construct(protected ProductImportService $service) {}

    public function onRow(Row $row): void
    {
        $raw = $row->toArray();

        if ($this->isEmptyRow($raw)) {
            return;
        }

        $data = array_map(function ($value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                return $trimmed === '' ? null : $trimmed;
            }
            return $value;
        }, $raw);

        $this->total++;
        $index = $row->getIndex();

        $validator = Validator::make($data, $this->rules());
        if ($validator->fails()) {
            $this->failed++;
            $this->recordError([
                'row_number' => $index,
                'attribute' => $validator->errors()->keys()[0] ?? null,
                'message' => $validator->errors()->first(),
                'row_snapshot' => $data,
            ]);

            return;
        }

        try {
            $this->process($data);
            $this->success++;
        } catch (\Throwable $e) {
            $this->failed++;
            $this->recordError([
                'row_number' => $index,
                'attribute' => null,
                'message' => $e->getMessage(),
                'row_snapshot' => $data,
            ]);
        }
    }

    protected function recordError(array $error): void
    {
        if (count($this->errors) < self::MAX_ERROR_DETAILS) {
            $this->errors[] = $error;
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function result(): array
    {
        return [
            'total' => $this->total,
            'success' => $this->success,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'errors_truncated' => max(0, $this->failed - count($this->errors)),
        ];
    }

    abstract protected function rules(): array;

    abstract protected function isEmptyRow(array $data): bool;

    abstract protected function process(array $data): void;
}
