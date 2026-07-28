<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class TransferOutImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    private const HEADERS = [
        'ref_no', 'transaction_date', 'note', 'source_location',
        'destination_location', 'item_code', 'serial_no', 'batch_no',
        'qty_in_base', 'kode_rak',
    ];

    private function makeUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pengisian Data Transfer');

        foreach (self::HEADERS as $j => $h) {
            $sheet->setCellValue([$j + 1, 1], $h);
        }
        foreach ($rows as $i => $row) {
            foreach ($row as $j => $val) {
                $sheet->setCellValue([$j + 1, $i + 2], $val);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'trf') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'data.xlsx', null, null, true);
    }

    public function test_template_has_transfer_data_sheet_with_expected_headers(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/inventory/transfers/import/template');

        $response->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Pengisian Data Transfer');

        $this->assertNotNull($sheet);
        $this->assertSame('ref_no', $sheet->getCell('A1')->getValue());
        $this->assertSame('kode_rak', $sheet->getCell('J1')->getValue());
        $this->assertNotNull($spreadsheet->getSheetByName('Instruksi'));
        $this->assertNotNull($spreadsheet->getSheetByName('Tata Cara Pengisian'));
    }

    public function test_preview_groups_rows_by_ref_no_into_documents(): void
    {
        $upload = $this->makeUpload([
            ['[auto]', '29/11/2018 14:22', 'catatan', 'Pusat', 'Jakarta', 'BJ-0001', '', '', 2, 'L1-B1-K1-R1'],
            ['', '29/11/2018 14:22', 'catatan', 'Pusat', 'Jakarta', 'BJ-0002', '', '', 3, 'L1-B1-K1-R1'],
            ['TFO-00002', '29/11/2018 17:35', '', 'Bandung', 'Bekasi', 'BJ-0003', '', '', 1, 'B-1-2-3'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/transfers/import/preview', ['file' => $upload]);

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.total_rows', 3)
            ->assertJsonPath('data.summary.total_docs', 2);

        $this->assertSame(2, $response->json('data.transfers.0.item_count'));
        $this->assertSame('(otomatis)', $response->json('data.transfers.0.ref_no'));
        $this->assertSame('TFO-00002', $response->json('data.transfers.1.ref_no'));

        $this->assertSame(0, $response->json('data.summary.valid_docs'));
    }

    public function test_preview_flags_first_row_without_ref_no(): void
    {
        $upload = $this->makeUpload([
            ['', '29/11/2018 14:22', '', 'Pusat', 'Jakarta', 'BJ-0001', '', '', 2, 'L1-B1-K1-R1'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/transfers/import/preview', ['file' => $upload]);

        $response->assertStatus(200);
        $errors = collect($response->json('data.errors'))->pluck('error')->implode(' ');
        $this->assertStringContainsString('No Transfer', $errors);
    }
}
