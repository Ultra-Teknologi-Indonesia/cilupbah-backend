<?php

namespace Modules\Report\Support;

/**
 * Representasi netral satu laporan gudang bersubtotal untuk diekspor ke Excel
 * dengan tata letak "cermin PDF": judul, periode, blok grup berheader kolom,
 * baris subtotal, dan Grand Total. Tiap service laporan memetakan datanya ke
 * struktur ini; SectionedReportExport yang menuliskannya ke sheet.
 *
 * Sel numerik (qty/jumlah) dikirim sebagai int/float mentah agar bisa di-SUM
 * di Excel; label & durasi dikirim sebagai string.
 */
final class SectionedReport
{
    public const TITLE = 'title';
    public const PERIODE = 'periode';
    public const SPACER = 'spacer';
    public const GROUP = 'group';
    public const SUBGROUP = 'subgroup';
    public const HEAD = 'head';
    public const DATA = 'data';
    public const SUBTOTAL = 'subtotal';
    public const GRAND = 'grand';
    public const EMPTY = 'empty';

    /** @var array<int, array{type: string, cells: array<int, mixed>}> */
    public array $rows = [];

    private int $columnCount = 1;

    private function __construct(
        public readonly string $title,
        public readonly string $periode,
    ) {
        $this->push(self::TITLE, [$title]);
        if ($periode !== '') {
            $this->push(self::PERIODE, [$periode]);
        }
        $this->push(self::SPACER, []);
    }

    public static function make(string $title, string $periode = ''): self
    {
        return new self($title, $periode);
    }

    public function group(string $label): self
    {
        return $this->push(self::GROUP, [$label]);
    }

    public function subgroup(string $label): self
    {
        return $this->push(self::SUBGROUP, [$label]);
    }

    public function head(array $cells): self
    {
        return $this->push(self::HEAD, array_values($cells));
    }

    public function row(array $cells): self
    {
        return $this->push(self::DATA, array_values($cells));
    }

    public function subtotal(array $cells): self
    {
        return $this->push(self::SUBTOTAL, array_values($cells));
    }

    public function grand(array $cells): self
    {
        return $this->push(self::GRAND, array_values($cells));
    }

    public function spacer(): self
    {
        return $this->push(self::SPACER, []);
    }

    public function emptyNotice(string $message): self
    {
        return $this->push(self::EMPTY, [$message]);
    }

    public function columnCount(): int
    {
        return $this->columnCount;
    }

    private function push(string $type, array $cells): self
    {
        $this->columnCount = max($this->columnCount, count($cells));
        $this->rows[] = ['type' => $type, 'cells' => $cells];

        return $this;
    }
}
