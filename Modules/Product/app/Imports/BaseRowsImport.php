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
    public const MODE_PREVIEW = 'preview';
    public const MODE_EXECUTE = 'execute';

    protected const MAX_ERROR_DETAILS = 500;

    protected string $mode = self::MODE_EXECUTE;
    protected int $total = 0;
    protected int $success = 0;
    protected int $failed = 0;

    protected array $errors = [];
    protected array $stagedRows = [];

    public function __construct(protected ProductImportService $service) {}

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

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
            $msg = $validator->errors()->first();
            $this->failed++;
            $this->recordError([
                'row_number' => $index,
                'attribute' => $validator->errors()->keys()[0] ?? null,
                'message' => $msg,
                'row_snapshot' => $data,
            ]);

            if ($this->mode === self::MODE_PREVIEW) {
                $this->stagedRows[] = [
                    'row_number' => $index,
                    'sku' => $this->extractSku($data),
                    'name' => $this->extractName($data),
                    'category_name' => $this->extractCategory($data),
                    'sell_price' => $this->extractPrice($data),
                    'status' => 'invalid',
                    'message' => $msg,
                    'payload' => $data,
                ];
            }

            return;
        }

        if ($this->mode === self::MODE_PREVIEW) {
            try {
                $this->validate($data);
                $this->success++;
                $this->stagedRows[] = [
                    'row_number' => $index,
                    'sku' => $this->extractSku($data),
                    'name' => $this->extractName($data),
                    'category_name' => $this->extractCategory($data),
                    'sell_price' => $this->extractPrice($data),
                    'status' => 'valid',
                    'message' => null,
                    'payload' => $data,
                ];
            } catch (\Throwable $e) {
                $this->failed++;
                $this->recordError([
                    'row_number' => $index,
                    'attribute' => null,
                    'message' => $e->getMessage(),
                    'row_snapshot' => $data,
                ]);

                $this->stagedRows[] = [
                    'row_number' => $index,
                    'sku' => $this->extractSku($data),
                    'name' => $this->extractName($data),
                    'category_name' => $this->extractCategory($data),
                    'sell_price' => $this->extractPrice($data),
                    'status' => 'invalid',
                    'message' => $e->getMessage(),
                    'payload' => $data,
                ];
            }

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
            'staged_rows' => $this->stagedRows,
            'errors_truncated' => max(0, $this->failed - count($this->errors)),
        ];
    }

    abstract protected function rules(): array;

    abstract protected function isEmptyRow(array $data): bool;

    abstract protected function process(array $data): void;

    abstract protected function validate(array $data): void;

    abstract protected function extractSku(array $data): ?string;

    abstract protected function extractName(array $data): ?string;

    abstract protected function extractCategory(array $data): ?string;

    abstract protected function extractPrice(array $data): ?float;
}
