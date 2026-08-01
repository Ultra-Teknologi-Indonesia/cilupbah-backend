<?php

namespace Modules\Warehouse\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class BinImportTemplateService
{
    public function build(string $locationName, array $examples): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $data = $spreadsheet->getActiveSheet();
        $data->setTitle(BinLayoutImporter::IMPORT_SHEET_NAME);
        $data->setCellValue('A1', 'kode_rak');
        $data->getStyle('A1')->getFont()->setBold(true);
        $data->getColumnDimension('A')->setWidth(30);
        $data->freezePane('A2');

        foreach ($examples as $i => $code) {
            $data->setCellValueExplicit(
                'A' . ($i + 2),
                $code,
                DataType::TYPE_STRING
            );
        }

        $instr = $spreadsheet->createSheet();
        $instr->setTitle('Instruksi');

        $instr->setCellValue('A1', 'Import Kode Rak' . ($locationName !== '' ? " — {$locationName}" : ''));
        $instr->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $lines = [
            '',
            '1. Isi kode rak di sheet "Pengisian Data", satu kode per baris, mulai baris 2.',
            '2. Jangan mengubah nama sheet maupun judul kolom "kode_rak".',
            '3. Kode rak berformat bebas. Yang dipakai sistem adalah teksnya persis.',
            '4. Kode yang sudah ada akan dilewati, bukan ditimpa. Aman dijalankan ulang.',
            '5. Baris kosong dan teks "Tidak ada rak" diabaikan.',
            '',
            'Contoh kode rak:',
            '  IN-A1-K1-P1',
            '  GK-14-K1-B1',
            '  O-LX-KX-KANTOR',
            '',
            $examples === []
                ? 'Lokasi ini belum punya rak, jadi sheet "Pengisian Data" masih kosong.'
                : 'Sheet "Pengisian Data" sudah berisi beberapa kode rak yang ADA di lokasi ini sebagai',
        ];

        if ($examples !== []) {
            $lines[] = 'contoh format. Boleh dihapus atau dibiarkan — kode yang sudah ada tidak akan diproses ulang.';
        }

        $lines[] = '';
        $lines[] = 'Zona dibuat otomatis dari segmen pertama kode rak (mis. "GK-14-K1-B1" → zona "GK").';

        foreach ($lines as $i => $line) {
            $instr->setCellValue('A' . ($i + 2), $line);
        }
        $instr->getColumnDimension('A')->setWidth(100);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}
