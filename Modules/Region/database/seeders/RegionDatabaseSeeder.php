<?php

namespace Modules\Region\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RegionDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = base_path('database/data/wilayah_indonesia.sql');
        if (!File::exists($path)) {
            $this->command->error("File $path not found.");
            return;
        }

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        
        DB::table('villages')->delete();
        DB::table('districts')->delete();
        DB::table('cities')->delete();
        DB::table('provinces')->delete();

        $this->command->info("Parsing and inserting from $path...");

        $handle = fopen($path, 'r');
        if (!$handle) {
            $this->command->error("Failed to open file.");
            return;
        }

        $now = now()->toDateTimeString();
        $buffer = [
            'provinces' => [],
            'cities' => [],
            'districts' => [],
            'villages' => []
        ];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (str_starts_with($line, '(')) {
                $row = str_getcsv(trim($line, "(),;"), ',', "'", "\\");
                if (count($row) >= 4) {
                    $id = trim($row[0]);
                    $nama = trim($row[1]);
                    $lat = floatval(trim($row[2]));
                    $long = floatval(trim($row[3]));
                    
                    $len = strlen($id);
                    if ($len === 2) {
                        $buffer['provinces'][] = [
                            'id' => $id,
                            'nama' => $nama,
                            'latitude' => $lat,
                            'longitude' => $long,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    } elseif ($len === 4) {
                        $buffer['cities'][] = [
                            'id' => $id,
                            'province_id' => substr($id, 0, 2),
                            'nama' => $nama,
                            'latitude' => $lat,
                            'longitude' => $long,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    } elseif ($len === 6) {
                        $buffer['districts'][] = [
                            'id' => $id,
                            'city_id' => substr($id, 0, 4),
                            'nama' => $nama,
                            'latitude' => $lat,
                            'longitude' => $long,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    } elseif ($len === 10) {
                        $buffer['villages'][] = [
                            'id' => $id,
                            'district_id' => substr($id, 0, 6),
                            'nama' => $nama,
                            'latitude' => $lat,
                            'longitude' => $long,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    // We removed the eager batch inserts here to avoid foreign key errors.
                    // The SQL file is grouped alphabetically (kecamatan, kelurahan, kota, provinsi).
                    // Thus, we must parse the entire file first, then insert in the correct parent-to-child order.
                }
            }
        }
        fclose($handle);

        // Insert in correct order: Provinces -> Cities -> Districts -> Villages
        foreach (['provinces', 'cities', 'districts', 'villages'] as $table) {
            if (!empty($buffer[$table])) {
                $chunks = array_chunk($buffer[$table], 2000);
                foreach ($chunks as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        }

        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
        $this->command->info("Seeding completed successfully.");
    }
}
