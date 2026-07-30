<?php

namespace Modules\Product\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class TemplateInstructionSheet implements FromArray, WithTitle
{
    public function __construct(private string $title, private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }
}
