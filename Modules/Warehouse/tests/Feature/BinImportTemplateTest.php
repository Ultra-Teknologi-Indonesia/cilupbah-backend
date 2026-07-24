<?php

namespace Modules\Warehouse\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class BinImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    private function downloadTemplate(Location $loc): string
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/locations/{$loc->id}/bins/import/template");

        $response->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        return $path;
    }

    public function test_template_has_pengisian_data_as_the_active_first_sheet(): void
    {
        $loc = Location::factory()->create();

        $spreadsheet = IOFactory::load($this->downloadTemplate($loc));

        $this->assertSame('Pengisian Data', $spreadsheet->getSheet(0)->getTitle());
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());
        $this->assertSame('kode_rak', $spreadsheet->getSheet(0)->getCell('A1')->getValue());
        $this->assertNotNull($spreadsheet->getSheetByName('Instruksi'));
    }

    public function test_template_seeds_examples_from_existing_bins(): void
    {
        $loc = Location::factory()->create();
        LocationBin::factory()->create(['location_id' => $loc->id, 'bin_final_code' => 'GK-14-K1-B1']);
        LocationBin::factory()->create(['location_id' => $loc->id, 'bin_final_code' => 'GK-14-K1-B2']);

        $sheet = IOFactory::load($this->downloadTemplate($loc))->getSheetByName('Pengisian Data');

        $this->assertSame('GK-14-K1-B1', $sheet->getCell('A2')->getValue());
        $this->assertSame('GK-14-K1-B2', $sheet->getCell('A3')->getValue());
    }

    /**
     * Template yang diunduh lalu langsung di-upload kembali harus jadi no-op —
     * contohnya adalah kode rak yang sudah ada, bukan placeholder yang bikin rak sampah.
     */
    public function test_unmodified_template_round_trips_without_creating_bins(): void
    {
        $loc = Location::factory()->create();
        LocationBin::factory()->create(['location_id' => $loc->id, 'bin_final_code' => 'GK-14-K1-B1']);

        $path = $this->downloadTemplate($loc);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/locations/{$loc->id}/bins/import", [
                'file' => new UploadedFile($path, 'template.xlsx', null, null, true),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.existing', 1);

        $this->assertSame(1, LocationBin::where('location_id', $loc->id)->count());
    }

    /**
     * Sheet aktif ikut tersimpan saat user menyimpan file di Excel. Kalau ia menutup
     * template dengan "Instruksi" terpilih, importer tidak boleh membaca teks instruksi
     * sebagai kode rak.
     */
    public function test_import_reads_pengisian_data_even_when_another_sheet_is_active(): void
    {
        $loc = Location::factory()->create();
        LocationBin::factory()->create(['location_id' => $loc->id, 'bin_final_code' => 'GK-14-K1-B1']);

        $path = $this->downloadTemplate($loc);

        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getSheetByName('Pengisian Data')->setCellValue('A3', 'GK-99-K9-B9');
        $spreadsheet->setActiveSheetIndexByName('Instruksi');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/locations/{$loc->id}/bins/import", [
                'file' => new UploadedFile($path, 'template.xlsx', null, null, true),
            ]);

        $response->assertStatus(200)->assertJsonPath('data.created', 1);

        $this->assertTrue(
            LocationBin::where('location_id', $loc->id)
                ->where('bin_final_code', 'GK-99-K9-B9')
                ->exists()
        );
        $this->assertSame(2, LocationBin::where('location_id', $loc->id)->count());
    }
}
