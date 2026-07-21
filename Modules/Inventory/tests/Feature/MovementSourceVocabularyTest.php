<?php

namespace Modules\Inventory\Tests\Feature;

use Modules\Inventory\Support\InventoryMovementSourceMap;
use Tests\TestCase;

class MovementSourceVocabularyTest extends TestCase
{

    private const SCAN_DIRS = ['Modules', 'app'];

    private function productionSourceLiterals(): array
    {
        $root = base_path();
        $found = [];

        foreach (self::SCAN_DIRS as $dir) {
            $path = $root . DIRECTORY_SEPARATOR . $dir;
            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $real = $file->getRealPath();

                if (str_contains($real, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)
                    || str_contains($real, 'InventoryMovementSourceMap.php')) {
                    continue;
                }

                $code = file_get_contents($real);
                if (! str_contains($code, "'source'")) {
                    continue;
                }

                preg_match_all(
                    "/'source'\s*=>\s*'([A-Z][A-Z_]{2,})'/",
                    $code,
                    $matches
                );

                foreach ($matches[1] as $source) {
                    $found[$source] ??= [];
                    $found[$source][] = str_replace($root . DIRECTORY_SEPARATOR, '', $real);
                }
            }
        }

        return $found;
    }

    public function test_setiap_source_yang_ditulis_produksi_punya_label(): void
    {
        $written = $this->productionSourceLiterals();

        $this->assertNotEmpty(
            $written,
            'pemindai tidak menemukan satu pun literal source -- polanya kemungkinan sudah usang'
        );

        $yatim = [];
        foreach ($written as $source => $files) {
            if (! array_key_exists($source, InventoryMovementSourceMap::SOURCES)) {
                $yatim[$source] = array_unique($files);
            }
        }

        $this->assertSame(
            [],
            $yatim,
            "source berikut ditulis produksi tapi tidak ada di InventoryMovementSourceMap::SOURCES,\n"
            . "jadi akan tampil sebagai enum mentah dan hilang dari dropdown filter:\n"
            . json_encode($yatim, JSON_PRETTY_PRINT)
        );
    }

    public function test_setiap_kategori_terdaftar_di_category_order(): void
    {
        $reflection = new \ReflectionClass(InventoryMovementSourceMap::class);
        $order = $reflection->getConstant('CATEGORY_ORDER');

        $kategoriTanpaUrutan = [];
        foreach (InventoryMovementSourceMap::SOURCES as $source => $meta) {
            if (! in_array($meta['category'], $order, true)) {
                $kategoriTanpaUrutan[$meta['category']][] = $source;
            }
        }

        $this->assertSame(
            [],
            $kategoriTanpaUrutan,
            "kategori berikut dipakai di SOURCES tapi tidak ada di CATEGORY_ORDER,\n"
            . "sehingga filterOptions() tidak akan pernah merendernya:\n"
            . json_encode($kategoriTanpaUrutan, JSON_PRETTY_PRINT)
        );
    }

    public function test_partisi_alokasi_memuat_semua_source_on_order(): void
    {

        foreach (['ORDER_RESERVE', 'ORDER_RELEASE', 'RESERVE', 'RESERVE_CANCEL', 'RESERVE_EXPIRED'] as $source) {
            $this->assertContains(
                $source,
                InventoryMovementSourceMap::ALLOCATION_PARTITION_SOURCES,
                "{$source} menggerakkan on_order, jadi wajib ada di ALLOCATION_PARTITION_SOURCES"
            );
        }

        $this->assertSame(
            ['ORDER_RESERVE', 'ORDER_RELEASE'],
            InventoryMovementSourceMap::ORDER_LEDGER_SOURCES,
            'ORDER_LEDGER_SOURCES harus tetap khusus alokasi Sales Order'
        );
    }
}
